<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Commands;

use Aws\MockHandler;
use Aws\Result;
use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Binary\BinaryProvider;
use Multiapi\Arco\Binary\GuzzleDownloader;
use Multiapi\Arco\Binary\RuntimeManifest;
use Multiapi\Arco\Build\Builder;
use Multiapi\Arco\Build\FrameworkPreset;
use Multiapi\Arco\Build\PresetRegistry;
use Multiapi\Arco\Build\ProcessBuilder;
use Multiapi\Arco\Commands\DeployCommand;
use Multiapi\Arco\Deploy\DeployPipeline;
use Multiapi\Arco\Package\Package;
use Multiapi\Arco\Package\Packager;
use Multiapi\Arco\Tests\Unit\Build\FakeRunner;
use Multiapi\Arco\Tests\Support\TempPaths;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** A Go project (go.mod, no composer.json, vendor/ or arco.php) through `deploy --json`, in process. */
final class GoProjectDeployTest extends TestCase
{
    private ?ProjectFixture $project = null;
    private ?FakeRunner $runner = null;
    /** the runner of the PHP builder (and so of ConfigLoader's neighbours): a process app never uses it */
    private ?FakeRunner $phpRunner = null;
    private MockHandler $aws;
    private bool $zipChecked = false;
    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        $this->aws = new MockHandler([new Result(['Account' => '111111111111'])]);
        foreach (['AWS_ENDPOINT_URL', 'AWS_IGNORE_CONFIGURED_ENDPOINT_URLS', 'ARCO_CONFIG_LAYER'] as $name) {
            $this->savedEnv[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        $this->project?->restore();
        foreach ($this->savedEnv as $name => $value) {
            putenv($value === false ? $name : "$name=$value");
        }
    }

    private function project(bool $withArcoJson): ProjectFixture
    {
        $this->project = new ProjectFixture();
        $source = __DIR__ . '/fixtures/go-app';
        foreach (['go.mod', 'main.go', ...($withArcoJson ? ['arco.json'] : [])] as $file) {
            copy("$source/$file", "{$this->project->dir}/$file");
        }
        mkdir("{$this->project->dir}/cmd");

        return $this->project;
    }

    /** @return array<string, mixed> */
    private function layer(bool $withQueue): array
    {
        return [
            'schema' => 1,
            'name' => 'shop',
            'region' => 'us-east-1',
            'environments' => ['production' => [
                'settings' => ['account' => '111111111111', 'accessLog' => true],
                ...($withQueue ? ['queues' => ['emails' => (object) []], 'schedules' => ['cleanup' => ['every' => '1h']]] : []),
            ]],
        ];
    }

    private function tester(FakeDeployer $deployer): CommandTester
    {
        $this->runner = new FakeRunner(onRun: function (array $command): void {
            if (($command[1] ?? '') === 'build') {
                file_put_contents($command[4], 'ELF');
            }
        });

        return ($this->project ?? throw new \LogicException('No project.'))->tester(new DeployCommand(
            awsFactory: fn (?string $profile, string $region) => new AwsClients($profile, $region, ['handler' => $this->aws, 'credentials' => false]),
            pipeline: new DeployPipeline(
                new Builder($this->phpRunner = new FakeRunner()),
                new BinaryProvider(new GuzzleDownloader(), TempPaths::path('arco-cache-'), RuntimeManifest::load()),
                new Packager(),
                fn () => throw new \LogicException('process apps have no Laravel schedule'),
                new ProcessBuilder($this->runner, 'go', fn (): bool => true),
            ),
            deployerFactory: fn () => $deployer,
            verifierFactory: fn () => new FakeVerifier(true, 800),
            presets: new PresetRegistry([new class () implements FrameworkPreset {
                public function name(): string { throw new \LogicException('PresetRegistry was consulted'); }
                public function detects(string $projectDir): bool { throw new \LogicException('PresetRegistry was consulted'); }
                public function prepare(string $appDir): void { throw new \LogicException('PresetRegistry was consulted'); }
                public function buildSteps(): array { throw new \LogicException('PresetRegistry was consulted'); }
                public function workerScript(): ?string { throw new \LogicException('PresetRegistry was consulted'); }
                public function publicDir(): string { throw new \LogicException('PresetRegistry was consulted'); }
                public function defaultVariables(): array { throw new \LogicException('PresetRegistry was consulted'); }
                public function excludeRules(): array { throw new \LogicException('PresetRegistry was consulted'); }
                public function healthPath(): string { throw new \LogicException('PresetRegistry was consulted'); }
            }]),
            consoleFactory: fn () => throw new \LogicException('No console expected.'),
        ));
    }

    /** @return array<string, mixed> the final line of deploy --json */
    private function deploy(CommandTester $tester, string $layerPath): array
    {
        $tester->execute(['--config-layer' => $layerPath, '--env' => 'production', '--profile' => 'p', '--json' => true, '--yes' => true], ['capture_stderr_separately' => true]);
        $lines = DeployJsonTest::lines($tester->getDisplay());

        return $lines[count($lines) - 1];
    }

    /** Checks the zip while the deployer still has it: the pipeline deletes it afterwards. */
    private function assertZip(Package $package): void
    {
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($package->path));
        foreach (['index.js', 'worker.js', 'app/bin/app', 'arco-runtime.json'] as $entry) {
            self::assertNotFalse($zip->locateName($entry), $entry);
        }
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }
        self::assertNotEmpty(array_filter($names, fn (string $n) => str_starts_with($n, 'lib/')), 'lib/');
        self::assertSame([], array_values(array_filter($names, fn (string $n) => str_contains($n, 'frankenphp') || str_starts_with($n, 'app/vendor/') || str_starts_with($n, 'vendor/'))));
        self::assertSame(
            ['kind' => 'process', 'command' => ['bin/app'], 'healthPath' => '/', 'accessLog' => true, 'arcoVersion' => \Multiapi\Arco\Arco::version()],
            json_decode((string) $zip->getFromName('arco-runtime.json'), true),
        );
        $zip->close();
        $this->zipChecked = true;
    }

    private function assertProcessDeploy(FakeDeployer $deployer): void
    {
        self::assertTrue($this->zipChecked, 'the deployer was given a package');
        self::assertSame('https://sqs.us-east-1.amazonaws.com/111111111111/shop-production-emails', $deployer->variables['ARCO_QUEUE_EMAILS_URL'] ?? null);
        foreach (($this->runner ?? throw new \LogicException('No runner.'))->calls as $call) {
            self::assertStringNotContainsString('load-config.php', implode(' ', $call['command']));
        }
        self::assertSame([], $this->phpRunner?->calls, 'the PHP builder ran nothing');
        self::assertSame('go', $deployer->preparedConfig?->process?->language);
        $schedules = $deployer->preparedConfig->schedules;
        self::assertSame(['cleanup'], array_map(fn ($s) => $s->name, $schedules));
        self::assertSame('1h', $schedules[0]->every);
        self::assertNull($schedules[0]->route, 'the default route /_arco/schedule/cleanup is applied by the runtime');
    }

    public function test_an_arco_json_go_project_deploys_without_composer_json(): void
    {
        $project = $this->project(true);
        $layerPath = $project->layer($this->layer(false));
        self::assertFileDoesNotExist("$project->dir/composer.json");
        self::assertFileDoesNotExist("$project->dir/vendor");
        $deployer = new FakeDeployer('rev-1');
        $deployer->onPrepare = fn (Package $package) => $this->assertZip($package);

        $tester = $this->tester($deployer);
        $result = $this->deploy($tester, $layerPath);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame('result', $result['type']);
        $this->assertProcessDeploy($deployer);
    }

    public function test_a_go_mod_alone_with_the_layer_is_detected_as_a_go_process_app(): void
    {
        $project = $this->project(false);
        $layerPath = $project->layer($this->layer(true));
        self::assertFileDoesNotExist("$project->dir/arco.json");
        $deployer = new FakeDeployer('rev-1');
        $deployer->onPrepare = fn (Package $package) => $this->assertZip($package);

        $tester = $this->tester($deployer);
        $result = $this->deploy($tester, $layerPath);

        self::assertSame('result', $result['type']);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertProcessDeploy($deployer);
    }
}
