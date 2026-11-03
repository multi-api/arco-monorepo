<?php declare(strict_types=1);

namespace Multiapi\Arco\Laravel;

use Illuminate\Support\ServiceProvider;
use Multiapi\Arco\Laravel\Queue\LambdaWorker;

/**
 * Arco Cloud extensions: opaque directories under `.arco-cloud/<name>/` that Arco Cloud adds to a
 * deployed app. Each one has a `bootstrap.php` that returns the class name of a service provider.
 * A broken extension never breaks the app: it is logged and skipped.
 */
final class CloudExtensions
{
    /** @var array<string, mixed> what each bootstrap file returned, by path: a file runs once per process */
    private static array $results = [];
    /** @var array<string, true> bootstrap files whose failure was already logged */
    private static array $logged = [];

    /**
     * @internal Forgets what the bootstraps returned and which failures were logged; tests only.
     */
    public static function flush(): void
    {
        self::$results = [];
        self::$logged = [];
    }

    /** @return list<class-string<ServiceProvider>> */
    public static function providers(string $basePath): array
    {
        $files = glob(rtrim($basePath, '/\\') . '/.arco-cloud/*/bootstrap.php');
        if ($files === false) {
            return [];
        }
        sort($files);
        $providers = [];
        foreach ($files as $file) {
            try {
                // A bootstrap may declare classes, so it runs once per process; later calls reuse its result.
                if (! array_key_exists($file, self::$results)) {
                    try {
                        self::$results[$file] = self::load($file);
                    } catch (\Throwable $e) {
                        self::$results[$file] = $e;
                    }
                }
                $provider = self::$results[$file];
                if ($provider instanceof \Throwable) {
                    throw $provider;
                }
                if (! is_string($provider) || ! class_exists($provider) || ! is_subclass_of($provider, ServiceProvider::class)) {
                    throw new \UnexpectedValueException('bootstrap.php must return the class name of a service provider');
                }
                $providers[] = $provider;
            } catch (\Throwable $e) {
                if (! isset(self::$logged[$file])) {
                    self::$logged[$file] = true;
                    self::log(['arco' => 'extension_failed', 'extension' => basename(dirname($file)), 'message' => $e->getMessage()]);
                }
            }
        }
        return $providers;
    }

    /** The file runs in its own scope, so its local variables cannot clash with ours. */
    private static function load(string $file): mixed
    {
        return (static fn (): mixed => require $file)();
    }

    /** @param array<string, mixed> $line */
    private static function log(array $line): void
    {
        try {
            (ArcoServiceProvider::$log ?? LambdaWorker::stderr(...))($line);
        } catch (\Throwable) {
            // Logging must not break the boot either.
        }
    }
}
