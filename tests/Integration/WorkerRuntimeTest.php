<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Integration;

use Multiapi\Arco\Binary\BinaryProvider;
use Multiapi\Arco\Binary\RuntimeManifest;
use Multiapi\Arco\Package\ConsolePackager;
use Multiapi\Arco\Package\PackageReader;
use Multiapi\Arco\Tests\Support\RuntimeApiEmulator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * A queue worker function of the packaged Laravel app (the console zip with ARCO_WORKER_QUEUE), run in
 * the provided.al2023 image the way Lambda runs it, fed SQS events by the Runtime API emulator. There
 * is no AWS: the queue host resolves to the container itself, so SQS calls are refused at once (the
 * best-effort visibility change of a released job must be tolerated).
 */
#[Group('integration')]
final class WorkerRuntimeTest extends TestCase
{
    private const EXAMPLE = __DIR__ . '/../../examples/laravel';
    private const IMAGE = 'public.ecr.aws/lambda/provided:al2023';
    private const QUEUE_URL = 'https://sqs.us-east-1.amazonaws.com/111111111111/arco-example-ci-emails';
    private ?string $container = null;
    private ?RuntimeApiEmulator $api = null;

    protected function setUp(): void
    {
        $docker = new Process(['docker', 'info']);
        $docker->run();
        if (! $docker->isSuccessful()) {
            self::markTestSkipped('Docker is not available.');
        }
    }

    protected function tearDown(): void
    {
        if ($this->container !== null) {
            (new Process(['docker', 'rm', '-f', $this->container]))->run();
        }
        $this->api?->stop();
    }

    public function test_a_batch_reports_the_failed_and_released_messages_and_laravel_boots_once(): void
    {
        $task = $this->task();
        $this->start($task);

        $first = $this->invoke([
            self::record('m-ok', 'ExampleSucceedsJob'),
            self::record('m-throws', 'ExampleThrowsJob'),
            self::record('m-released', 'ExampleReleasesJob'),
        ]);
        self::assertSame([['itemIdentifier' => 'm-throws'], ['itemIdentifier' => 'm-released']], $first['batchItemFailures'], $this->logs());

        $second = $this->invoke([self::record('m-ok-2', 'ExampleSucceedsJob')]);
        self::assertSame([], $second['batchItemFailures'], $this->logs());

        $logs = $this->logs();
        self::assertSame(1, substr_count($logs, '"arco":"worker_ready"'), 'the worker is prepared once');
        $name = preg_quote((string) json_encode('App\\Jobs\\ExampleSucceedsJob'), '/');
        preg_match_all('/"example_job":' . $name . ',"event":"ran","pid":(\d+)/', $logs, $pids);
        self::assertCount(2, $pids[1], $logs);
        self::assertSame($pids[1][0], $pids[1][1], 'both invocations ran in the same PHP process: Laravel booted once');
        self::assertStringContainsString('"arco":"job_release_failed"', $logs, 'a refused visibility change is logged, not fatal');
        self::assertSame(['released', 'released', 'ok'] /* a throwing job with tries left comes back, like under queue:work */, $this->statuses($logs, 'm-released', 'm-throws', 'm-ok'));
        self::assertNull($this->api?->initError());
    }

    /** The status of each job's `{"arco":"job"}` line, in the order of the ids asked. @return list<string> */
    private function statuses(string $logs, string ...$ids): array
    {
        $statuses = [];
        foreach ($ids as $id) {
            self::assertSame(1, preg_match('/\{"arco":"job"[^\n]*"messageId":"' . preg_quote($id, '/') . '"[^\n]*"status":"(\w+)"/', $logs, $m), "no job line for $id in $logs");
            $statuses[] = $m[1];
        }
        return $statuses;
    }

