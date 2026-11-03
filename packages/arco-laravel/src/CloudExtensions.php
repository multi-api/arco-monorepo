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
            $name = basename(dirname($file));
            try {
                $provider = self::load($file);
                if (! is_string($provider) || ! class_exists($provider) || ! is_subclass_of($provider, ServiceProvider::class)) {
                    throw new \UnexpectedValueException('bootstrap.php must return the class name of a service provider');
                }
                $providers[] = $provider;
            } catch (\Throwable $e) {
                self::log(['arco' => 'extension_failed', 'extension' => $name, 'message' => $e->getMessage()]);
            }
        }
        return $providers;
    }

    /** The file runs in its own scope, so its local variables cannot clash with ours. */
    private static function load(string $file): mixed
    {
        return (static fn (): mixed => require_once $file)();
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
