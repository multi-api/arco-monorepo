<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\ConsoleRuntime;

use Multiapi\Arco\Tests\Support\RuntimeApiEmulator;
use Multiapi\Arco\Tests\Support\RuntimeApiTrace;
use Multiapi\Arco\Tests\Support\TelemetryCollector;
use Multiapi\Arco\Tests\Support\TempPaths;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Runs runtime/console.php with the local PHP against the Runtime API emulator. Each test starts its
 * own runtime, so a runtime parked on /next never blocks another test's request.
 */
final class ConsoleLoopTest extends TestCase
{
    private RuntimeApiEmulator $api;
    private ?Process $runtime = null;
    private string $root;

    protected function setUp(): void
    {
        $this->root = TempPaths::path('arco-task-');
        $fs = new Filesystem();
        $fs->mirror(__DIR__ . '/../../../packages/arco/runtime/console', "$this->root/runtime/console");
        $fs->copy(__DIR__ . '/../../../packages/arco/runtime/console.php', "$this->root/runtime/console.php");
        $fs->copy(__DIR__ . '/../../../packages/arco/runtime/console-cli.php', "$this->root/runtime/console-cli.php");
        $fs->dumpFile("$this->root/arco-runtime.json", '{"arcoVersion":"0.21.0","phpIni":{"memory_limit":"321M"},"console":{"entry":"artisan"}}');
        $fs->dumpFile("$this->root/app/artisan", <<<'PHP'
            <?php
            echo 'args:', json_encode(array_slice($argv, 1)), "\n", 'server-args:', json_encode(array_slice($_SERVER['argv'], 1)), "\n", 'greeting:', getenv('GREETING'), "\n", 'cwd:', getcwd(), "\n", 'ini:', getenv('PHP_INI_SCAN_DIR'), "\n", 'request:', getenv('ARCO_REQUEST_ID'), "\n", 'trace:', getenv('_X_AMZN_TRACE_ID'), "\n";
            fwrite(STDERR, "to-stderr\n");
            if (($argv[1] ?? '') === 'arco:tinker') { echo "ARCO-TINKER:scope-with-a-secret\n"; }
            exit(in_array('--fail', $argv, true) ? 3 : 0);
            PHP);

        $this->api = new RuntimeApiEmulator();
    }

    protected function tearDown(): void
    {
        $this->api->stop();
        $this->runtime?->stop(1);
    }

    /** @param array<string, string|false> $env */
    private function runtime(array $env): Process
    {
        return new Process([PHP_BINARY, "$this->root/runtime/console.php"], "$this->root/app", [
            'AWS_LAMBDA_RUNTIME_API' => "127.0.0.1:{$this->api->port}",
            'LAMBDA_TASK_ROOT' => $this->root,
            'ARCO_TMP' => "$this->root/tmp",
            'AWS_REGION' => 'us-east-1',
            // Never the real collector; the telemetry tests point it at their own socket.
            'ARCO_TELEMETRY_HOST' => '127.0.0.1:9',
            // Never inherit real credentials from the developer's shell.
            'AWS_ACCESS_KEY_ID' => false,
            'AWS_SECRET_ACCESS_KEY' => false,
            'AWS_SESSION_TOKEN' => false,
            ...$env,
        ]);
    }