    /** @return array{batchItemFailures: list<array{itemIdentifier: string}>} */
    private function invoke(array $records): array
    {
        $api = $this->api ?? throw new \LogicException('No emulator.');
        $reply = $api->await($api->enqueue(['Records' => $records]), 90);
        self::assertSame('response', $reply['type'], $reply['body'] . $this->logs());
        /** @var array{batchItemFailures: list<array{itemIdentifier: string}>} */
        return json_decode($reply['body'], true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private static function record(string $id, string $job): array
    {
        $class = "App\\Jobs\\$job";
        $payload = [
            'uuid' => $id, 'displayName' => $class, 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'maxTries' => null, 'maxExceptions' => null, 'failOnTimeout' => false, 'backoff' => null, 'timeout' => null, 'retryUntil' => null,
            'data' => ['commandName' => $class, 'command' => sprintf('O:%d:"%s":0:{}', strlen($class), $class)],
        ];
        return [
            'messageId' => $id, 'receiptHandle' => "handle-$id", 'body' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'attributes' => ['ApproximateReceiveCount' => '1'], 'messageAttributes' => [],
            'eventSource' => 'aws:sqs', 'eventSourceARN' => 'arn:aws:sqs:us-east-1:111111111111:arco-example-ci-emails', 'awsRegion' => 'us-east-1',
        ];
    }

    /** The console zip of the example app (the real CLI packages it), unzipped like Lambda, with this checkout's arco-laravel. */
    private function task(): string
    {
        $build = RuntimeManifest::load()->resolve(null, null);
        $phpCli = BinaryProvider::default()->phpCli($build);
        self::assertNotNull($phpCli, "The default build {$build->id} has no PHP CLI.");
        $work = sys_get_temp_dir() . '/arco-worker-it-' . bin2hex(random_bytes(4));
        mkdir($work, 0777, true);
        $package = new Process([PHP_BINARY, __DIR__ . '/../../packages/arco/bin/arco', 'package', '--env=ci', "--output=$work/package.zip", '--no-interaction'], (string) realpath(self::EXAMPLE), null, null, 900);
        $package->run();
        self::assertTrue($package->isSuccessful(), $package->getOutput() . $package->getErrorOutput());
        $console = ConsolePackager::fromWeb(PackageReader::open("$work/package.zip"), "$work/console", $phpCli);

        $task = "$work/task";
        $zip = new ZipArchive();
        self::assertTrue($zip->open($console->package->path));
        $zip->extractTo($task);
        $stat = $zip->statName('bin/php');
        self::assertNotFalse($stat);
        // ZipArchive drops the mode; Lambda keeps the zip's Unix mode.
        self::assertTrue($zip->getExternalAttributesIndex($stat['index'], $os, $attributes));
        chmod("$task/bin/php", ($attributes >> 16) & 0777);
        $zip->close();
        self::assertFileExists("$task/runtime/worker.php");
        (new Filesystem())->mirror(__DIR__ . '/../../packages/arco-laravel/src', "$task/app/vendor/multiapi/arco-laravel/src", null, ['override' => true]);
        return $task;
    }

    private function start(string $task): void
    {
        $this->api = new RuntimeApiEmulator('0.0.0.0');
        $this->container = 'arco-worker-it-' . bin2hex(random_bytes(3));
        $env = [
            'AWS_LAMBDA_RUNTIME_API' => "host.docker.internal:{$this->api->port}", 'LAMBDA_TASK_ROOT' => '/var/task', 'AWS_REGION' => 'us-east-1',
            'AWS_DEFAULT_REGION' => 'us-east-1', 'AWS_ACCESS_KEY_ID' => 'AKIDTEST', 'AWS_SECRET_ACCESS_KEY' => 'secret', 'AWS_SESSION_TOKEN' => 'token',
            'ARCO' => '1', 'ARCO_WORKER_QUEUE' => 'emails', 'ARCO_QUEUE_TRIES' => '3',
            'ARCO_QUEUES' => json_encode(['emails' => self::QUEUE_URL], JSON_UNESCAPED_SLASHES),
            'QUEUE_CONNECTION' => 'sqs', 'SQS_PREFIX' => 'https://sqs.us-east-1.amazonaws.com/111111111111', 'SQS_QUEUE' => self::QUEUE_URL,
            'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:' . base64_encode(str_repeat('a', 32)),
        ];
        $command = ['docker', 'run', '-d', '--name', $this->container, '--platform', 'linux/arm64',
            '--read-only', '--tmpfs', '/tmp:size=1024m,exec', '--cpus', '2', '--memory', '2g',
            '--add-host', 'host.docker.internal:host-gateway',
            // No real AWS: the SQS host is the container itself, which refuses the connection.
            '--add-host', 'sqs.us-east-1.amazonaws.com:127.0.0.1',
            '-v', "$task:/var/task:ro", '-w', '/var/task'];
        foreach ($env as $name => $value) {
            array_push($command, '-e', "$name=$value");
        }
        array_push($command, '--entrypoint', 'sh', self::IMAGE, '/var/task/bootstrap');
        $run = new Process($command);
        $run->run();
        self::assertTrue($run->isSuccessful(), $run->getErrorOutput());
    }

    private function logs(): string
    {
        $logs = new Process(['docker', 'logs', (string) $this->container]);
        $logs->run();
        return $logs->getOutput() . $logs->getErrorOutput();
    }
}
