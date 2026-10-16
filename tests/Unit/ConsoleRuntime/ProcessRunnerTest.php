<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\ConsoleRuntime;

use Multiapi\Arco\ConsoleRuntime\ProcessRunner;
use Multiapi\Arco\Tests\Support\TempPaths;
use PHPUnit\Framework\TestCase;

final class ProcessRunnerTest extends TestCase
{
    private string $dir;
    /** @var list<string> */
    private array $echoed = [];

    protected function setUp(): void
    {
        $this->dir = TempPaths::path('arco-runner-');
        mkdir($this->dir);
    }

    private function script(string $code): string
    {
        $path = $this->dir . '/s' . bin2hex(random_bytes(3)) . '.php';
        file_put_contents($path, "<?php\n$code");
        return $path;
    }

    private function runner(int $bufferLimit = 5 * 1024 * 1024): ProcessRunner
    {
        return new ProcessRunner(function (string $chunk): void { $this->echoed[] = $chunk; }, $bufferLimit);
    }

    public function test_interleaves_stdout_and_stderr_and_returns_the_exit_code(): void
    {
        $script = $this->script('echo "out\n"; fwrite(STDERR, "err\n"); echo "again\n"; exit(3);');

        $result = $this->runner()->run([PHP_BINARY, $script], $this->dir, ['PATH' => (string) getenv('PATH')], 10);

        self::assertSame(3, $result->exitCode);
        self::assertSame("out\nerr\nagain\n", $result->output);
        self::assertFalse($result->timedOut);
        self::assertSame($result->output, implode('', $this->echoed), 'everything is echoed to the function log');
    }

    public function test_arguments_reach_the_process_literally_without_a_shell(): void
    {
        $script = $this->script('echo json_encode(array_slice($argv, 1));');

        $result = $this->runner()->run([PHP_BINARY, $script, '$(id)', '; rm -rf /', '`id`', "a'b"], $this->dir, [], 10);

        self::assertSame('["$(id)","; rm -rf \/","`id`","a\'b"]', $result->output);
    }

    public function test_passes_environment_and_working_directory(): void
    {
        $script = $this->script('echo getenv("GREETING"), "|", getcwd();');

        $result = $this->runner()->run([PHP_BINARY, $script], $this->dir, ['GREETING' => 'hola'], 10);

        self::assertSame('hola|' . realpath($this->dir), $result->output);
    }

    public function test_feeds_large_stdin_without_blocking(): void
    {
        $script = $this->script('echo strlen(stream_get_contents(STDIN));');

        $result = $this->runner()->run([PHP_BINARY, $script], $this->dir, [], 10, str_repeat('x', 3 * 1024 * 1024));

        self::assertSame('3145728', $result->output);
    }

    public function test_stops_a_command_that_runs_past_its_timeout(): void
    {
        $script = $this->script('echo "started\n"; sleep(30);');
        $started = microtime(true);

        $result = $this->runner()->run([PHP_BINARY, $script], $this->dir, [], 0.5);

        self::assertTrue($result->timedOut);
        self::assertSame(ProcessRunner::TIMEOUT_EXIT_CODE, $result->exitCode);
        self::assertSame("started\n", $result->output);
        self::assertLessThan(5, microtime(true) - $started);
    }

    public function test_output_over_the_buffer_limit_keeps_the_end(): void
    {
        $script = $this->script('for ($i = 0; $i < 2000; $i++) { echo str_pad((string) $i, 9, "0", STR_PAD_LEFT), "\n"; }');

        $result = $this->runner(1000)->run([PHP_BINARY, $script], $this->dir, [], 10);

        self::assertStringStartsWith("[truncated]\n", $result->output);
        self::assertStringEndsWith("000001999\n", $result->output);
    }

    public function test_a_missing_executable_is_a_failed_command(): void
    {
        $result = $this->runner()->run([$this->dir . '/missing'], $this->dir, [], 5);

        self::assertNotSame(0, $result->exitCode);
    }
}
