<?php declare(strict_types=1);

namespace Multiapi\Arco\Laravel;

use Multiapi\Arco\Build\BuildException;
use Multiapi\Arco\Build\CachesPaths;
use Multiapi\Arco\Build\FrameworkPreset;

final class LaravelPreset implements FrameworkPreset, CachesPaths
{
    private const OCTANE_WORKER_STUB = 'vendor/laravel/octane/src/Commands/stubs/frankenphp-worker.php';

    public function name(): string
    {
        return 'laravel';
    }

    public function detects(string $projectDir): bool
    {
        if (! is_file("$projectDir/artisan") || ! is_file("$projectDir/composer.json")) {
            return false;
        }
        $composer = json_decode((string) file_get_contents("$projectDir/composer.json"), true);
        $require = is_array($composer) && is_array($composer['require'] ?? null) ? $composer['require'] : [];
        return isset($require['laravel/framework']);
    }

    public function prepare(string $appDir): void
    {
        $stub = "$appDir/" . self::OCTANE_WORKER_STUB;
        if (! is_file($stub)) {
            throw BuildException::make('laravel.octane_missing');
        }
        copy($stub, "$appDir/public/frankenphp-worker.php");
    }

    public function buildSteps(): array
    {
        return [
            // No route:cache: it serializes closures (including the framework's /up route) with the
            // absolute staging path baked in, which does not exist at /var/task/app.
            [PHP_BINARY, 'artisan', 'event:cache'],
        ];
    }

    public function workerScript(): string
    {
        return 'public/frankenphp-worker.php';
    }

    public function publicDir(): string
    {
        return 'public';
    }

    public function defaultVariables(): array
    {
        return [
            'LARAVEL_STORAGE_PATH' => '/tmp/arco/storage',
            'LOG_CHANNEL' => 'stderr',
            'SESSION_DRIVER' => 'cookie',
            'OCTANE_SERVER' => 'frankenphp',
        ];
    }

    public function excludeRules(): array
    {
        return [
            // LARAVEL_STORAGE_PATH points at /tmp, so nothing packaged under storage/ is ever read
            // (and local uploads, debug dumps or keys there must not ship).
            'storage/**',
            'bootstrap/cache/*.php',
            'public/hot',
            'public/frankenphp-worker.php',
            'tests/**',
        ];
    }

    public function healthPath(): string
    {
        return '/up';
    }

    /** Vite writes fingerprinted assets to public/build. */
    public function cachedPaths(): array
    {
        return ['/build/*'];
    }
}
