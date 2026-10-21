<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Laravel;

use Multiapi\Arco\Build\BuildException;
use Multiapi\Arco\Build\PresetRegistry;
use Multiapi\Arco\Laravel\LaravelPreset;
use Multiapi\Arco\Tests\Support\TempPaths;
use PHPUnit\Framework\TestCase;

final class LaravelPresetTest extends TestCase
{
    private function laravelProject(bool $withOctane = true): string
    {
        $dir = TempPaths::path('arco-laravel-');
        mkdir("$dir/public", 0777, true);
        file_put_contents("$dir/artisan", '<?php');
        file_put_contents("$dir/composer.json", json_encode(['require' => ['php' => '^8.3', 'laravel/framework' => '^13.0']]));
        if ($withOctane) {
            mkdir("$dir/vendor/laravel/octane/src/Commands/stubs", 0777, true);
            file_put_contents("$dir/vendor/laravel/octane/src/Commands/stubs/frankenphp-worker.php", '<?php // octane worker');
        }
        return $dir;
    }

    public function test_detection(): void
    {
        $preset = new LaravelPreset();
        self::assertTrue($preset->detects($this->laravelProject()));

        $plain = TempPaths::path('arco-plain-');
        mkdir($plain);
        file_put_contents("$plain/composer.json", '{"require":{"slim/slim":"^4"}}');
        self::assertFalse($preset->detects($plain));

        self::assertSame('laravel', (new PresetRegistry([$preset]))->detect($this->laravelProject())->name());
    }

    public function test_prepare_copies_the_octane_worker(): void
    {
        $dir = $this->laravelProject();
        (new LaravelPreset())->prepare($dir);
        self::assertSame('<?php // octane worker', file_get_contents("$dir/public/frankenphp-worker.php"));
    }

    public function test_prepare_requires_octane(): void
    {
        try {
            (new LaravelPreset())->prepare($this->laravelProject(withOctane: false));
            self::fail('Expected BuildException');
        } catch (BuildException $e) {
            self::assertSame('laravel.octane_missing', $e->key);
        }
    }

    public function test_defaults(): void
    {
        $preset = new LaravelPreset();
        self::assertSame('public/frankenphp-worker.php', $preset->workerScript());
        self::assertSame('/up', $preset->healthPath());
        self::assertSame([[PHP_BINARY, 'artisan', 'event:cache']], $preset->buildSteps());
        self::assertSame('/tmp/arco/storage', $preset->defaultVariables()['LARAVEL_STORAGE_PATH']);
        self::assertSame('stderr', $preset->defaultVariables()['LOG_CHANNEL']);
        self::assertContains('bootstrap/cache/*.php', $preset->excludeRules());
        self::assertContains('storage/**', $preset->excludeRules());
    }

    public function test_caches_vite_build_output(): void
    {
        self::assertSame(['/build/*'], (new LaravelPreset())->cachedPaths());
        self::assertSame([], (new \Multiapi\Arco\Build\PlainPhpPreset())->cachedPaths());
    }

    public function test_commands_run_through_artisan(): void
    {
        self::assertInstanceOf(\Multiapi\Arco\Build\RunsCommands::class, new LaravelPreset());
        self::assertSame('artisan', (new LaravelPreset())->commandEntry());
    }

    public function test_stages_laravel_storage_directories_for_the_build(): void
    {
        self::assertInstanceOf(\Multiapi\Arco\Build\StagesDirectories::class, new LaravelPreset());
        self::assertSame(
            ['storage/framework/views', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/logs'],
            (new LaravelPreset())->buildDirectories(),
        );
    }
}
