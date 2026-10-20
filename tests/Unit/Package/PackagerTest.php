<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Package;

use Multiapi\Arco\Build\BuildResult;
use Multiapi\Arco\Build\PlainPhpPreset;
use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Config\Runtime;
use Multiapi\Arco\Build\BuildException;
use Multiapi\Arco\Package\PackageManifest;
use Multiapi\Arco\Package\PackageReader;
use Multiapi\Arco\Package\PackageTooLarge;
use Multiapi\Arco\Package\Packager;
use Multiapi\Arco\Tests\Support\TempPaths;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class PackagerTest extends TestCase
{
    private string $runtimeDir;
    private BuildResult $build;
    private string $binary;

    protected function setUp(): void
    {
        $base = TempPaths::path('arco-pkg-');
        $this->runtimeDir = "$base/runtime";
        mkdir("$this->runtimeDir/lib", 0777, true);
        mkdir("$this->runtimeDir/test", 0777, true);
        file_put_contents("$this->runtimeDir/index.js", '// runtime');
        file_put_contents("$this->runtimeDir/lib/caddyfile.js", '// lib');
        file_put_contents("$this->runtimeDir/test/x.test.js", '// test');
        file_put_contents("$this->runtimeDir/bootstrap", "#!/bin/sh\n");
        file_put_contents("$this->runtimeDir/console.php", '<?php // console');
        file_put_contents("$this->runtimeDir/console-cli.php", '<?php // console-cli');
        file_put_contents("$this->runtimeDir/worker.php", '<?php // worker');
        mkdir("$this->runtimeDir/console");
        file_put_contents("$this->runtimeDir/console/Handler.php", '<?php // handler');

        mkdir("$base/staging/app/public", 0777, true);
        mkdir("$base/staging/app/vendor/big", 0777, true);
        file_put_contents("$base/staging/app/public/index.php", '<?php');
        file_put_contents("$base/staging/app/vendor/big/blob.bin", str_repeat('x', 4096));
        $this->build = new BuildResult("$base/staging", "$base/staging/app", []);

        $this->binary = "$base/frankenphp";
        file_put_contents($this->binary, 'ELF');
        chmod($this->binary, 0644);
    }

    private function config(): AppConfig
    {
        return ConfigResolver::resolve(
            App::make('shop')->region('us-east-1')
                ->runtime(fn (Runtime $r) => $r->phpIni(['memory_limit' => '512M']))
                ->environment('production')->toArray(),
            'production',
        );
    }

    public function test_zip_layout_and_runtime_config(): void
    {
        $package = (new Packager($this->runtimeDir))->package($this->build, $this->binary, $this->config(), new PlainPhpPreset());

        $zip = new ZipArchive();
        self::assertTrue($zip->open($package->path));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        sort($names);
        self::assertSame(['app/public/index.php', 'app/vendor/big/blob.bin', 'arco-package.json', 'arco-runtime.json', 'bin/frankenphp', 'bootstrap', 'index.js', 'lib/caddyfile.js', 'runtime/console-cli.php', 'runtime/console.php', 'runtime/console/Handler.php', 'runtime/worker.php'], $names);

        $zip->getExternalAttributesName('bin/frankenphp', $opsys, $attr);
        self::assertSame(ZipArchive::OPSYS_UNIX, $opsys);
        self::assertSame(0755, ($attr >> 16) & 0777);
        self::assertSame(ZipArchive::CM_STORE, $zip->statName('bin/frankenphp')['comp_method']);
        $zip->getExternalAttributesName('bootstrap', $opsys, $attr);
        self::assertSame(0755, ($attr >> 16) & 0777, 'Lambda runs bootstrap directly');
        $zip->getExternalAttributesName('runtime/console.php', $opsys, $attr);
        self::assertSame(0644, ($attr >> 16) & 0777);

        self::assertSame([
            'mode' => 'direct',
            'publicDir' => 'app/public',
            'workerScript' => null,
            'threads' => 8,
            'workers' => ['min' => 4, 'max' => 64],
            'phpIni' => ['memory_limit' => '512M'],
            'console' => ['entry' => null],
        ], json_decode((string) $zip->getFromName('arco-runtime.json'), true));
        $zip->close();

        self::assertSame(64, strlen($package->hash));
        self::assertGreaterThan(4096, $package->bytes);
    }

    public function test_workers_and_max_threads_write_the_same_runtime_json_as_the_deprecated_form(): void
    {
        $json = fn (callable $runtime): string => json_encode(Packager::runtimeConfig(ConfigResolver::resolve(
            App::make('shop')->region('us-east-1')->runtime($runtime)->environment('production')->toArray(),
            'production',
        ), new PlainPhpPreset()), JSON_THROW_ON_ERROR);

        $old = $json(fn (Runtime $r) => $r->workers(min: 4, max: 64));
        self::assertSame($old, $json(fn (Runtime $r) => $r->workers(4)->maxThreads(64)));
        self::assertSame($old, $json(fn (Runtime $r) => $r->threads(8)), 'the defaults are 4 workers and max_threads 64');
        self::assertStringContainsString('"workers":{"min":4,"max":64}', $old);
        self::assertStringContainsString('"workers":{"min":6,"max":32}', $json(fn (Runtime $r) => $r->workers(6)->maxThreads(32)));
    }

    public function test_laravel_commands_go_through_artisan(): void
    {
        self::assertSame(['entry' => 'artisan'], Packager::runtimeConfig($this->config(), new \Multiapi\Arco\Laravel\LaravelPreset())['console']);
    }

    public function test_a_package_without_the_console_runtime_is_refused(): void
    {
        $built = (new Packager($this->runtimeDir))->package($this->build, $this->binary, $this->config(), new PlainPhpPreset());
        $old = TempPaths::path('arco-old-', '.zip');
        copy($built->path, $old);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($old));
        $zip->deleteName('bootstrap');
        $zip->close();

        try {
            PackageReader::check(PackageReader::open($old), $this->config(), new PlainPhpPreset(), null);
            self::fail('A package without bootstrap must be refused.');
        } catch (BuildException $e) {
            self::assertSame('package.no_console_runtime', $e->key);
        }
    }

    public function test_hash_depends_on_content_only(): void
    {
        $packager = new Packager($this->runtimeDir);
        $first = $packager->package($this->build, $this->binary, $this->config(), new PlainPhpPreset());
        sleep(1);
        touch($this->build->appDir . '/public/index.php');
        $second = $packager->package($this->build, $this->binary, $this->config(), new PlainPhpPreset());
        self::assertSame($first->hash, $second->hash);

        file_put_contents($this->build->appDir . '/public/index.php', '<?php // changed');
        self::assertNotSame($first->hash, $packager->package($this->build, $this->binary, $this->config(), new PlainPhpPreset())->hash);
    }

    public function test_too_large_lists_largest_directories(): void
    {
        try {
            (new Packager($this->runtimeDir, maxBytes: 1024))->package($this->build, $this->binary, $this->config(), new PlainPhpPreset());
            self::fail('Expected PackageTooLarge');
        } catch (PackageTooLarge $e) {
            self::assertSame('app/vendor', array_key_first($e->largest));
            self::assertGreaterThan(1024, $e->bytes);
        }
    }

    public function test_manifest_identifies_the_package_and_carries_the_content_hash(): void
    {
        $package = (new Packager($this->runtimeDir))->package($this->build, $this->binary, $this->config(), new PlainPhpPreset(), 'fp1.2.3-php8.4-r2');

        $zip = new ZipArchive();
        self::assertTrue($zip->open($package->path));
        $manifest = json_decode((string) $zip->getFromName('arco-package.json'), true);
        $zip->close();

        self::assertIsArray($manifest);
        self::assertSame('shop', $manifest['app']);
        self::assertSame('production', $manifest['environment']);
        self::assertSame('php', $manifest['preset']);
        self::assertSame('fp1.2.3-php8.4-r2', $manifest['runtime_build']);
        self::assertSame($package->hash, $manifest['hash']);
        self::assertSame($package->bytes, $manifest['bytes']);
        self::assertSame(\Multiapi\Arco\Arco::version(), $manifest['arco_version']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $manifest['created_at']);
        self::assertEquals(PackageManifest::fromArray($manifest), $package->manifest);
    }

    public function test_reading_a_package_back_returns_its_manifest_and_hash(): void
    {
        $built = (new Packager($this->runtimeDir))->package($this->build, $this->binary, $this->config(), new PlainPhpPreset());
        $copy = TempPaths::path('arco-read-', '.zip');
        copy($built->path, $copy);

        $read = PackageReader::open($copy);

        self::assertSame($copy, $read->path);
        self::assertSame($built->hash, $read->hash);
        self::assertSame($built->bytes, $read->bytes);
        self::assertSame('production', $read->manifest?->environment);
        self::assertSame(json_encode(Packager::runtimeConfig($this->config(), new PlainPhpPreset())), json_encode(PackageReader::runtimeConfig($copy)));
    }

    public function test_reading_something_that_is_not_an_arco_package_fails(): void
    {
        $dir = TempPaths::path('arco-notpkg-');
        mkdir($dir);
        $plain = "$dir/plain.zip";
        $zip = new ZipArchive();
        $zip->open($plain, ZipArchive::CREATE);
        $zip->addFromString('index.js', '//');
        $zip->close();
        file_put_contents("$dir/text.zip", 'not a zip');

        foreach (["$dir/plain.zip", "$dir/text.zip", "$dir/missing.zip"] as $path) {
            try {
                PackageReader::open($path);
                self::fail("$path must be refused");
            } catch (BuildException $e) {
                self::assertSame('package.not_arco', $e->key, $path);
                self::assertSame(['path' => $path], $e->params);
            }
        }
    }

    public function test_zip_writing_progress_is_reported_in_whole_percent_up_to_100(): void
    {
        file_put_contents($this->build->appDir . '/vendor/big/random.bin', random_bytes(2 * 1024 * 1024));
        $percents = [];

        (new Packager($this->runtimeDir))->package($this->build, $this->binary, $this->config(), new PlainPhpPreset(), null, function (int $percent) use (&$percents): void {
            $percents[] = $percent;
        });

        self::assertNotEmpty($percents);
        self::assertSame(100, end($percents));
        $sorted = $percents;
        sort($sorted);
        self::assertSame($sorted, $percents, 'progress never goes backwards');
        self::assertSame(array_values(array_unique($percents)), $percents, 'each percent is reported once');
        foreach ($percents as $percent) {
            self::assertGreaterThanOrEqual(0, $percent);
            self::assertLessThanOrEqual(100, $percent);
        }
    }

    public function test_a_corrupt_package_is_refused(): void
    {
        $built = (new Packager($this->runtimeDir))->package($this->build, $this->binary, $this->config(), new PlainPhpPreset());
        $bytes = (string) file_get_contents($built->path);
        $bytes[31] = $bytes[31] === 'Z' ? 'Y' : 'Z'; // first entry's name in its local header no longer matches the central directory
        $corrupt = TempPaths::path('arco-corrupt-', '.zip');
        file_put_contents($corrupt, $bytes);

        try {
            PackageReader::open($corrupt);
            self::fail('A corrupt zip must be refused');
        } catch (BuildException $e) {
            self::assertSame('package.corrupt', $e->key);
            self::assertSame(['path' => $corrupt], $e->params);
        }
    }

    public function test_check_warns_when_the_runtime_build_changed(): void
    {
        $package = (new Packager($this->runtimeDir))->package($this->build, $this->binary, $this->config(), new PlainPhpPreset(), '1.13.0-r1');

        self::assertSame([], PackageReader::check($package, $this->config(), new PlainPhpPreset(), '1.13.0-r1'));
        self::assertSame(
            [['package.runtime_build_differs', ['path' => $package->path, 'package_build' => '1.13.0-r1', 'build' => '1.14.0-r1']]],
            PackageReader::check($package, $this->config(), new PlainPhpPreset(), '1.14.0-r1'),
        );
        self::assertSame(
            [['package.runtime_build_differs', ['path' => $package->path, 'package_build' => '1.13.0-r1', 'build' => 'custom binary']]],
            PackageReader::check($package, $this->config(), new PlainPhpPreset(), null),
        );
    }

    public function test_check_compares_a_pre_0_9_build_id_by_the_build_it_names(): void
    {
        $package = (new Packager($this->runtimeDir))->package($this->build, $this->binary, $this->config(), new PlainPhpPreset(), '1.13.0-r2');
        $canonical = fn (string $id): string => \Multiapi\Arco\Binary\RuntimeManifest::load()->canonical($id);

        self::assertSame([], PackageReader::check($package, $this->config(), new PlainPhpPreset(), 'php8.5.11-fp1.13.0-r2', $canonical));
        self::assertSame(
            [['package.runtime_build_differs', ['path' => $package->path, 'package_build' => '1.13.0-r2', 'build' => 'php8.5.11-fp1.13.0-r1']]],
            PackageReader::check($package, $this->config(), new PlainPhpPreset(), 'php8.5.11-fp1.13.0-r1', $canonical),
        );
    }
}
