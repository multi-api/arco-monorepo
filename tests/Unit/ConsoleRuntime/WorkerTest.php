<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\ConsoleRuntime;

use Multiapi\Arco\Tests\Support\RuntimeApiEmulator;
use Multiapi\Arco\Tests\Support\TempPaths;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/** Runs runtime/worker.php with the local PHP: it prepares like the console, then becomes `php artisan arco:work`. */
final class WorkerTest extends TestCase
{
    private RuntimeApiEmulator $api;
    private string $root;

    protected function setUp(): void
    {
        $this->root = TempPaths::path('arco-worker-');
        $fs = new Filesystem();
        $fs->mirror(__DIR__ . '/../../../packages/arco/runtime/console', "$this->root/runtime/console");
        $fs->copy(__DIR__ . '/../../../packages/arco/runtime/worker.php', "$this->root/runtime/worker.php");
        $fs->copy(__DIR__ . '/../../../packages/arco/runtime/console-cli.php', "$this->root/runtime/console-cli.php");
        $fs->dumpFile("$this->root/arco-runtime.json", '{"phpIni":{"memory_limit":"321M"},"console":{"entry":"artisan"}}');
        // Stands in for `artisan arco:work`: prints how it was started and exits like a worker that restarts.
        $fs->dumpFile("$this->root/app/artisan", <<<'PHP'
            <?php
            echo 'args:', json_encode(array_slice($argv, 1)), "\n", 'server-args:', json_encode(array_slice($_SERVER['argv'], 1)), "\n";
            echo 'queue:', getenv('ARCO_WORKER_QUEUE'), "\n", 'home:', getenv('HOME'), "\n", 'cwd:', getcwd(), "\n", 'ini:', getenv('PHP_INI_SCAN_DIR'), "\n";
            exit(3);
            PHP);
        $this->api = new RuntimeApiEmulator();
    }

    protected function tearDown(): void
    {
        $this->api->stop();
    }

    /**
     * @param array<string, string|false> $env
     * @param list<string> $phpOptions
     */
    private function worker(array $env = [], array $phpOptions = []): Process
    {
        $process = new Process([PHP_BINARY, ...$phpOptions, "$this->root/runtime/worker.php"], "$this->root/app", [
            'AWS_LAMBDA_RUNTIME_API' => "127.0.0.1:{$this->api->port}",
            'LAMBDA_TASK_ROOT' => $this->root,
            'ARCO_TMP' => "$this->root/tmp",
            'ARCO_PHP_BINARY' => PHP_BINARY,
            'ARCO_WORKER_QUEUE' => 'emails',
            'AWS_REGION' => 'us-east-1',
            // Never inherit real credentials from the developer's shell.
            'AWS_ACCESS_KEY_ID' => false,
            'AWS_SECRET_ACCESS_KEY' => false,
            'AWS_SESSION_TOKEN' => false,
            ...$env,
        ]);
        $process->setTimeout(30);
        $process->run();
        return $process;
    }

    private function assertBecameArtisan(Process $worker): void
    {
        $output = $worker->getOutput();
        self::assertSame(3, $worker->getExitCode(), 'the exit code of arco:work is the process exit code: ' . $output . $worker->getErrorOutput());
        self::assertStringContainsString('args:["arco:work"]', $output);
        self::assertStringContainsString('queue:emails', $output);
        self::assertStringContainsString("home:$this->root/tmp\n", $output);
        self::assertStringContainsString('cwd:' . realpath("$this->root/app"), $output);
        self::assertStringContainsString("ini::$this->root/tmp/php\n", $output, 'phpIni() applies to the worker');
        self::assertSame(['session.upload_progress.enabled' => '0', 'fatal_error_backtraces' => '0', 'memory_limit' => '321M'], parse_ini_file("$this->root/tmp/php/99-arco.ini"));
        self::assertDirectoryExists("$this->root/tmp/storage/framework/views");
        self::assertSame(1, preg_match('/^\{"arco":"worker_ready","queue":"emails".*$/m', $output), $output);
        self::assertLessThan(strpos($output, 'args:'), strpos($output, '"worker_ready"'), 'ready is logged before the worker starts');
        self::assertNull($this->api->initError());
    }

    public function test_becomes_artisan_arco_work_with_the_prepared_environment(): void
    {
        $this->assertBecameArtisan($this->worker());
    }

    public function test_without_pcntl_exec_the_worker_runs_as_a_child_process(): void
    {
        $this->assertBecameArtisan($this->worker(phpOptions: ['-d', 'disable_functions=pcntl_exec']));
    }

    public function test_frankenphp_runs_artisan_through_the_php_cli_shim(): void
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

        $worker = $this->worker(['ARCO_PHP_BINARY' => $fake]);

        self::assertSame(3, $worker->getExitCode(), $worker->getOutput() . $worker->getErrorOutput());
        self::assertStringContainsString('args:["arco:work"]', $worker->getOutput());
        self::assertStringContainsString('server-args:["arco:work"]', $worker->getOutput());
    }

    public function test_an_app_without_artisan_is_an_init_error(): void
    {
        file_put_contents("$this->root/arco-runtime.json", '{"console":{"entry":null}}');

        $worker = $this->worker();

        self::assertSame(1, $worker->getExitCode());
        self::assertStringNotContainsString('args:', $worker->getOutput());
        self::assertSame('Arco.WorkerInit', $this->api->initError()['type'] ?? null);
        self::assertStringContainsString('artisan', (string) ($this->api->initError()['body'] ?? ''));
        self::assertStringContainsString('{"arco":"fatal"', $worker->getOutput());
    }

    public function test_a_missing_secret_is_an_init_error(): void
    {
        // A reference without credentials fails before any network call.
        $worker = $this->worker(['APP_KEY' => 'arco-ssm:/shop/production/app-key']);

        self::assertSame(1, $worker->getExitCode());
        self::assertStringNotContainsString('args:', $worker->getOutput());
        self::assertSame('Arco.WorkerInit', $this->api->initError()['type'] ?? null);
    }
}
