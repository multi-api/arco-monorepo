<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Commands;

use Aws\MockHandler;
use Aws\Result;
use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Binary\BinaryProvider;
use Multiapi\Arco\Binary\GuzzleDownloader;
use Multiapi\Arco\Build\Builder;
use Multiapi\Arco\Build\PresetRegistry;
use Multiapi\Arco\Cli\Application;
use Multiapi\Arco\Commands\DeployCommand;
use Multiapi\Arco\Deploy\DeployPipeline;
use Multiapi\Arco\Package\Packager;
use Multiapi\Arco\Tests\Unit\Binary\ElfInspectorTest;
use Multiapi\Arco\Tests\Unit\Build\FakeRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class DeployCommandTest extends TestCase
{
    private string $project;
    private string $cwd;
    private MockHandler $aws;

    protected function setUp(): void
    {
        $this->cwd = (string) getcwd();
        $this->project = sys_get_temp_dir() . '/arco-cmd-' . bin2hex(random_bytes(4));
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
                ->environment('production', fn (Environment \$env) => \$env
                    ->variables(['APP_ENV' => 'production']));
            PHP);
        chdir($this->project);

        $home = sys_get_temp_dir() . '/arco-home-' . bin2hex(random_bytes(4));
        mkdir("$home/.aws", 0777, true);
        file_put_contents("$home/.aws/config", "[profile acme-prod]\nsso_session = m\nsso_account_id = 222222222222\n\n[profile personal]\nregion = us-east-1\n");
        $keys = "aws_access_key_id = test\naws_secret_access_key = test\n";
        file_put_contents("$home/.aws/credentials", "[acme-prod]\n{$keys}[acme-dns]\n{$keys}[other-dns]\n{$keys}");
        putenv("AWS_CONFIG_FILE=$home/.aws/config");
        putenv("AWS_SHARED_CREDENTIALS_FILE=$home/.aws/credentials");
        putenv('AWS_PROFILE');
        putenv('AWS_ACCESS_KEY_ID');
        $this->aws = new MockHandler();
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        putenv('AWS_CONFIG_FILE');
        putenv('AWS_SHARED_CREDENTIALS_FILE');
    }

    /** @var list<array{0: ?string, 1: ?string, 2: bool}> deploy profile, DNS profile, force */
    private array $factoryCalls = [];

    private function tester(FakeDeployer $deployer, bool $verifies = true, ?int $initMs = 800): CommandTester
    {
        $command = new DeployCommand(
            awsFactory: fn (?string $profile, string $region) => new AwsClients($profile, $region, ['handler' => $this->aws, 'credentials' => false]),
            pipeline: new DeployPipeline(new Builder(new FakeRunner()), new BinaryProvider(new GuzzleDownloader(), sys_get_temp_dir(), \Multiapi\Arco\Binary\RuntimeManifest::load()), new Packager()),
            deployerFactory: function (AwsClients $aws, ?AwsClients $dns, bool $force) use ($deployer) {
                $this->factoryCalls[] = [$aws->profile, $dns?->profile, $force];
                return $deployer;
            },
            verifierFactory: fn () => new FakeVerifier($verifies, $initMs),
            presets: new PresetRegistry([]),
        );
        $app = Application::create();
        $app->addCommands([$command]);
        return new CommandTester($app->find('deploy'));
    }

    public function test_first_deploy_prompts_for_profile_remembers_it_and_offers_account_lock(): void
    {
        $this->aws->append(new Result(['Account' => '222222222222']));
        $tester = $this->tester(new FakeDeployer());
        // profile choice (index of "acme-prod"), confirm creation, accept account lock
        $tester->setInputs(['acme-prod', 'yes', 'yes']);

        $tester->execute(['--env' => 'production']);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        self::assertStringContainsString('shop → production', $display);
        self::assertStringContainsString('222222222222', $display);
        self::assertStringContainsString('https://abc.on.aws', $display);
        self::assertStringContainsString("'acme-prod'", (string) file_get_contents("$this->project/.arco/local.php"));
        self::assertStringContainsString("->account('222222222222')", (string) file_get_contents("$this->project/arco.php"));
    }

    public function test_account_lock_stops_deploys_to_another_account(): void
    {
        file_put_contents("$this->project/arco.php", str_replace("->variables(['APP_ENV' => 'production'])", "->account('999999999999')", (string) file_get_contents("$this->project/arco.php")));
        $this->aws->append(new Result(['Account' => '222222222222']));
        $deployer = new FakeDeployer();

        $tester = $this->tester($deployer);
        $tester->execute(['--env' => 'production', '--profile' => 'acme-prod']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('999999999999', $tester->getDisplay());
        self::assertSame([], $deployer->calls);
    }

    public function test_non_interactive_without_profile_fails_without_touching_aws(): void
    {
        $deployer = new FakeDeployer();
        $tester = $this->tester($deployer);

        $tester->execute(['--env' => 'production'], ['interactive' => false]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('--profile', $tester->getDisplay());
        self::assertSame([], $deployer->calls);
    }

    public function test_failed_verification_exits_non_zero_and_shows_logs(): void
    {
        $this->aws->append(new Result(['Account' => '222222222222']));
        $deployer = new FakeDeployer('rev-1', new \Multiapi\Arco\Deploy\AppStatus('shop-production', 'Active', 'abc.on.aws', 'rev-1'));

        $tester = $this->tester($deployer, verifies: false);
        $tester->execute(['--env' => 'production', '--profile' => 'acme-prod', '--yes' => true], ['interactive' => false]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Application.InitCodeError', $tester->getDisplay());
        self::assertStringContainsString('{"arco":"fatal","error":"boom"}', $tester->getDisplay());
        self::assertContains('rollback:rev-1', $deployer->calls);
    }

    public function test_custom_domains_use_the_dns_profile_and_are_listed_in_the_summary(): void
    {
        file_put_contents("$this->project/arco.php", str_replace(
            "->variables(['APP_ENV' => 'production'])",
            "->domain(['arco.acmeapp.io', 'api-arco.acmeapp.io'])->dns('acme-dns')",
            (string) file_get_contents("$this->project/arco.php"),
        ));
        $this->aws->append(new Result(['Account' => '222222222222']), new Result(['Account' => '333333333333']));
        $deployer = new FakeDeployer('rev-1', new \Multiapi\Arco\Deploy\AppStatus('shop-production', 'Active', 'abc.on.aws', 'rev-1'));

        $tester = $this->tester($deployer);
        $tester->execute(['--env' => 'production', '--profile' => 'acme-prod', '--force-dns' => true], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        self::assertSame([['acme-prod', 'acme-dns', true]], $this->factoryCalls);
        self::assertContains('domains:', $deployer->calls);
        $display = $tester->getDisplay();
        self::assertStringContainsString('333333333333 (acme-dns)', $display);
        self::assertStringContainsString('https://arco.acmeapp.io', $display);
        self::assertStringContainsString('https://api-arco.acmeapp.io', $display);
    }

    public function test_dns_profile_flag_overrides_the_config(): void
    {
        file_put_contents("$this->project/arco.php", str_replace(
            "->variables(['APP_ENV' => 'production'])",
            "->domain('arco.acmeapp.io')->dns(false)",
            (string) file_get_contents("$this->project/arco.php"),
        ));
        $this->aws->append(new Result(['Account' => '222222222222']), new Result(['Account' => '333333333333']));
        $deployer = new FakeDeployer('rev-1', new \Multiapi\Arco\Deploy\AppStatus('shop-production', 'Active', 'abc.on.aws', 'rev-1'));

        $tester = $this->tester($deployer);
        $tester->execute(['--env' => 'production', '--profile' => 'acme-prod', '--dns-profile' => 'other-dns'], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        self::assertSame([['acme-prod', 'other-dns', false]], $this->factoryCalls);
    }

    public function test_without_domains_no_dns_clients_are_made(): void
    {
        $this->aws->append(new Result(['Account' => '222222222222']));
        $deployer = new FakeDeployer('rev-1', new \Multiapi\Arco\Deploy\AppStatus('shop-production', 'Active', 'abc.on.aws', 'rev-1'));

        $tester = $this->tester($deployer);
        $tester->execute(['--env' => 'production', '--profile' => 'acme-prod'], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        self::assertSame([['acme-prod', null, false]], $this->factoryCalls);
        self::assertSame(['describe', 'deploy'], $deployer->calls);
    }

    /** Builds a real package of the fixture project and optionally rewrites fields of its manifest. */
    private function prebuilt(array $manifest = []): string
    {
        $pipeline = new DeployPipeline(new Builder(new FakeRunner()), new BinaryProvider(new GuzzleDownloader(), sys_get_temp_dir(), \Multiapi\Arco\Binary\RuntimeManifest::load()), new Packager());
        $config = \Multiapi\Arco\Config\ConfigResolver::resolve((new \Multiapi\Arco\Config\ConfigLoader())->load("$this->project/arco.php"), 'production');
        [$build, $package] = $pipeline->package($config, new \Multiapi\Arco\Build\PlainPhpPreset(), $this->project, fn () => null);
        $zip = sys_get_temp_dir() . '/arco-prebuilt-' . bin2hex(random_bytes(4)) . '.zip';
        copy($package->path, $zip);
        $build->cleanup();
        if ($manifest !== []) {
            $archive = new \ZipArchive();
            $archive->open($zip);
            $current = json_decode((string) $archive->getFromName('arco-package.json'), true);
            $archive->addFromString('arco-package.json', (string) json_encode([...$current, ...$manifest]));
            $archive->close();
        }
        return $zip;
    }

    public function test_deploying_a_prebuilt_package_skips_the_build(): void
    {
        $zip = $this->prebuilt();
        $this->aws->append(new Result(['Account' => '222222222222']));
        $deployer = new FakeDeployer('rev-1', new \Multiapi\Arco\Deploy\AppStatus('shop-production', 'Active', 'abc.on.aws', 'rev-1'));
        $before = glob(sys_get_temp_dir() . '/arco-build-*') ?: [];

        $tester = $this->tester($deployer);
        $tester->execute(['--env' => 'production', '--profile' => 'acme-prod', '--package' => $zip], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        self::assertStringContainsString($zip, $display);
        self::assertStringNotContainsString('Building shop', $display);
        self::assertStringNotContainsString('Package ready', $display);
        self::assertStringNotContainsString('WARNING', $display, 'a matching package raises no warning');
        self::assertSame(['describe', 'deploy'], $deployer->calls);
        self::assertSame($zip, $deployer->package?->path);
        self::assertSame(\Multiapi\Arco\Package\PackageReader::open($zip)->hash, $deployer->package->hash);
        self::assertSame(['APP_ENV' => 'production', 'ARCO' => '1', 'ARCO_APP' => 'shop', 'ARCO_ENV' => 'production'], $deployer->variables);
        self::assertSame([], array_diff(glob(sys_get_temp_dir() . '/arco-build-*') ?: [], $before));
    }

    public function test_a_package_built_for_another_environment_is_refused_before_touching_aws(): void
    {
        $zip = $this->prebuilt(['environment' => 'staging']);
        $deployer = new FakeDeployer();

        $tester = $this->tester($deployer);
        $tester->execute(['--env' => 'production', '--profile' => 'acme-prod', '--package' => $zip], ['interactive' => false]);

        self::assertSame(1, $tester->getStatusCode());
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('shop → staging', $display);
        self::assertStringContainsString('arco package --env=production', $display);
        self::assertSame([], $deployer->calls);
        self::assertSame(0, $this->aws->count() + count($this->factoryCalls));
    }

    public function test_a_zip_that_is_not_an_arco_package_is_refused(): void
    {
        $zip = sys_get_temp_dir() . '/arco-foreign-' . bin2hex(random_bytes(4)) . '.zip';
        $archive = new \ZipArchive();
        $archive->open($zip, \ZipArchive::CREATE);
        $archive->addFromString('index.js', '//');
        $archive->close();
        $deployer = new FakeDeployer();

        $tester = $this->tester($deployer);
        $tester->execute(['--env' => 'production', '--profile' => 'acme-prod', '--package' => $zip], ['interactive' => false]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('not an Arco package', $tester->getDisplay());
        self::assertSame([], $deployer->calls);
    }

    public function test_a_package_from_another_arco_version_warns_but_deploys(): void
    {
        $zip = $this->prebuilt(['arco_version' => '0.0.1']);
        $this->aws->append(new Result(['Account' => '222222222222']));
        $deployer = new FakeDeployer('rev-1', new \Multiapi\Arco\Deploy\AppStatus('shop-production', 'Active', 'abc.on.aws', 'rev-1'));

        $tester = $this->tester($deployer);
        $tester->execute(['--env' => 'production', '--profile' => 'acme-prod', '--package' => $zip], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('Arco 0.0.1', $display);
        self::assertStringContainsString('Arco ' . \Multiapi\Arco\Arco::version(), $display);
        self::assertSame(['describe', 'deploy'], $deployer->calls);
    }

    public function test_summary_shows_the_cold_start_or_says_it_is_not_available_yet(): void
    {
        foreach ([[800, 'Cold start: 800 ms'], [null, 'Cold start: not available yet']] as [$ms, $expected]) {
            $this->aws->append(new Result(['Account' => '222222222222']));
            $deployer = new FakeDeployer('rev-1', new \Multiapi\Arco\Deploy\AppStatus('shop-production', 'Active', 'abc.on.aws', 'rev-1'));

            $tester = $this->tester($deployer, initMs: $ms);
            $tester->execute(['--env' => 'production', '--profile' => 'acme-prod'], ['interactive' => false]);

            $tester->assertCommandIsSuccessful();
            $display = preg_replace('/\s+/', ' ', $tester->getDisplay());
            self::assertStringContainsString($expected, $display);
            self::assertStringNotContainsString('Cold start: - ms', $display);
        }
    }

    public function test_a_package_built_with_another_runtime_build_warns_but_deploys(): void
    {
        $zip = $this->prebuilt(['runtime_build' => '1.0.0-r1']);
        $this->aws->append(new Result(['Account' => '222222222222']));
        $deployer = new FakeDeployer('rev-1', new \Multiapi\Arco\Deploy\AppStatus('shop-production', 'Active', 'abc.on.aws', 'rev-1'));

        $tester = $this->tester($deployer);
        $tester->execute(['--env' => 'production', '--profile' => 'acme-prod', '--package' => $zip], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('runtime build 1.0.0-r1', $display);
        self::assertSame(['describe', 'deploy'], $deployer->calls);
    }

    public function test_a_package_built_with_another_preset_is_refused(): void
    {
        $zip = $this->prebuilt(['preset' => 'laravel']);
        $deployer = new FakeDeployer();

        $tester = $this->tester($deployer);
        $tester->execute(['--env' => 'production', '--profile' => 'acme-prod', '--package' => $zip], ['interactive' => false]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('laravel preset', preg_replace('/\s+/', ' ', $tester->getDisplay()));
        self::assertSame([], $deployer->calls);
    }
}
