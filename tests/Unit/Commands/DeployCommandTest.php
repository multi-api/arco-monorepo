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
use Multiapi\Arco\Console\CommandResult;
use Multiapi\Arco\Deploy\AppStatus;
use Multiapi\Arco\Deploy\DeployPipeline;
use Multiapi\Arco\Package\Packager;
use Multiapi\Arco\Tests\Support\TempPaths;
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
        $this->project = TempPaths::path('arco-cmd-');
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

        $home = TempPaths::path('arco-home-');
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

    private function tester(FakeDeployer $deployer, bool $verifies = true, ?int $initMs = 800, ?FakeConsole $console = null): CommandTester
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
            consoleFactory: fn () => $console ?? throw new \LogicException('No console expected.'),
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

    /** The display of a deploy whose domain step fails with $error. */
    private function deployWithFailingDomains(\Throwable $error): string
    {
        file_put_contents("$this->project/arco.php", str_replace(
            "->variables(['APP_ENV' => 'production'])",
            "->domain('arco.acmeapp.io')->dns('acme-dns')",
            (string) file_get_contents("$this->project/arco.php"),
        ));
        $this->aws->append(new Result(['Account' => '222222222222']), new Result(['Account' => '333333333333']));
        $deployer = new FakeDeployer('rev-1', new \Multiapi\Arco\Deploy\AppStatus('shop-production', 'Active', 'abc.on.aws', 'rev-1'));
        $deployer->domainsError = $error;

        $tester = $this->tester($deployer);
        $tester->execute(['--env' => 'production', '--profile' => 'acme-prod'], ['interactive' => false]);

        self::assertSame(1, $tester->getStatusCode());
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    public function test_an_expired_token_on_a_route_53_call_names_the_dns_profile(): void
    {
        $display = $this->deployWithFailingDomains(new \Aws\Exception\AwsException('expired', new \Aws\Command('ChangeResourceRecordSets'), ['code' => 'ExpiredToken', 'message' => 'token expired']));

        self::assertStringContainsString('profile "acme-dns" are missing or expired', $display);
        self::assertStringNotContainsString('profile "acme-prod" are missing', $display);
    }

    public function test_expired_credentials_that_cannot_be_attributed_do_not_name_a_wrong_profile(): void
    {
        $display = $this->deployWithFailingDomains(new \Aws\Exception\CredentialsException('Cached SSO token returned an expired token'));

        self::assertStringNotContainsString('profile "acme-prod" are missing', $display);
        self::assertStringContainsString('for profile "acme-prod" or DNS profile "acme-dns" are missing or expired', $display);
        self::assertStringContainsString('Cached SSO token returned an expired token', $display);
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
        self::assertSame(['describe', 'prepare', 'release', 'prune'], $deployer->calls);
    }

    /** Builds a real package of the fixture project and optionally rewrites fields of its manifest. */
    private function prebuilt(array $manifest = []): string
    {
        $pipeline = new DeployPipeline(new Builder(new FakeRunner()), new BinaryProvider(new GuzzleDownloader(), sys_get_temp_dir(), \Multiapi\Arco\Binary\RuntimeManifest::load()), new Packager());
        $config = \Multiapi\Arco\Config\ConfigResolver::resolve((new \Multiapi\Arco\Config\ConfigLoader())->load("$this->project/arco.php"), 'production');
        [$build, $package] = $pipeline->package($config, new \Multiapi\Arco\Build\PlainPhpPreset(), $this->project, fn () => null);
        $zip = TempPaths::path('arco-prebuilt-', '.zip');
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
        self::assertStringContainsString('• Console function: downloads the FrankenPHP binary at cold start (custom binary()', $display, 'the custom binary() note is a plain bullet');
        self::assertSame(['describe', 'prepare', 'release', 'prune'], $deployer->calls);
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
        $zip = TempPaths::path('arco-foreign-', '.zip');
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
        self::assertSame(['describe', 'prepare', 'release', 'prune'], $deployer->calls);
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
        self::assertSame(['describe', 'prepare', 'release', 'prune'], $deployer->calls);
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

    private function withDeployCommands(string $list): void
    {
        $path = "$this->project/arco.php";
        file_put_contents($path, str_replace(
            "->variables(['APP_ENV' => 'production'])",
            "->variables(['APP_ENV' => 'production'])->deployCommands($list)",
            (string) file_get_contents($path),
        ));
    }

    private function existing(?string $consoleVersion = '7'): FakeDeployer
    {
        return new FakeDeployer('rev-1', new AppStatus('shop-production', 'Active', 'abc.on.aws', 'rev-1'), consoleVersion: $consoleVersion);
    }

    public function test_deploy_commands_run_before_traffic_and_their_output_is_printed(): void
    {
        $this->withDeployCommands("['scripts/migrate.php', 'scripts/warm.php']");
        $this->aws->append(new Result(['Account' => '222222222222']));
        $deployer = $this->existing();
        $console = new FakeConsole([new CommandResult(0, "Migrated.\n"), new CommandResult(0, "Warmed.\n")], function (string $call) use ($deployer): void { $deployer->calls[] = $call; });

        $tester = $this->tester($deployer, console: $console);
        $tester->execute(['--env' => 'production', '--profile' => 'acme-prod'], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        self::assertSame(['describe', 'prepare', 'console:scripts/migrate.php@7', 'console:scripts/warm.php@7', 'release', 'live:7', 'prune'], $deployer->calls);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Running deploy command: scripts/migrate.php', $display);
        self::assertStringContainsString("Migrated.\n", $display);
        self::assertStringContainsString("Warmed.\n", $display);
        self::assertLessThan(strpos($display, 'scripts/warm.php'), strpos($display, 'Migrated.'), 'each output is printed before the next command starts');
    }

    public function test_deploy_commands_that_cannot_work_on_lambda_are_warned_about_before_the_deploy(): void
    {
        $this->withDeployCommands("['config:cache', 'migrate']");
        // A Laravel app for the checks (the build itself uses no preset here).
        file_put_contents("$this->project/artisan", '<?php');
        file_put_contents("$this->project/composer.json", (string) json_encode(['require' => ['laravel/framework' => '^13.0']]));
        $this->aws->append(new Result(['Account' => '222222222222']));
        $deployer = $this->existing();
        $console = new FakeConsole([new CommandResult(0, ''), new CommandResult(0, '')]);

        $tester = $this->tester($deployer, console: $console);
        $tester->execute(['--env' => 'production', '--profile' => 'acme-prod'], ['interactive' => false]);

        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('read-only on Lambda', $display);
        self::assertStringContainsString('Add --force', $display);
        self::assertLessThan(strpos($display, 'Running deploy command'), strpos($display, 'read-only on Lambda'), 'warned before anything runs');
    }

    public function test_a_failing_deploy_command_keeps_traffic_on_the_previous_revision(): void
    {
        $this->withDeployCommands("['scripts/migrate.php']");
        $this->aws->append(new Result(['Account' => '222222222222']));
        $deployer = $this->existing();

        $tester = $this->tester($deployer, console: new FakeConsole([new CommandResult(3, "SQLSTATE[HY000] connection refused\n")]));
        $tester->execute(['--env' => 'production', '--profile' => 'acme-prod'], ['interactive' => false]);

        self::assertSame(3, $tester->getStatusCode(), 'R12: the command\'s own exit code');
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('SQLSTATE[HY000] connection refused', $display);
        self::assertStringContainsString('exit code 3', $display);
        self::assertStringContainsString('previous revision keeps serving', $display);
        self::assertSame(['describe', 'prepare'], $deployer->calls);
    }

    public function test_a_command_failing_after_a_migration_suggests_rolling_it_back(): void
    {
        $this->withDeployCommands("['migrate --force', 'config:cache']");
        $this->aws->append(new Result(['Account' => '222222222222']));
        $deployer = $this->existing();

        $tester = $this->tester($deployer, console: new FakeConsole([new CommandResult(0, ''), new CommandResult(1, "boom\n")]));
        $tester->execute(['--env' => 'production', '--profile' => 'acme-prod'], ['interactive' => false]);

        self::assertSame(1, $tester->getStatusCode());
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('Deploy command "config:cache" failed with exit code 1', $display);
        self::assertStringContainsString('not undone: migrate --force.', $display, 'only the commands that succeeded');
        self::assertStringContainsString('arco command "migrate:rollback --force" --env=production', $display);
        self::assertSame(1, substr_count($display, 'not undone'));
    }

    public function test_a_failed_migration_alone_gets_no_rollback_hint(): void
    {
        $this->withDeployCommands("['migrate --force']");
        $this->aws->append(new Result(['Account' => '222222222222']));
        $deployer = $this->existing();

        $tester = $this->tester($deployer, console: new FakeConsole([new CommandResult(1, "SQLSTATE\n")]));
        $tester->execute(['--env' => 'production', '--profile' => 'acme-prod'], ['interactive' => false]);

        $display = preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringNotContainsString('not undone', $display);
        self::assertStringNotContainsString('migrate:rollback', $display);
    }

    public function test_an_invoke_error_during_a_deploy_command_says_its_outcome_is_unknown(): void
    {
        $this->withDeployCommands("['migrate --force']");
        $this->aws->append(new Result(['Account' => '222222222222']));
        $deployer = $this->existing();

        $tester = $this->tester($deployer, console: new FakeConsole([new \RuntimeException('cURL error 28: Operation timed out')]));
        $tester->execute(['--env' => 'production', '--profile' => 'acme-prod'], ['interactive' => false]);

        self::assertSame(1, $tester->getStatusCode());
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('outcome of deploy command "migrate --force" is unknown', $display);
        self::assertStringContainsString('cURL error 28: Operation timed out', $display);
        self::assertStringContainsString('previous revision keeps serving', $display);
        self::assertStringContainsString('may have run', $display);
        self::assertStringContainsString('migrate --force', $display);
        self::assertStringContainsString('arco command "migrate:rollback --force" --env=production', $display);
        self::assertSame(['describe', 'prepare'], $deployer->calls, 'no traffic shift');
    }

    public function test_a_crashed_deploy_command_exits_with_1_and_shows_the_log_tail(): void
    {
        $this->withDeployCommands("['scripts/migrate.php']");
        $this->aws->append(new Result(['Account' => '222222222222']));
        $deployer = $this->existing();

        $tester = $this->tester($deployer, console: new FakeConsole([new CommandResult(0, '', 'Sandbox.Timedout', 'Task timed out', ['<error>last log line</error>'])]));
        $tester->execute(['--env' => 'production', '--profile' => 'acme-prod'], ['interactive' => false]);

        self::assertSame(1, $tester->getStatusCode());
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('Sandbox.Timedout', $display);
        self::assertStringContainsString('<error>last log line</error>', $display, 'log lines are escaped, not styled');
        self::assertSame(['describe', 'prepare'], $deployer->calls);
    }

    public function test_no_deploy_commands_skips_them(): void
    {
        $this->withDeployCommands("['scripts/migrate.php']");
        $this->aws->append(new Result(['Account' => '222222222222']));
        $deployer = $this->existing();

        $tester = $this->tester($deployer, console: new FakeConsole());
        $tester->execute(['--env' => 'production', '--profile' => 'acme-prod', '--no-deploy-commands' => true], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        self::assertSame(['describe', 'prepare', 'release', 'live:7', 'prune'], $deployer->calls);
    }

    public function test_migrate_needs_laravel_and_conflicts_with_no_deploy_commands(): void
    {
        $deployer = $this->existing();
        $tester = $this->tester($deployer);

        $tester->execute(['--env' => 'production', '--profile' => 'acme-prod', '--migrate' => true], ['interactive' => false]);
        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Laravel', $tester->getDisplay());

        $tester->execute(['--env' => 'production', '--profile' => 'acme-prod', '--migrate' => true, '--no-deploy-commands' => true], ['interactive' => false]);
        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('--no-deploy-commands', $tester->getDisplay());
        self::assertSame([], $deployer->calls, 'both fail before any AWS call');
        self::assertSame([], $this->factoryCalls);
    }

    public function test_a_rollback_after_deploy_commands_says_what_was_not_undone(): void
    {
        $this->withDeployCommands("['migrate --force']");
        $this->aws->append(new Result(['Account' => '222222222222']));
        $deployer = $this->existing();

        $tester = $this->tester($deployer, verifies: false, console: new FakeConsole([new CommandResult(0, '')]));
        $tester->execute(['--env' => 'production', '--profile' => 'acme-prod'], ['interactive' => false]);

        self::assertSame(1, $tester->getStatusCode());
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('not undone: migrate --force', $display);
        self::assertStringContainsString('arco command "migrate:rollback --force" --env=production', $display);
        self::assertContains('rollback:rev-1', $deployer->calls);
    }

    public function test_an_error_after_deploy_commands_ran_still_says_what_was_not_undone(): void
    {
        $this->withDeployCommands("['scripts/warm.php']");
        $this->aws->append(new Result(['Account' => '222222222222']));
        $deployer = $this->existing();
        $deployer->releaseError = new \Multiapi\Arco\Cli\UserError('release refused');

        $tester = $this->tester($deployer, console: new FakeConsole([new CommandResult(0, '')]));
        $tester->execute(['--env' => 'production', '--profile' => 'acme-prod'], ['interactive' => false]);

        self::assertSame(1, $tester->getStatusCode());
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('release refused', $display);
        self::assertStringContainsString('not undone: scripts/warm.php', $display);
        self::assertStringNotContainsString('migrate:rollback', $display, 'no migrate, no migrate hint');
    }

    public function test_an_unexpected_error_after_deploy_commands_ran_still_says_what_was_not_undone(): void
    {
        $this->withDeployCommands("['migrate --force']");
        $this->aws->append(new Result(['Account' => '222222222222']));
        $deployer = $this->existing();
        $deployer->releaseError = new \RuntimeException('connection reset');

        $tester = $this->tester($deployer, console: new FakeConsole([new CommandResult(0, '')]));
        try {
            $tester->execute(['--env' => 'production', '--profile' => 'acme-prod'], ['interactive' => false]);
            self::fail('An unknown error is rethrown for the application to render.');
        } catch (\RuntimeException $e) {
            self::assertSame('connection reset', $e->getMessage());
        }
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('not undone: migrate --force', $display);
        self::assertStringContainsString('migrate:rollback', $display);
    }

    public function test_a_refused_console_alias_move_is_a_warning_and_the_deploy_succeeds(): void
    {
        $this->aws->append(new Result(['Account' => '222222222222']));
        $deployer = $this->existing();
        $deployer->activateError = new \RuntimeException('AccessDenied');

        $tester = $this->tester($deployer);
        $tester->execute(['--env' => 'production', '--profile' => 'acme-prod'], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('[WARNING] Revision rev-2 is live and verified, but the live alias of shop-production-console was not moved to version 7 (AccessDenied)', $display);
        self::assertStringContainsString('arco command and arco tinker still run the previous version', $display);
        self::assertContains('prune', $deployer->calls);
    }

    public function test_a_refused_prune_is_shown_as_a_warning_block(): void
    {
        $this->aws->append(new Result(['Account' => '222222222222']));
        $deployer = $this->existing();
        $deployer->pruneWarning = 'deploy.prune_versions_failed';

        $tester = $this->tester($deployer);
        $tester->execute(['--env' => 'production', '--profile' => 'acme-prod'], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('[WARNING] Old versions of console function f were not deleted (AccessDenied)', $display);
        self::assertStringNotContainsString('• Old versions', $display);
    }
}
