<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Build;

use Multiapi\Arco\Build\Builder;
use Multiapi\Arco\Build\BuildException;
use Multiapi\Arco\Build\PlainPhpPreset;
use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Tests\Support\TempPaths;
use PHPUnit\Framework\TestCase;

final class BuilderTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        // Review focus: project paths with spaces.
        $this->project = TempPaths::path('My Projects ') . '/shop';
        foreach (['public', 'src', 'tests', 'vendor/foo', 'node_modules/x', '.git', 'storage/app/public'] as $dir) {
            mkdir("$this->project/$dir", 0777, true);
        }
        file_put_contents("$this->project/composer.json", '{}');
        file_put_contents("$this->project/public/index.php", '<?php echo "hi";');
        file_put_contents("$this->project/src/App.php", '<?php');
        file_put_contents("$this->project/tests/AppTest.php", '<?php');
        file_put_contents("$this->project/vendor/foo/a.php", '<?php');
        file_put_contents("$this->project/.env", 'APP_KEY=secret');
        file_put_contents("$this->project/.env.production", 'APP_KEY=secret');
        file_put_contents("$this->project/run.sh", "#!/bin/sh\n");
        chmod("$this->project/run.sh", 0755);
        // Review focus: symlinks (Laravel's public/storage points to an absolute path).
        symlink("$this->project/storage/app/public", "$this->project/public/storage");
        symlink('/nonexistent/target', "$this->project/broken-link");
    }

    private function config(): AppConfig
    {
        return ConfigResolver::resolve(App::make('shop')->region('us-east-1')->exclude(['tests/**'])->environment('production')->toArray(), 'production');
    }

    public function test_stages_project_and_runs_composer(): void
    {
        $runner = new FakeRunner();
        $result = (new Builder($runner))->build($this->config(), new PlainPhpPreset(), $this->project);

        self::assertFileExists("$result->appDir/public/index.php");
        self::assertFileExists("$result->appDir/src/App.php");
        self::assertFileDoesNotExist("$result->appDir/tests/AppTest.php");
        self::assertFileDoesNotExist("$result->appDir/vendor/foo/a.php");
        self::assertFileDoesNotExist("$result->appDir/node_modules");
        self::assertFileDoesNotExist("$result->appDir/.env");
        self::assertFileDoesNotExist("$result->appDir/.env.production");
        self::assertFalse(is_link("$result->appDir/public/storage"));
        self::assertFileDoesNotExist("$result->appDir/broken-link");
        self::assertSame(0755, fileperms("$result->appDir/run.sh") & 0777);
        self::assertSame(['broken-link', 'public/storage'], $result->skipped);

        self::assertSame(['composer', 'install', '--no-dev', '--optimize-autoloader', '--no-interaction', '--no-progress'], $runner->calls[0]['command']);
        self::assertSame($result->appDir, $runner->calls[0]['cwd']);
        self::assertFileExists("$this->project/.env", 'the project itself is never modified');

        $result->cleanup();
        self::assertDirectoryDoesNotExist($result->stagingDir);
    }

    public function test_relative_path_repositories_outside_the_project_are_absolute_in_the_staged_copy_only(): void
    {
        $composer = json_encode(['extra' => new \stdClass(), 'repositories' => [
            ['type' => 'path', 'url' => '../../packages/*', 'options' => ['symlink' => false]],
            ['type' => 'path', 'url' => '../sibling'],
            ['type' => 'path', 'url' => '../kept', 'options' => ['symlink' => true]],
            ['type' => 'path', 'url' => 'packages/*'],
            ['type' => 'path', 'url' => './local/x'],
            ['type' => 'path', 'url' => '/abs/pkg'],
            ['type' => 'vcs', 'url' => '../not-a-path'],
        ]], JSON_UNESCAPED_SLASHES);
        $lock = json_encode(['packages' => [
            ['name' => 'a/a', 'dist' => ['type' => 'path', 'url' => '../../packages/a']],
            ['name' => 'b/b', 'dist' => ['type' => 'path', 'url' => '/abs/b']],
            ['name' => 'c/c', 'dist' => ['type' => 'zip', 'url' => 'https://example.test/c.zip']],
            ['name' => 'e/e', 'dist' => ['type' => 'path', 'url' => 'packages/e']],
        ], 'packages-dev' => [
            ['name' => 'd/d', 'dist' => ['type' => 'path', 'url' => '../d'], 'transport-options' => ['symlink' => true]],
        ]], JSON_UNESCAPED_SLASHES);
        file_put_contents("$this->project/composer.json", $composer);
        file_put_contents("$this->project/composer.lock", $lock);
        $root = realpath($this->project);
        $parent = dirname($root);
        $grand = dirname($parent);

        $result = (new Builder(new FakeRunner()))->build($this->config(), new PlainPhpPreset(), $this->project);

        $raw = (string) file_get_contents("$result->appDir/composer.json");
        self::assertStringContainsString('"extra": {}', $raw, 'an empty object stays an object');
        $staged = json_decode($raw, true);
        self::assertSame("$grand/packages/*", $staged['repositories'][0]['url']);
        self::assertSame(['symlink' => false], $staged['repositories'][0]['options']);
        self::assertSame("$parent/sibling", $staged['repositories'][1]['url']);
        self::assertSame(['symlink' => false], $staged['repositories'][1]['options']);
        self::assertSame("$parent/kept", $staged['repositories'][2]['url']);
        self::assertSame(['symlink' => true], $staged['repositories'][2]['options'], 'an explicit symlink option is respected');
        self::assertSame('packages/*', $staged['repositories'][3]['url'], 'inside the project: left alone');
        self::assertArrayNotHasKey('options', $staged['repositories'][3]);
        self::assertSame('./local/x', $staged['repositories'][4]['url']);
        self::assertSame('/abs/pkg', $staged['repositories'][5]['url']);
        self::assertSame('../not-a-path', $staged['repositories'][6]['url']);
        $stagedLock = json_decode((string) file_get_contents("$result->appDir/composer.lock"), true);
        self::assertSame("$grand/packages/a", $stagedLock['packages'][0]['dist']['url']);
        self::assertSame(['symlink' => false], $stagedLock['packages'][0]['transport-options']);
        self::assertSame('/abs/b', $stagedLock['packages'][1]['dist']['url']);
        self::assertSame('https://example.test/c.zip', $stagedLock['packages'][2]['dist']['url']);
        self::assertSame('packages/e', $stagedLock['packages'][3]['dist']['url']);
        self::assertSame("$parent/d", $stagedLock['packages-dev'][0]['dist']['url']);
        self::assertSame(['symlink' => true], $stagedLock['packages-dev'][0]['transport-options']);
        self::assertSame($composer, file_get_contents("$this->project/composer.json"), 'the project is never modified');
        self::assertSame($lock, file_get_contents("$this->project/composer.lock"));
        $result->cleanup();
    }

    public function test_path_repositories_without_a_lock_file_are_fine(): void
    {
        file_put_contents("$this->project/composer.json", '{"repositories":[{"type":"path","url":"../x"}]}');
        $result = (new Builder(new FakeRunner()))->build($this->config(), new PlainPhpPreset(), $this->project);
        $staged = json_decode((string) file_get_contents("$result->appDir/composer.json"), true);
        self::assertSame(dirname((string) realpath($this->project)) . '/x', $staged['repositories'][0]['url']);
        self::assertFileDoesNotExist("$result->appDir/composer.lock");
        $result->cleanup();
    }

    public function test_include_patterns_cannot_package_env_files(): void
    {
        foreach ([['**'], ['.env.production', '.env*']] as $include) {
            $config = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->include($include)->environment('production')->toArray(), 'production');
            $result = (new Builder(new FakeRunner()))->build($config, new PlainPhpPreset(), $this->project);

            self::assertFileDoesNotExist("$result->appDir/.env");
            self::assertFileDoesNotExist("$result->appDir/.env.production");
            self::assertFileExists("$result->appDir/src/App.php");
            $result->cleanup();
        }
    }

    public function test_nested_env_files_are_never_packaged(): void
    {
        mkdir("$this->project/sub/deeper", 0777, true);
        file_put_contents("$this->project/sub/.env", 'DB_PASSWORD=secret');
        file_put_contents("$this->project/sub/deeper/.env.local", 'DB_PASSWORD=secret');
        file_put_contents("$this->project/sub/config.php", '<?php');
        $config = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->include(['**'])->environment('production')->toArray(), 'production');

        $result = (new Builder(new FakeRunner()))->build($config, new PlainPhpPreset(), $this->project);

        self::assertFileDoesNotExist("$result->appDir/sub/.env");
        self::assertFileDoesNotExist("$result->appDir/sub/deeper/.env.local");
        self::assertFileExists("$result->appDir/sub/config.php");
        $result->cleanup();
    }

    public function test_auth_json_is_available_to_composer_but_not_packaged(): void
    {
        file_put_contents("$this->project/auth.json", '{"http-basic":{"repo.example.com":{"username":"u","password":"p"}}}');
        $seen = null;
        $runner = new FakeRunner([], function (array $command, string $cwd) use (&$seen): void {
            if (($command[1] ?? '') === 'install') {
                $seen = is_file("$cwd/auth.json");
            }
        });

        $result = (new Builder($runner))->build($this->config(), new PlainPhpPreset(), $this->project);

        self::assertTrue($seen, 'auth.json is present while composer install runs');
        self::assertFileDoesNotExist("$result->appDir/auth.json");
        self::assertFileExists("$this->project/auth.json");
        $result->cleanup();
    }

    public function test_laravel_maintenance_flag_is_not_packaged(): void
    {
        mkdir("$this->project/storage/framework", 0777, true);
        file_put_contents("$this->project/storage/framework/down", '{}');
        file_put_contents("$this->project/storage/framework/.gitignore", '*');

        $result = (new Builder(new FakeRunner()))->build($this->config(), new PlainPhpPreset(), $this->project);

        self::assertFileDoesNotExist("$result->appDir/storage/framework/down");
        self::assertFileExists("$result->appDir/storage/framework/.gitignore");
        $result->cleanup();
    }

    public function test_composer_failure_is_reported(): void
    {
        $this->expectException(BuildException::class);
        (new Builder(new FakeRunner(['install' => 'Your lock file is out of date'])))->build($this->config(), new PlainPhpPreset(), $this->project);
    }

    public function test_each_phase_is_reported_before_it_runs(): void
    {
        $events = [];
        $runner = new FakeRunner(onRun: function (array $command) use (&$events): void { $events[] = 'run:' . $command[0] . ' ' . ($command[1] ?? ''); });
        $preset = new class extends PlainPhpPreset {
            public function buildSteps(): array { return [['php', 'artisan', 'event:cache']]; }
        };

        $result = (new Builder($runner))->build($this->config(), $preset, $this->project, function (string $key, array $params = []) use (&$events): void {
            $events[] = $key . ($params === [] ? '' : ' ' . json_encode($params));
        });
        $result->cleanup();

        self::assertSame([
            'build.copying',
            'build.composer',
            'run:composer install',
            'build.step {"command":"php artisan event:cache"}',
            'run:php artisan',
        ], $events);
    }

    public function test_package_zips_at_the_project_root_and_extra_excludes_are_not_staged(): void
    {
        file_put_contents("$this->project/arco-shop-production.zip", 'old package');
        mkdir("$this->project/src/fixtures");
        file_put_contents("$this->project/src/fixtures/arco-sample.zip", 'app data');
        mkdir("$this->project/dist");
        file_put_contents("$this->project/dist/app.zip", 'old package');

        $result = (new Builder(new FakeRunner()))->build($this->config(), new PlainPhpPreset(), $this->project, null, ['dist/app.zip']);

        self::assertFileDoesNotExist("$result->appDir/arco-shop-production.zip");
        self::assertFileDoesNotExist("$result->appDir/dist/app.zip");
        self::assertFileExists("$result->appDir/src/fixtures/arco-sample.zip", 'only root-level arco-*.zip is excluded');
        $result->cleanup();
    }

    public function test_build_directories_exist_while_building_and_are_not_packaged(): void
    {
        // Arco Cloud report (2026-10-21): storage/ is excluded from the copy, so a package that touches the Blade
        // compiler in boot() made `composer install` (package:discover) fail with "Please provide a valid cache path".
        $preset = new class implements \Multiapi\Arco\Build\FrameworkPreset, \Multiapi\Arco\Build\StagesDirectories {
            public function name(): string { return 'fixture'; }
            public function detects(string $projectDir): bool { return true; }
            public function prepare(string $appDir): void {}
            public function buildSteps(): array { return [['php', 'artisan', 'event:cache']]; }
            public function workerScript(): ?string { return null; }
            public function publicDir(): string { return 'public'; }
            public function defaultVariables(): array { return []; }
            public function excludeRules(): array { return ['storage/**']; }
            public function healthPath(): string { return '/'; }
            public function buildDirectories(): array { return ['storage/framework/views', 'storage/framework/cache/data']; }
        };
        $seen = [];
        $runner = new FakeRunner([], function (array $command, string $cwd) use (&$seen): void {
            $seen[$command[1]] = is_dir("$cwd/storage/framework/views") && is_dir("$cwd/storage/framework/cache/data");
            if ($command[1] === 'artisan') {
                file_put_contents("$cwd/storage/framework/views/compiled.php", '<?php');
            }
        });

        $result = (new Builder($runner))->build($this->config(), $preset, $this->project);

        self::assertSame(['install' => true, 'artisan' => true], $seen, 'present for composer and for the build steps');
        self::assertDirectoryDoesNotExist("$result->appDir/storage", 'removed before packaging, with whatever the build wrote there');
        self::assertDirectoryExists("$this->project/storage/app/public", 'the project itself is untouched');
        $result->cleanup();
    }

    public function test_build_directories_must_stay_inside_the_staged_copy(): void
    {
        foreach (['../outside', '/tmp/x', 'storage/../../x', ''] as $bad) {
            $preset = new class($bad) implements \Multiapi\Arco\Build\FrameworkPreset, \Multiapi\Arco\Build\StagesDirectories {
                public function __construct(private string $dir) {}
                public function name(): string { return 'fixture'; }
                public function detects(string $projectDir): bool { return true; }
                public function prepare(string $appDir): void {}
                public function buildSteps(): array { return []; }
                public function workerScript(): ?string { return null; }
                public function publicDir(): string { return 'public'; }
                public function defaultVariables(): array { return []; }
                public function excludeRules(): array { return []; }
                public function healthPath(): string { return '/'; }
                public function buildDirectories(): array { return [$this->dir]; }
            };
            try {
                (new Builder(new FakeRunner()))->build($this->config(), $preset, $this->project);
                self::fail("expected a refusal for '$bad'");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
