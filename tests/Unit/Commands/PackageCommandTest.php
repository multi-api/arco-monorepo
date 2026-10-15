<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Commands;

use Multiapi\Arco\Binary\BinaryProvider;
use Multiapi\Arco\Binary\GuzzleDownloader;
use Multiapi\Arco\Build\Builder;
use Multiapi\Arco\Build\PresetRegistry;
use Multiapi\Arco\Cli\Application;
use Multiapi\Arco\Commands\PackageCommand;
use Multiapi\Arco\Deploy\DeployPipeline;
use Multiapi\Arco\Package\Packager;
use Multiapi\Arco\Tests\Unit\Binary\ElfInspectorTest;
use Multiapi\Arco\Tests\Unit\Build\FakeRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class PackageCommandTest extends TestCase
{
    private string $project;
    private string $cwd;

    protected function setUp(): void
    {
        $this->cwd = (string) getcwd();
        $this->project = sys_get_temp_dir() . '/arco-pkg-' . bin2hex(random_bytes(4));
        mkdir("$this->project/public", 0777, true);
        file_put_contents("$this->project/public/index.php", '<?php');
        $binary = ElfInspectorTest::fakeElf(0xB7);
        file_put_contents("$this->project/arco.php", <<<PHP
            <?php
            use Multiapi\Arco\Config\App;
            use Multiapi\Arco\Config\Environment;
            use Multiapi\Arco\Config\Runtime;

            return App::make('shop')
                ->region('us-east-1')
                ->runtime(fn (Runtime \$r) => \$r->binary('$binary'))
                ->environment('production', fn (Environment \$env) => \$env->variables(['APP_ENV' => 'production']));
            PHP);
        chdir($this->project);
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
    }

    private function tester(): CommandTester
    {
        $command = new PackageCommand(
            new DeployPipeline(new Builder(new FakeRunner()), new BinaryProvider(new GuzzleDownloader(), sys_get_temp_dir(), \Multiapi\Arco\Binary\RuntimeManifest::load()), new Packager()),
            new PresetRegistry([]),
        );
        $app = Application::create();
        $app->addCommands([$command]);
        return new CommandTester($app->find('package'));
    }

    public function test_writes_default_zip_with_expected_entries(): void
    {
        $tester = $this->tester();
        $tester->execute([], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        $zipPath = "$this->project/.arco/build/arco-shop-production.zip";
        self::assertFileExists($zipPath);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($zipPath) === true);
        foreach (['index.js', 'arco-runtime.json', 'bin/frankenphp', 'app/public/index.php'] as $entry) {
            self::assertNotFalse($zip->locateName($entry), "missing $entry");
        }
        $zip->close();
    }

    public function test_unwritable_output_fails_and_cleans_staging(): void
    {
        $before = glob(sys_get_temp_dir() . '/arco-build-*') ?: [];
        $tester = $this->tester();
        $tester->execute(['--output' => '/nonexistent-dir/x.zip'], ['interactive' => false]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('/nonexistent-dir/x.zip', $tester->getDisplay());
        self::assertStringNotContainsString('Package written', $tester->getDisplay());
        self::assertSame([], array_diff(glob(sys_get_temp_dir() . '/arco-build-*') ?: [], $before));
    }

    public function test_non_interactive_output_names_each_phase_and_the_zip_progress(): void
    {
        $tester = $this->tester();
        $tester->execute([], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        foreach (['Copying the project', 'Writing the zip', '    100%', 'Package ready'] as $text) {
            self::assertStringContainsString($text, $display);
        }
        self::assertLessThan(strpos($display, 'Package ready'), strpos($display, '    100%'));
    }

    public function test_an_output_inside_the_project_is_never_packaged_again(): void
    {
        foreach ([1, 2] as $round) {
            $tester = $this->tester();
            $tester->execute(['--output' => 'dist/app.zip'], ['interactive' => false]);
            $tester->assertCommandIsSuccessful();
        }

        $zip = new \ZipArchive();
        self::assertTrue($zip->open("$this->project/dist/app.zip") === true);
        self::assertFalse($zip->locateName('app/dist/app.zip'), 'the previous package is not inside the new one');
        $zip->close();
    }
}
