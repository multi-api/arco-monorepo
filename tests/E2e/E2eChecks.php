<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\E2e;

use Multiapi\Arco\Tests\Support\TempPaths;
use PHPUnit\Framework\Attributes\Depends;
use Symfony\Component\Process\Process;

/**
 * The checks and helpers the Floci and the real-AWS end-to-end suites share: each deploys a copy of
 * examples/laravel through the real CLI, then checks the same features. The using class names the
 * arco.php environment (environment()) and may add variables for every arco process (processEnv()).
 */
trait E2eChecks
{
    /** The copy of examples/laravel the CLI runs in. */
    private static ?string $app = null;
    /** The function URL from arco info --json, without a trailing slash. */
    private static ?string $url = null;
    /** When the running check began; null when none is running (a skipped check never starts one). */
    private static ?float $started = null;

    private E2eHttp $http;

    abstract protected static function environment(): string;

    /** @return array<string, string> extra environment for every arco process */
    protected static function processEnv(): array
    {
        return [];
    }

    protected function setUp(): void
    {
        $this->http = new E2eHttp();
        self::$started = microtime(true);
    }

    protected function tearDown(): void
    {
        $began = self::$started;
        self::$started = null;
        self::report($this->name(), $began, $this->status()->asString());
    }

    public function test_up(): void
    {
        // The first request after a deploy may pull the runtime image and cold-start (20-30 s on Floci).
        $answer = $this->http->get('up', self::url('/up'), deadlineSeconds: 180);
        self::assertSame(200, $answer['status'], '[up] /up answered ' . $answer['status'] . ': ' . E2eHttp::excerpt($answer['body']));
    }

    #[Depends('test_up')]
    public function test_cache(): void
    {
        $data = $this->http->json('cache', self::url('/e2e/cache'));
        self::assertTrue($data['ok'] ?? null, '[cache] the DynamoDB cache did not return the value just stored: ' . json_encode($data));
    }

    #[Depends('test_up')]
    public function test_queue_job(): void
    {
        $dispatched = $this->http->json('queue', self::url('/e2e/dispatch'));
        $id = $dispatched['id'] ?? null;
        self::assertIsString($id, '[queue] /e2e/dispatch returned no job id: ' . json_encode($dispatched));

        $status = null;
        $deadline = time() + 120;
        while (time() < $deadline) {
            // The request's own retries stay within what is left of the 120 s.
            $status = $this->http->json('queue', self::url("/e2e/job/$id"), deadlineSeconds: max(1, $deadline - time()))['status'] ?? null;
            if ($status === 'done') {
                break;
            }
            sleep(5);
        }
        self::assertSame('done', $status, "[queue] job $id was not processed by the queue worker within 120 s");
    }

    #[Depends('test_up')]
    public function test_scheduler(): void
    {
        $last = null;
        $deadline = time() + 150;
        while (time() < $deadline) {
            $last = $this->http->json('scheduler', self::url('/e2e/schedule'), deadlineSeconds: max(1, $deadline - time()))['last'] ?? null;
            // Any non-empty marker counts: DynamoDB may hand a numeric-looking string back as a number.
            if (is_scalar($last) && (string) $last !== '') {
                break;
            }
            sleep(5);
        }
        self::assertTrue(is_scalar($last) && (string) $last !== '', '[scheduler] the scheduled task did not run within 150 s (no e2e-schedule in the cache)');
    }

    #[Depends('test_up')]
    public function test_arco_command(): void
    {
        $command = self::arco(['command', '--env=' . static::environment(), 'about --only=environment', '--no-interaction'], 300);
        $output = $command->getOutput() . $command->getErrorOutput();
        self::assertTrue($command->isSuccessful(), "[arco command] exit {$command->getExitCode()}:\n" . self::tail($output, 40));
        self::assertStringContainsString('production', $output, "[arco command] output does not show the production environment:\n" . self::tail($output, 40));
    }

    #[Depends('test_up')]
    public function test_logs(): void
    {
        $logs = self::arco(['logs', '--env=' . static::environment(), '--since=10m', '--no-interaction'], 120);
        self::assertTrue($logs->isSuccessful(), "[logs] exit {$logs->getExitCode()}:\n" . self::tail($logs->getOutput() . $logs->getErrorOutput(), 40));
        self::assertNotSame('', trim($logs->getOutput()), '[logs] arco logs printed nothing for the last 10 minutes');
    }

    /** Runs arco deploy for the copy; the caller fails the run when it did not succeed. */
    private static function deploy(): Process
    {
        $began = microtime(true);
        $deploy = self::arco(['deploy', '--env=' . static::environment(), '--yes', '--no-interaction'], 1800);
        self::report('deploy', $began, $deploy->isSuccessful() ? 'ok' : 'FAILED');
        return $deploy;
    }

    /**
     * The `arco info --json` data of the deployed environment; sets the URL the checks use.
     *
     * @return array<string, mixed>
     */
    private static function readInfo(): array
    {
        $info = self::arco(['info', '--env=' . static::environment(), '--json'], 120);
        $data = json_decode($info->getOutput(), true);
        if (! is_array($data) || ! is_string($data['url'] ?? null)) {
            self::fail("[deploy] arco info --json gave no URL:\n" . $info->getOutput() . $info->getErrorOutput());
        }
        self::$url = rtrim($data['url'], '/');
        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * Copies examples/laravel without its dependencies to a run-long shared temp path (deploy writes
     * .arco/ into the project) and points its path repository at the monorepo's packages, since the
     * relative ../../packages no longer resolves from the copy.
     */
    private static function copyExample(): string
    {
        $repo = dirname(__DIR__, 2);
        $source = "$repo/examples/laravel";
        $target = TempPaths::shared('e2e-app-', untilRunEnds: true);
        mkdir($target, 0777, true);

        $skip = ['vendor', 'node_modules', '.arco', '.git'];
        $items = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $file, string $path, \RecursiveDirectoryIterator $iterator): bool => ! ($iterator->getSubPath() === '' && in_array($file->getFilename(), $skip, true)),
            ),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($items as $item) {
            \assert($item instanceof \SplFileInfo);
            $destination = $target . substr($item->getPathname(), strlen($source));
            if ($item->isDir() && ! $item->isLink()) {
                mkdir($destination, 0777, true);
            } elseif (! copy($item->getPathname(), $destination)) {
                throw new \RuntimeException("Cannot copy {$item->getPathname()}.");
            }
        }

        foreach (['composer.json', 'composer.lock'] as $file) {
            $contents = (string) file_get_contents("$target/$file");
            file_put_contents("$target/$file", str_replace('"../../packages/', '"' . "$repo/packages/", $contents));
        }

        return $target;
    }

    /** @param list<string> $arguments */
    private static function arco(array $arguments, int $timeout): Process
    {
        \assert(self::$app !== null);
        $env = static::processEnv();
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2) . '/packages/arco/bin/arco', ...$arguments], self::$app, $env === [] ? null : $env, null, $timeout);
        $process->run();
        return $process;
    }

    private static function url(string $path): string
    {
        \assert(self::$url !== null);
        return self::$url . $path;
    }

    private static function report(string $check, ?float $began, string $result): void
    {
        fwrite(STDERR, E2eReport::line($check, $began === null ? null : microtime(true) - $began, $result));
    }

    private static function tail(string $text, int $lines): string
    {
        return implode("\n", array_slice(explode("\n", rtrim($text)), -$lines));
    }
}
