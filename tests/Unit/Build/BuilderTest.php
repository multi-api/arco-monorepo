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
}
