<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Laravel;

use Illuminate\Console\Application as Artisan;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Multiapi\Arco\Laravel\ArcoServiceProvider;
use Multiapi\Arco\Tests\Support\RuntimeApiEmulator;
use Multiapi\Arco\Tests\Support\RuntimeApiTrace;
use Multiapi\Arco\Tests\Support\TelemetryCollector;
use Multiapi\Arco\Tests\Support\TempPaths;
use Multiapi\Arco\Tests\Unit\Laravel\Fixtures\QueueTestApp;
use Multiapi\Arco\Tests\Unit\Laravel\Fixtures\QueueTestJob;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/** Runs `arco:work` in its own process against the Runtime API emulator. */
final class WorkCommandTest extends TestCase
{
    private ?RuntimeApiEmulator $api = null;
    private ?Process $worker = null;

    protected function tearDown(): void
    {
        $this->api?->stop();
        $this->worker?->stop(1);
        unset($_SERVER['ARCO']);
        Artisan::forgetBootstrappers();
    }

    public function test_the_provider_registers_a_hidden_arco_work(): void
    {
        $_SERVER['ARCO'] = '1';
        $app = new Application(sys_get_temp_dir());
        (new ArcoServiceProvider($app))->boot();

        $artisan = new Artisan($app, new Dispatcher($app), 'test');
        self::assertTrue($artisan->has('arco:work'));
        self::assertTrue($artisan->get('arco:work')->isHidden());
    }

    /**
     * Starts the emulator (each invocation gets $timeoutMs) and `arco:work`; returns the boots file.
     *
     * @param array<string, string> $env
     */
    private function start(int $timeoutMs = 120_000, ?string $contextFile = null, array $env = []): string
    {
        // The emulator's server inherits $_ENV when it starts.
        $_ENV['ARCO_EMULATOR_TIMEOUT_MS'] = (string) $timeoutMs;
        try {
            $this->api = new RuntimeApiEmulator();
        } finally {
            unset($_ENV['ARCO_EMULATOR_TIMEOUT_MS']);
        }
        $boots = TempPaths::path('arco-boots-', '.txt');
        $this->worker = new Process([PHP_BINARY, __DIR__ . '/Fixtures/work-app.php'], null, [
            ...QueueTestApp::env(),
            'AWS_LAMBDA_RUNTIME_API' => "127.0.0.1:{$this->api->port}",
            'ARCO_TEST_BOOTS' => $boots,
            'ARCO_TEST_CONTEXT' => $contextFile ?? '',
            // Never inherit real credentials from the developer's shell.
            'AWS_ACCESS_KEY_ID' => false,
            'AWS_SECRET_ACCESS_KEY' => false,
            'AWS_SESSION_TOKEN' => false,
            // No function package (the CLI's Telemetry.php) unless a test gives one.
            'LAMBDA_TASK_ROOT' => TempPaths::path('arco-task-'),
            ...$env,
        ]);
        $this->worker->setTimeout(60);
        $this->worker->start();
        return $boots;
    }

    public function test_the_loop_serves_invocations_with_laravel_booted_once(): void
    {
        $boots = $this->start();
        self::assertNotNull($this->api);
        self::assertNotNull($this->worker);

        $first = $this->api->await($this->api->enqueue(['Records' => [QueueTestJob::record('ok')]]));
        $second = $this->api->await($this->api->enqueue(['Records' => [QueueTestJob::record('ok', id: 'a'), QueueTestJob::record('throw')]]));
        $broken = $this->api->await($this->api->enqueueRaw('not json'));

        $output = $this->worker->getOutput() . $this->worker->getErrorOutput();
        self::assertSame('response', $first['type'], $first['body'] . $output);
        self::assertSame(['batchItemFailures' => []], json_decode($first['body'], true));
        self::assertSame('response', $second['type'], $second['body'] . $output);
        self::assertSame(['batchItemFailures' => [['itemIdentifier' => 'm-throw']]], json_decode($second['body'], true));
        self::assertSame('error', $broken['type'], $output);
        self::assertSame("boot\n", file_get_contents($boots), 'Laravel boots once for all invocations');
    }

    public function test_jobs_log_with_the_invocation_id_and_trace_from_the_runtime_api(): void
    {
        $contextFile = TempPaths::path('arco-context-', '.jsonl');
        $this->start(contextFile: $contextFile);
        self::assertNotNull($this->api);
        self::assertNotNull($this->worker);

        $id = $this->api->enqueue(['Records' => [QueueTestJob::record('context')]]);
        $reply = $this->api->await($id);

        self::assertSame('response', $reply['type'], $this->worker->getErrorOutput());
        self::assertSame(
            ['requestId' => $id, 'traceId' => RuntimeApiTrace::root($id), 'job' => QueueTestJob::class],
            json_decode(trim((string) file_get_contents($contextFile)), true),
        );
    }

    public function test_after_a_timeout_the_answer_is_posted_and_the_process_restarts(): void
    {
        // 6 s per invocation: the slow job gets max(1, 5 - 3) = 2 s; then under 4 s are left, so the
        // next record is not started.
        $this->start(6_000);
        self::assertNotNull($this->api);
        self::assertNotNull($this->worker);

        $reply = $this->api->await($this->api->enqueue(['Records' => [QueueTestJob::record('slow'), QueueTestJob::record('ok')]]), 10);

        self::assertSame('response', $reply['type'], $this->worker->getErrorOutput());
        self::assertSame(['batchItemFailures' => [['itemIdentifier' => 'm-slow'], ['itemIdentifier' => 'm-ok']]], json_decode($reply['body'], true));
        $this->worker->wait();
        self::assertNotSame(0, $this->worker->getExitCode(), 'the next invocation boots a fresh process');
    }

