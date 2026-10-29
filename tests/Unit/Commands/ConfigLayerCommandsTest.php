<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Commands;

use Aws\MockHandler;
use Aws\Result;
use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Binary\BinaryProvider;
use Multiapi\Arco\Binary\Downloader;
use Multiapi\Arco\Binary\RuntimeManifest;
use Multiapi\Arco\Build\Builder;
use Multiapi\Arco\Build\PresetRegistry;
use Multiapi\Arco\Commands\DeployCommand;
use Multiapi\Arco\Commands\InfoCommand;
use Multiapi\Arco\Commands\LogsCommand;
use Multiapi\Arco\Commands\PackageCommand;
use Multiapi\Arco\Commands\QueueAddCommand;
use Multiapi\Arco\Commands\RemoveCommand;
use Multiapi\Arco\Deploy\AppStatus;
use Multiapi\Arco\Deploy\DeployPipeline;
use Multiapi\Arco\Package\Packager;
use Multiapi\Arco\Tests\Support\TempPaths;
use Multiapi\Arco\Tests\Unit\Binary\ElfInspectorTest;
use Multiapi\Arco\Tests\Unit\Build\FakeRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** --config-layer / ARCO_CONFIG_LAYER on deploy, package, info, remove and logs; deploying without arco.php. */
final class ConfigLayerCommandsTest extends TestCase
{
    private ?ProjectFixture $project = null;
    private MockHandler $aws;
    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        foreach (['AWS_ENDPOINT_URL', 'AWS_IGNORE_CONFIGURED_ENDPOINT_URLS', 'ARCO_CONFIG_LAYER'] as $name) {
            $this->savedEnv[$name] = getenv($name);
            putenv($name);
        }
        $this->aws = new MockHandler();
    }

    protected function tearDown(): void
    {
        $this->project?->restore();
        putenv('ARCO_CONFIG_LAYER');
        foreach ($this->savedEnv as $name => $value) {
            putenv($value === false ? $name : "$name=$value");
        }
    }

    private function project(?string $arcoPhp = null): ProjectFixture
    {
        $this->project = new ProjectFixture($arcoPhp);
        file_put_contents($this->project->dir . '/public/index.php', '<?php');
        return $this->project;
    }

    /**
     * A layer for production of the app shop in us-east-1.
     *
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $top
     */
    private function layer(array $entry = [], array $top = []): string
    {
        return ($this->project ?? throw new \LogicException('No project.'))->layer($top + [
            'schema' => 1,
            'name' => 'shop',
            'region' => 'us-east-1',
            'environments' => ['production' => (object) $entry],
        ]);
    }

    /** A pipeline whose default runtime build is a fake arm64 ELF, served by a downloader that never reaches the network. */
    private static function pipeline(): DeployPipeline
    {
        $elf = (string) file_get_contents(ElfInspectorTest::fakeElf(0xB7));
        $manifest = RuntimeManifest::fromArray([
            'default_php' => '8.5',
            'builds' => ['php8.5.11-fp1.13.0-r1' => [
                'url' => 'https://example.test/frankenphp-linux-aarch64',
                'sha256' => hash('sha256', $elf),
                'size' => strlen($elf),
                'frankenphp' => '1.13.0',
                'php' => '8.5.11',
            ]],
        ]);
        $downloader = new class ($elf) implements Downloader {
            public function __construct(private string $content)
            {
            }

            public function download(string $url, string $destination, ?callable $onProgress = null): void
            {
                file_put_contents($destination, $this->content);
            }
        };
        return new DeployPipeline(new Builder(new FakeRunner()), new BinaryProvider($downloader, TempPaths::path('arco-cache-'), $manifest), new Packager());
    }

    private function deployTester(FakeDeployer $deployer): CommandTester
    {
        return ($this->project ?? throw new \LogicException('No project.'))->tester(new DeployCommand(
            awsFactory: fn (?string $profile, string $region) => new AwsClients($profile, $region, ['handler' => $this->aws, 'credentials' => false]),
            pipeline: self::pipeline(),
            deployerFactory: fn () => $deployer,
            verifierFactory: fn () => new FakeVerifier(true, 800),
            presets: new PresetRegistry([]),
            consoleFactory: fn () => throw new \LogicException('No console expected.'),
        ));
    }

    private function infoTester(): CommandTester
    {
        return ($this->project ?? throw new \LogicException('No project.'))->tester(new InfoCommand(deployerFactory: fn () => throw new \LogicException('no AWS expected')));
    }

    /** @return list<array<string, mixed>> */
    private static function lines(CommandTester $tester): array
    {
        return DeployJsonTest::lines($tester->getDisplay());
    }

    /** @return array<string, mixed> */
    private static function finalLine(CommandTester $tester): array
    {
        $lines = self::lines($tester);
        return $lines[count($lines) - 1];
    }

    private static function flat(string $text): string
    {
        return (string) preg_replace('/\s+/', ' ', $text);
    }

    private static function arcoPhpWithQueue(string $queue): string
    {
        return <<<PHP
            <?php
            use Multiapi\Arco\Config\App;
            use Multiapi\Arco\Config\Environment;

            return App::make('shop')
                ->region('us-east-1')
                ->environment('production', fn (Environment \$env) => \$env->$queue);
            PHP;
    }

    public function test_deploy_without_arco_php_uses_the_layer_environment(): void
    {
        $this->project();
        $layer = $this->layer(['variables' => ['APP_ENV' => 'production']]);
        $this->aws->append(new Result(['Account' => '222222222222']));
        $deployer = new FakeDeployer('rev-1');

        $tester = $this->deployTester($deployer);
        $tester->execute(['--config-layer' => $layer, '--profile' => 'acme-prod', '--yes' => true], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        self::assertSame('shop', $deployer->preparedConfig?->app);
        self::assertSame('production', $deployer->preparedConfig->environment);
        self::assertFalse($deployer->preparedConfig->hasArcoPhp);
        self::assertFileDoesNotExist($this->project?->dir . '/arco.php');
    }

    public function test_env_var_is_read_by_info_json(): void
    {
        $this->project(ProjectFixture::standardConfig());
        putenv('ARCO_CONFIG_LAYER=' . $this->layer(['queues' => ['emails' => ['timeout' => 60]]]));

        $tester = $this->infoTester();
        $tester->execute(['--env' => 'production', '--json' => true], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        $json = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($json);
        self::assertSame(['emails'], array_column($json['queues'], 'name'));
        self::assertSame('shop-production', $json['function']);
    }

    public function test_env_option_must_match_the_layer(): void
    {
        $this->project();
        $layer = $this->layer();
        $deployer = new FakeDeployer('rev-1');

        $tester = $this->deployTester($deployer);
        $tester->execute(['--config-layer' => $layer, '--env' => 'staging', '--profile' => 'acme-prod', '--json' => true], ['capture_stderr_separately' => true]);

        self::assertSame(1, $tester->getStatusCode());
        $result = self::finalLine($tester);
        self::assertSame('refused', $result['outcome']);
        self::assertSame('config.layer_environment', $result['error']['key']);
        self::assertSame([], $deployer->calls);
    }

    public function test_conflict_fails_before_any_aws_call(): void
    {
        $this->project(self::arcoPhpWithQueue("queue('emails', timeout: 30)"));
        $layer = $this->layer(['queues' => ['emails' => ['timeout' => 60]]]);
        $this->aws->append(new Result(['Account' => '222222222222']));
        $deployer = new FakeDeployer('rev-1');

        $tester = $this->deployTester($deployer);
        $tester->execute(['--config-layer' => $layer, '--profile' => 'acme-prod', '--json' => true], ['capture_stderr_separately' => true]);

        self::assertSame(1, $tester->getStatusCode());
        $result = self::finalLine($tester);
        self::assertSame('refused', $result['outcome']);
        self::assertSame('config.layer_conflict', $result['error']['key']);
        self::assertSame(['kind' => 'queue', 'name' => 'emails'], $result['error']['params']);
        self::assertStringContainsString('queue "emails"', $result['error']['message']);
        self::assertSame([], $deployer->calls);
        self::assertSame(1, $this->aws->count(), 'no AWS call: the account was never asked');
    }

    public function test_adopt_waiting_note_is_a_warning(): void
    {
        $this->project(self::arcoPhpWithQueue("queue('emails')"));
        $layer = $this->layer(['queues' => ['emails' => ['adopt' => true]]]);

        $this->aws->append(new Result(['Account' => '222222222222']));
        $tester = $this->deployTester(new FakeDeployer('rev-1'));
        $tester->execute(['--config-layer' => $layer, '--profile' => 'acme-prod', '--json' => true], ['capture_stderr_separately' => true]);

        $warnings = array_values(array_filter(self::lines($tester), fn (array $line) => ($line['status'] ?? null) === 'warning'));
        self::assertSame('config.layer_adopt_waiting', $warnings[0]['key'] ?? null);
        self::assertSame(['kind' => 'queue', 'name' => 'emails'], $warnings[0]['params']);

        $this->aws->append(new Result(['Account' => '222222222222']));
        $tester = $this->deployTester(new FakeDeployer('rev-1'));
        $tester->execute(['--config-layer' => $layer, '--profile' => 'acme-prod', '--yes' => true], ['interactive' => false]);

        self::assertStringContainsString('queue "emails" is still declared in arco.php', self::flat($tester->getDisplay()));
    }

    public function test_package_remove_logs_accept_the_option(): void
    {
        $project = $this->project();
        $layer = $this->layer();
        $zip = TempPaths::path('arco-zip-') . '/out.zip';

        $package = $project->tester(new PackageCommand(self::pipeline(), new PresetRegistry([])));
        $package->execute(['--config-layer' => $layer, '--output' => $zip], ['interactive' => false]);
        $package->assertCommandIsSuccessful();
        self::assertFileExists($zip);

        $deployer = new FakeDeployer(status: new AppStatus('shop-production', 'Active', 'x.on.aws', 'rev-1'));
        $remove = $project->tester(new RemoveCommand(
            awsFactory: fn (?string $profile, string $region) => new AwsClients($profile, $region, ['handler' => new MockHandler([new Result(['Account' => '111111111111'])]), 'credentials' => false]),
            deployerFactory: fn () => $deployer,
        ));
        $remove->execute(['--config-layer' => $layer, '--profile' => 'p'], ['interactive' => false]);
        self::assertSame(1, $remove->getStatusCode());
        self::assertStringContainsString('Add --yes', self::flat($remove->getDisplay()), 'past the config: remove asks for --yes');
        self::assertNotContains('remove', $deployer->calls);

        $logs = $project->tester(new LogsCommand(awsFactory: fn () => throw new \LogicException('no AWS expected')));
        $logs->execute(['--config-layer' => $layer], ['interactive' => false, 'capture_stderr_separately' => true]);
        self::assertSame(1, $logs->getStatusCode());
        self::assertStringContainsString('No AWS profile selected for "production"', self::flat($logs->getErrorOutput() . $logs->getDisplay()));
    }

    public function test_no_account_lock_prompt_without_arco_php(): void
    {
        $this->project();
        $layer = $this->layer();
        $this->aws->append(new Result(['Account' => '222222222222']));

        $tester = $this->deployTester(new FakeDeployer('rev-1'));
        // Only the creation is confirmed: a lock question would find no answer and abort.
        $tester->setInputs(['yes']);
        $tester->execute(['--config-layer' => $layer, '--profile' => 'acme-prod']);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringNotContainsString('Lock "production" to account', self::flat($tester->getDisplay()));
        self::assertFileDoesNotExist($this->project?->dir . '/arco.php');
    }

    public function test_editing_commands_ignore_the_layer(): void
    {
        $project = $this->project(ProjectFixture::standardConfig());
        // Would conflict with the queue being added if queue:add read it.
        putenv('ARCO_CONFIG_LAYER=' . $this->layer(['queues' => ['emails' => ['timeout' => 99]]], ['name' => 'other']));

        $tester = $project->tester(new QueueAddCommand());
        $tester->execute(['name' => 'emails', '--env' => 'production'], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString("queue('emails')", (string) file_get_contents($project->dir . '/arco.php'));
        self::assertFalse((new QueueAddCommand())->getDefinition()->hasOption('config-layer'));
    }

    public function test_info_table_shows_cloud_source(): void
    {
        $this->project();
        $layer = $this->layer(['settings' => ['timeout' => 90]]);

        $tester = $this->infoTester();
        $tester->execute(['--config-layer' => $layer], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        self::assertMatchesRegularExpression('/timeout\s+90\s+cloud/', $tester->getDisplay());
    }

    public function test_info_table_shows_cloud_for_region_and_permissions_from_the_layer(): void
    {
        $this->project();
        $layer = $this->layer(['permissions' => [['Effect' => 'Allow', 'Action' => 's3:GetObject', 'Resource' => '*']]]);

        $tester = $this->infoTester();
        $tester->execute(['--config-layer' => $layer], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        self::assertMatchesRegularExpression('/region\s+us-east-1\s+cloud *\n/', $tester->getDisplay());
        self::assertMatchesRegularExpression('/permissions\s+1\s+cloud *\n/', $tester->getDisplay());
    }

    public function test_info_table_keeps_app_sources_when_arco_php_has_them(): void
    {
        $this->project(<<<'PHP'
            <?php
            use Multiapi\Arco\Config\App;
            use Multiapi\Arco\Config\Environment;

            return App::make('shop')
                ->region('us-east-1')
                ->permissions([['Effect' => 'Allow', 'Action' => 'sqs:SendMessage', 'Resource' => '*']])
                ->environment('production');
            PHP);
        $layer = $this->layer(['permissions' => [['Effect' => 'Allow', 'Action' => 's3:GetObject', 'Resource' => '*']]]);

        $tester = $this->infoTester();
        $tester->execute(['--config-layer' => $layer], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        self::assertMatchesRegularExpression('/region\s+us-east-1\s+app *\n/', $tester->getDisplay());
        self::assertMatchesRegularExpression('/permissions\s+2\s+app\/environment *\n/', $tester->getDisplay());
    }

    public function test_without_layer_and_arco_php_is_config_not_found(): void
    {
        $this->project();

        $tester = $this->infoTester();
        $tester->execute([], ['interactive' => false]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('No arco.php found at', self::flat($tester->getDisplay()));

        $tester = $this->deployTester(new FakeDeployer('rev-1'));
        $tester->execute(['--profile' => 'acme-prod', '--json' => true], ['capture_stderr_separately' => true]);
        self::assertSame('config.not_found', self::finalLine($tester)['error']['key']);
    }
}
