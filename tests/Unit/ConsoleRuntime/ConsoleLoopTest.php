<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\ConsoleRuntime;

use Multiapi\Arco\Tests\Support\RuntimeApiEmulator;
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
        $this->root = sys_get_temp_dir() . '/arco-task-' . bin2hex(random_bytes(4));
        $fs = new Filesystem();
        $fs->mirror(__DIR__ . '/../../../packages/arco/runtime/console', "$this->root/runtime/console");
        $fs->copy(__DIR__ . '/../../../packages/arco/runtime/console.php', "$this->root/runtime/console.php");
        $fs->dumpFile("$this->root/arco-runtime.json", '{"console":{"entry":"artisan"}}');
        $fs->dumpFile("$this->root/app/artisan", <<<'PHP'
            <?php
            echo 'args:', json_encode(array_slice($argv, 1)), "\n", 'greeting:', getenv('GREETING'), "\n", 'cwd:', getcwd(), "\n";
            fwrite(STDERR, "to-stderr\n");
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

        $ok = $this->respond($runtime, $this->api->enqueue(['command' => "greet --name='A B'"]));
        $failed = $this->respond($runtime, $this->api->enqueue(['command' => 'greet --fail']));
        $invalid = $this->api->await($this->api->enqueueRaw('not json'));

        self::assertSame(0, $ok['exitCode']);
        self::assertStringContainsString('args:["greet","--name=A B"]', $ok['output']);
        self::assertStringContainsString('greeting:hola', $ok['output']);
        self::assertStringContainsString('to-stderr', $ok['output']);
        self::assertStringContainsString('cwd:' . realpath("$this->root/app"), $ok['output']);
        self::assertSame(3, $failed['exitCode']);
        self::assertSame('error', $invalid['type']);
        self::assertSame('Arco.InvalidEvent', $invalid['errorType']);
        self::assertDirectoryExists("$this->root/tmp/storage/framework/views");

        $log = $runtime->getOutput();
        self::assertStringContainsString('{"arco":"console_ready"', $log);
        self::assertStringContainsString('{"arco":"command_failed"', $log);
        self::assertStringContainsString('to-stderr', $log, 'command output is echoed to the function log');
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
}