    public function test_a_job_that_swallows_its_timeout_still_gets_the_batch_answered_before_the_deadline(): void
    {
        $this->start(6_000);
        self::assertNotNull($this->api);
        self::assertNotNull($this->worker);
        $started = microtime(true);

        $reply = $this->api->await($this->api->enqueue(['Records' => [QueueTestJob::record('stubborn'), QueueTestJob::record('ok')]]), 10);

        self::assertLessThan(6, microtime(true) - $started, 'answered before the Lambda deadline');
        self::assertSame('response', $reply['type'], $this->worker->getErrorOutput());
        self::assertSame(['batchItemFailures' => [['itemIdentifier' => 'm-stubborn'], ['itemIdentifier' => 'm-ok']]], json_decode($reply['body'], true));
        $this->worker->wait();
        self::assertNotSame(0, $this->worker->getExitCode());
    }
    /** A function package root with arco-runtime.json and, unless $withTelemetry is false, the CLI's Telemetry.php. */
    private function taskRoot(bool $withTelemetry): string
    {
        $root = TempPaths::path('arco-task-');
        $fs = new Filesystem();
        $fs->dumpFile("$root/arco-runtime.json", '{"arcoVersion":"0.21.0","console":{"entry":"artisan"}}');
        if ($withTelemetry) {
            $fs->copy(__DIR__ . '/../../../packages/arco/runtime/console/Telemetry.php', "$root/runtime/console/Telemetry.php");
        }
        return $root;
    }

    public function test_each_invocation_counts_one_queue_invocation(): void
    {
        $collector = new TelemetryCollector();
        try {
            $this->start(env: ['LAMBDA_TASK_ROOT' => $this->taskRoot(true), 'AWS_REGION' => 'us-east-1', 'ARCO_TELEMETRY_HOST' => $collector->host]);
            self::assertNotNull($this->api);
            self::assertNotNull($this->worker);

            // Two records, one invocation: one count.
            $reply = $this->api->await($this->api->enqueue(['Records' => [QueueTestJob::record('ok'), QueueTestJob::record('ok', id: 'a')]]));
            $this->api->await($this->api->enqueueRaw('not json'));

            self::assertSame('response', $reply['type'], $this->worker->getErrorOutput());
            self::assertMatchesRegularExpression('/^arco1 r=laravel k=queue v=0\\.21\\.0 g=us-east-1 i=1 c=1 b=[0-3]$/', (string) $collector->receive(), $this->worker->getErrorOutput());
            self::assertNull($collector->receive(0.5), 'the second invocation waits for the minute');
        } finally {
            $collector->close();
        }
    }

    public function test_the_boot_bucket_counts_from_the_process_start(): void
    {
        $collector = new TelemetryCollector();
        try {
            // 1.2 s before Laravel boots: bucket 2 (1 to 3 s), which a clock started in handle() would miss.
            $this->start(env: ['LAMBDA_TASK_ROOT' => $this->taskRoot(true), 'AWS_REGION' => 'us-east-1', 'ARCO_TELEMETRY_HOST' => $collector->host, 'ARCO_TEST_BOOT_DELAY_MS' => '1200']);
            self::assertNotNull($this->api);
            self::assertNotNull($this->worker);

            $reply = $this->api->await($this->api->enqueue(['Records' => [QueueTestJob::record('ok')]]));

            self::assertSame('response', $reply['type'], $this->worker->getErrorOutput());
            self::assertSame('arco1 r=laravel k=queue v=0.21.0 g=us-east-1 i=1 c=1 b=2', $collector->receive());
        } finally {
            $collector->close();
        }
    }

    public function test_a_deadline_exit_flushes_its_count(): void
    {
        $collector = new TelemetryCollector();
        try {
            $this->start(6_000, env: ['LAMBDA_TASK_ROOT' => $this->taskRoot(true), 'AWS_REGION' => 'us-east-1', 'ARCO_TELEMETRY_HOST' => $collector->host]);
            self::assertNotNull($this->api);
            self::assertNotNull($this->worker);

            $this->api->await($this->api->enqueue(['Records' => [QueueTestJob::record('ok')]]));
            self::assertNotNull($collector->receive(), 'the cold start');
            $reply = $this->api->await($this->api->enqueue(['Records' => [QueueTestJob::record('stubborn')]]), 10);

            self::assertSame('response', $reply['type'], $this->worker->getErrorOutput());
            self::assertSame('arco1 r=laravel k=queue v=0.21.0 g=us-east-1 i=1 c=0', $collector->receive());
        } finally {
            $collector->close();
        }
    }

    /** @return array<string, array{bool, array<string, string>}> */
    public static function silentTelemetry(): array
    {
        return [
            'a CLI without Telemetry.php' => [false, []],
            'ARCO_TELEMETRY_DISABLE=1' => [true, ['ARCO_TELEMETRY_DISABLE' => '1']],
        ];
    }

    /** @param array<string, string> $env */
    #[\PHPUnit\Framework\Attributes\DataProvider('silentTelemetry')]
    public function test_without_telemetry_the_loop_runs_and_sends_nothing(bool $withTelemetry, array $env): void
    {
        $collector = new TelemetryCollector();
        try {
            $this->start(env: ['LAMBDA_TASK_ROOT' => $this->taskRoot($withTelemetry), 'AWS_REGION' => 'us-east-1', 'ARCO_TELEMETRY_HOST' => $collector->host, ...$env]);
            self::assertNotNull($this->api);
            self::assertNotNull($this->worker);

            $reply = $this->api->await($this->api->enqueue(['Records' => [QueueTestJob::record('ok')]]));

            self::assertSame('response', $reply['type'], $this->worker->getErrorOutput());
            self::assertNull($collector->receive(1));
        } finally {
            $collector->close();
        }
    }
}