    /** @return array{exitCode: int, output: string} */
    private function respond(Process $runtime, string $id): array
    {
        $reply = $this->api->await($id);
        self::assertSame('response', $reply['type'], $reply['body'] . $runtime->getOutput() . $runtime->getErrorOutput());
        /** @var array{exitCode: int, output: string} */
        return json_decode($reply['body'], true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_runs_commands_one_invocation_at_a_time(): void
    {
        $runtime = $this->runtime = $this->runtime(['ARCO_PHP_BINARY' => PHP_BINARY, 'GREETING' => 'hola']);
        $runtime->start();

        $ok = $this->respond($runtime, $okId = $this->api->enqueue(['command' => "greet --name='A B'"]));
        $failed = $this->respond($runtime, $failedId = $this->api->enqueue(['command' => 'greet --fail']));
        $invalid = $this->api->await($this->api->enqueueRaw('not json'));

        self::assertSame(0, $ok['exitCode']);
        self::assertStringContainsString('args:["greet","--name=A B"]', $ok['output']);
        self::assertStringContainsString('greeting:hola', $ok['output']);
        // phpIni() applies to commands through an .ini file in the child's scan dir (FrankenPHP: integration test).
        self::assertStringContainsString("ini::$this->root/tmp/php\n", $ok['output']);
        self::assertSame(['session.upload_progress.enabled' => '0', 'fatal_error_backtraces' => '0', 'memory_limit' => '321M'], parse_ini_file("$this->root/tmp/php/99-arco.ini"));
        self::assertStringContainsString('to-stderr', $ok['output']);
        self::assertStringContainsString('cwd:' . realpath("$this->root/app"), $ok['output']);
        // Each command's Laravel shares them in its log context (ArcoServiceProvider), for one invocation's lines.
        self::assertStringContainsString("request:$okId\ntrace:" . RuntimeApiTrace::header($okId) . "\n", $ok['output']);
        self::assertStringContainsString("request:$failedId\ntrace:" . RuntimeApiTrace::header($failedId) . "\n", $failed['output']);
        self::assertSame(3, $failed['exitCode']);
        self::assertSame('error', $invalid['type']);
        self::assertSame('Arco.InvalidEvent', $invalid['errorType']);
        self::assertDirectoryExists("$this->root/tmp/storage/framework/views");

        $log = $runtime->getOutput();
        self::assertStringContainsString('{"arco":"console_ready"', $log);
        self::assertStringContainsString('{"arco":"command_failed"', $log);
        self::assertStringContainsString('to-stderr', $log, 'command output is echoed to the function log');
    }

    public function test_tinker_replies_stay_out_of_the_function_log(): void
    {
        $runtime = $this->runtime = $this->runtime(['ARCO_PHP_BINARY' => PHP_BINARY]);
        $runtime->start();

        $reply = $this->respond($runtime, $this->api->enqueue(['tinker' => ['code' => base64_encode('$x = 1;'), 'context' => null]]));
        $command = $this->respond($runtime, $this->api->enqueue(['command' => 'about']));

        self::assertStringContainsString('ARCO-TINKER:scope-with-a-secret', $reply['output']);
        self::assertSame(0, $command['exitCode']);
        $log = $runtime->getOutput();
        self::assertStringContainsString('{"arco":"tinker","code":"$x = 1;"', $log);
        self::assertStringContainsString('{"arco":"tinker_done","exitCode":0', $log);
        self::assertStringNotContainsString('scope-with-a-secret', $log);
        self::assertStringContainsString('args:["about"]', $log, 'command output is still echoed');
    }

    public function test_scripts_get_php_argv_under_frankenphp_php_cli(): void
    {
        // FrankenPHP's php-cli (v1.13) passes [<binary>, <script>, ...args] where PHP passes [<script>, ...args].
        $fake = "$this->root/bin/frankenphp";
        $fs = new Filesystem();
        $fs->dumpFile("$fake.php", <<<'PHP'
            <?php
            [, $binary, , $script] = $argv; // fake.php, frankenphp, php-cli, script
            $argv = [$binary, ...array_slice($argv, 3)];
            $_SERVER['argv'] = $argv;
            $_SERVER['argc'] = $argc = count($argv);
            require $script;
            PHP);
        $fs->dumpFile($fake, sprintf("#!/bin/sh\nexec %s %s \"\$0\" \"\$@\"\n", escapeshellarg(PHP_BINARY), escapeshellarg("$fake.php")));
        chmod($fake, 0755);
        $runtime = $this->runtime = $this->runtime(['ARCO_PHP_BINARY' => $fake]);
        $runtime->start();

        $ok = $this->respond($runtime, $this->api->enqueue(['command' => "greet --name='A B'"]));

        self::assertSame(0, $ok['exitCode'], $ok['output']);
        self::assertStringContainsString('args:["greet","--name=A B"]', $ok['output']);
        self::assertStringContainsString('server-args:["greet","--name=A B"]', $ok['output']);
    }

    public function test_a_missing_secret_is_an_init_error(): void
    {
        // A reference without credentials fails before any network call.
        $runtime = $this->runtime(['APP_KEY' => 'arco-ssm:/shop/production/app-key']);
        $runtime->setTimeout(30);
        $runtime->run();

        self::assertSame(1, $runtime->getExitCode());
        self::assertSame('Arco.InitError', $this->api->initError()['type'] ?? null);
        self::assertStringContainsString('{"arco":"fatal"', $runtime->getOutput());
    }
    /** @return array<string, array{string, string}> */
    public static function telemetryKinds(): array
    {
        return [
            'the scheduler rule' => ['{"command":"schedule:run"}', 'schedule'],
            'any other command' => ['{"command":"schedule:list"}', 'console'],
            'tinker' => ['{"tinker":{"code":"JHggPSAxOw==","context":null}}', 'console'],
            'an invalid event' => ['not json', 'console'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('telemetryKinds')]
    public function test_telemetry_counts_each_invocation_after_its_response(string $event, string $kind): void
    {
        $collector = new TelemetryCollector();
        try {
            $runtime = $this->runtime = $this->runtime(['ARCO_PHP_BINARY' => PHP_BINARY, 'ARCO_TELEMETRY_HOST' => $collector->host]);
            $runtime->start();

            $this->api->await($this->api->enqueueRaw($event));
            $this->api->await($this->api->enqueue(['command' => 'about']));

            $packet = $collector->receive();
            self::assertMatchesRegularExpression("/^arco1 r=laravel k=$kind v=0\\.21\\.0 g=us-east-1 i=1 c=1 b=[0-3]$/", (string) $packet, $runtime->getOutput() . $runtime->getErrorOutput());
            self::assertNull($collector->receive(0.5), 'the second invocation waits for the minute');
        } finally {
            $collector->close();
        }
    }

    public function test_arco_telemetry_disable_sends_nothing(): void
    {
        $collector = new TelemetryCollector();
        try {
            $runtime = $this->runtime = $this->runtime(['ARCO_PHP_BINARY' => PHP_BINARY, 'ARCO_TELEMETRY_HOST' => $collector->host, 'ARCO_TELEMETRY_DISABLE' => '1']);
            $runtime->start();

            $this->respond($runtime, $this->api->enqueue(['command' => 'about']));

            self::assertNull($collector->receive(1));
        } finally {
            $collector->close();
        }
    }
}
