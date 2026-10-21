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
use Multiapi\Arco\Deploy\DeployException;
use Multiapi\Arco\Deploy\DeployPipeline;
use Multiapi\Arco\Package\Packager;
use Multiapi\Arco\Tests\Support\TempPaths;
use Multiapi\Arco\Tests\Unit\Binary\ElfInspectorTest;
use Multiapi\Arco\Tests\Unit\Build\FakeRunner;
use Multiapi\Arco\Tests\Unit\Deploy\Domains\FakeCloudflare;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** `deploy --json`: NDJSON step events on stdout and one final result line (spec G2). */
final class DeployJsonTest extends TestCase
{
    private const SECRET = 'ssm-secret-value-42';

    private string $project;
    private string $cwd;
    private MockHandler $aws;
    /** @var array<string, string|false> */
    private array $savedEnv = [];
    private float $now = 1792591385.0;

    protected function setUp(): void
    {
        foreach (['AWS_ENDPOINT_URL', 'AWS_IGNORE_CONFIGURED_ENDPOINT_URLS', 'CLOUDFLARE_API_TOKEN'] as $name) {
            $this->savedEnv[$name] = getenv($name);
            putenv($name);
        }
        $this->cwd = (string) getcwd();
        $this->project = TempPaths::path('arco-json-');
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
                ->console()
                ->environment('production', fn (Environment \$env) => \$env
                    ->variables(['APP_ENV' => 'production']));
            PHP);
        chdir($this->project);

        $home = TempPaths::path('arco-home-');
        mkdir("$home/.aws", 0777, true);
        file_put_contents("$home/.aws/config", "[profile acme-prod]\nregion = us-east-1\n");
        file_put_contents("$home/.aws/credentials", "[acme-prod]\naws_access_key_id = test\naws_secret_access_key = test\n");
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
        foreach ($this->savedEnv as $name => $value) {
            putenv($value === false ? $name : "$name=$value");
        }
    }

    /** @param (\Closure(): void)|null $onDeployer runs when the deployer is made, mid-deploy */
    private function tester(FakeDeployer $deployer, bool $verifies = true, ?FakeConsole $console = null, ?FakeCloudflare $cloudflare = null, ?FakeRunner $runner = null, ?\Closure $onDeployer = null): CommandTester
    {
        $command = new DeployCommand(
            awsFactory: fn (?string $profile, string $region) => new AwsClients($profile, $region, ['handler' => $this->aws, 'credentials' => false]),
            pipeline: new DeployPipeline(new Builder($runner ?? new FakeRunner()), new BinaryProvider(new GuzzleDownloader(), sys_get_temp_dir(), \Multiapi\Arco\Binary\RuntimeManifest::load()), new Packager()),
            deployerFactory: function () use ($deployer, $onDeployer) {
                if ($onDeployer !== null) {
                    $onDeployer();
                }
                return $deployer;
            },
            verifierFactory: fn () => new FakeVerifier($verifies, 800),
            presets: new PresetRegistry([]),
            consoleFactory: fn () => $console ?? throw new \LogicException('No console expected.'),
            cloudflareFactory: $cloudflare === null ? null : fn (string $token) => $cloudflare->api($token),
            clock: function (): float {
                $this->now += 0.25;
                return $this->now;
            },
        );
        $app = Application::create();
        $app->addCommands([$command]);
        return new CommandTester($app->find('deploy'));
    }

    /**
     * Runs deploy --json (with a terminal: --json must still never ask) and returns the stdout lines,
     * each checked to be one JSON object with schema 1, the result last and only last.
     *
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    private function deploy(CommandTester $tester, array $options = []): array
    {
        $tester->execute(['--env' => 'production', '--profile' => 'acme-prod', '--json' => true] + $options, ['capture_stderr_separately' => true]);
        return self::lines($tester->getDisplay());
    }

    /** @return list<array<string, mixed>> */
    public static function lines(string $stdout): array
    {
        self::assertStringEndsWith("\n", $stdout, 'NDJSON: every line ends with a newline');
        $lines = [];
        foreach (explode("\n", rtrim($stdout, "\n")) as $line) {
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded, $line);
            self::assertSame(1, $decoded['schema'] ?? null, $line);
            self::assertContains($decoded['type'] ?? null, ['step', 'progress', 'result'], $line);
            if ($decoded['type'] === 'step') {
                self::assertSame(['schema', 'type', 'key', 'status', 'at', 'params'], array_slice(array_keys($decoded), 0, 6), $line);
                self::assertContains($decoded['status'], ['started', 'done', 'warning', 'note'], $line);
                self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $decoded['at']);
                self::assertStringStartsWith('{', (string) json_encode((object) $decoded['params']));
            }
            $lines[] = $decoded;
        }
        $results = array_keys(array_filter($lines, fn (array $l) => $l['type'] === 'result'));
        self::assertSame([count($lines) - 1], $results, 'exactly one result, the last line');
        self::assertSame(['schema', 'type', 'outcome', 'app', 'environment', 'revision', 'url', 'domains', 'rolled_back', 'cold_start_ms', 'error'], array_keys($lines[count($lines) - 1]));
        return $lines;
    }

    /** @param list<array<string, mixed>> $lines @return array<string, mixed> */
    private static function last(array $lines): array
    {
        return $lines[count($lines) - 1];
    }

    /** @param list<array<string, mixed>> $lines @return list<string> */
    private static function keys(array $lines, ?string $status = null): array
    {
        return array_values(array_map(fn (array $l) => (string) $l['key'], array_filter($lines, fn (array $l) => $l['type'] === 'step' && ($status === null || $l['status'] === $status))));
    }

    private function existing(): FakeDeployer
    {
        return new FakeDeployer('rev-1', new AppStatus('shop-production', 'Active', 'abc.on.aws', 'rev-1'), consoleVersion: '7');
    }

    public function test_a_successful_deploy_streams_steps_and_ends_with_the_deployed_result(): void
    {
        $this->aws->append(new Result(['Account' => '222222222222']));
        $before = (string) file_get_contents("$this->project/arco.php");
        $tester = $this->tester($this->existing());

        $lines = $this->deploy($tester);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame([
            'schema' => 1, 'type' => 'result', 'outcome' => 'deployed', 'app' => 'shop', 'environment' => 'production',
            'revision' => 'rev-2', 'url' => 'https://abc.on.aws', 'domains' => [], 'rolled_back' => false, 'cold_start_ms' => 800, 'error' => null,
        ], self::last($lines));
        $started = self::keys($lines, 'started');
        self::assertSame('build.staging', $started[0]);
        self::assertContains('package.writing', $started);
        self::assertContains('deploy.verifying', $started);
        self::assertSame(['deploy.target'], array_slice(self::keys($lines, 'note'), 0, 1));
        self::assertSame(['app' => 'shop', 'environment' => 'production', 'account' => '222222222222', 'profile' => 'acme-prod', 'region' => 'us-east-1'], $lines[0]['params']);
        // Every started step is done, each with its duration.
        self::assertSame($started, self::keys($lines, 'done'));
        foreach (array_filter($lines, fn (array $l) => $l['type'] === 'step' && $l['status'] === 'done') as $done) {
            self::assertIsInt($done['elapsed_ms']);
        }
        $progress = array_values(array_filter($lines, fn (array $l) => $l['type'] === 'progress'));
        self::assertNotSame([], $progress);
        self::assertSame('build.zip', $progress[0]['key']);
        self::assertSame(100, $progress[count($progress) - 1]['percent']);
        // --json never asks: no account-lock question, arco.php unchanged.
        self::assertSame($before, (string) file_get_contents("$this->project/arco.php"));
        self::assertSame('', $tester->getErrorOutput());
    }

    public function test_a_refusal_before_any_step_is_a_single_refused_result_line(): void
    {
        file_put_contents("$this->project/arco.php", str_replace("->variables(['APP_ENV' => 'production'])", "->account('999999999999')", (string) file_get_contents("$this->project/arco.php")));
        $this->aws->append(new Result(['Account' => '222222222222']));
        $deployer = $this->existing();
        $tester = $this->tester($deployer);

        $lines = $this->deploy($tester);

        self::assertSame(1, $tester->getStatusCode());
        self::assertCount(1, $lines);
        $result = self::last($lines);
        self::assertSame(['refused', 'shop', 'production', null, null, [], false, null], [$result['outcome'], $result['app'], $result['environment'], $result['revision'], $result['url'], $result['domains'], $result['rolled_back'], $result['cold_start_ms']]);
        self::assertSame('aws.account_mismatch', $result['error']['key']);
        self::assertStringContainsString('999999999999', $result['error']['message']);
        self::assertSame([], $deployer->calls);
    }

    public function test_an_arco_php_that_cannot_be_loaded_is_refused_without_an_app(): void
    {
        unlink("$this->project/arco.php");
        $tester = $this->tester($this->existing());

        $lines = $this->deploy($tester);

        self::assertSame(1, $tester->getStatusCode());
        self::assertCount(1, $lines);
        self::assertSame(['refused', null, 'production'], [$lines[0]['outcome'], $lines[0]['app'], $lines[0]['environment']]);
        self::assertStringStartsWith('config.', $lines[0]['error']['key']);
    }

    public function test_json_never_prompts_a_missing_profile_is_refused(): void
    {
        $tester = $this->tester($this->existing());
        $tester->execute(['--env' => 'production', '--json' => true], ['capture_stderr_separately' => true]);

        $lines = self::lines($tester->getDisplay());
        self::assertSame(1, $tester->getStatusCode());
        self::assertSame(['refused', 'aws.no_profile_non_interactive'], [self::last($lines)['outcome'], self::last($lines)['error']['key']]);
    }

    public function test_a_first_deploy_without_yes_would_prompt_and_is_refused(): void
    {
        $this->aws->append(new Result(['Account' => '222222222222']));
        $deployer = new FakeDeployer();
        $tester = $this->tester($deployer);

        $lines = $this->deploy($tester);

        self::assertSame(1, $tester->getStatusCode());
        self::assertSame('refused', self::last($lines)['outcome']);
        self::assertSame('deploy.confirm_required', self::last($lines)['error']['key']);
        self::assertSame(['describe'], $deployer->calls);
    }

    public function test_an_ownership_refusal_after_the_build_is_refused(): void
    {
        $this->aws->append(new Result(['Account' => '222222222222']));
        $deployer = $this->existing();
        $deployer->ownershipError = DeployException::make('deploy.not_owned', ['resource' => 'shop-production', 'app' => 'shop', 'environment' => 'staging']);
        $tester = $this->tester($deployer);

        $lines = $this->deploy($tester);

        self::assertSame(1, $tester->getStatusCode());
        self::assertContains('build.staging', self::keys($lines, 'started'));
        $result = self::last($lines);
        self::assertSame(['refused', 'deploy.not_owned', false], [$result['outcome'], $result['error']['key'], $result['rolled_back']]);
        self::assertStringContainsString('staging', $result['error']['message']);
    }

    public function test_a_failed_verification_is_failed_and_rolled_back(): void
    {
        $this->aws->append(new Result(['Account' => '222222222222']));
        $deployer = $this->existing();
        $tester = $this->tester($deployer, verifies: false);

        $lines = $this->deploy($tester);

        self::assertSame(1, $tester->getStatusCode());
        $result = self::last($lines);
        self::assertSame(['failed', true, 'deploy.verify_failed', 'rev-2'], [$result['outcome'], $result['rolled_back'], $result['error']['key'], $result['revision']]);
        self::assertContains('deploy.rolling_back', self::keys($lines, 'started'));
        self::assertContains('rollback:rev-1', $deployer->calls);
    }

    public function test_an_unexpected_error_after_changes_still_ends_with_a_failed_result_and_the_same_exit_code(): void
    {
        $this->aws->append(new Result(['Account' => '222222222222']));
        $deployer = $this->existing();
        $deployer->releaseError = new \RuntimeException('connection reset');
        $tester = $this->tester($deployer);

        $lines = $this->deploy($tester);

        self::assertSame(1, $tester->getStatusCode(), 'what the application returns for an uncaught exception');
        $result = self::last($lines);
        self::assertSame(['failed', 'error.unexpected', 'connection reset'], [$result['outcome'], $result['error']['key'], $result['error']['message']]);
        self::assertStringContainsString('connection reset', $tester->getErrorOutput(), 'the diagnostic goes to stderr');
    }

    public function test_an_aws_error_after_changes_is_failed_with_its_key(): void
    {
        $this->aws->append(new Result(['Account' => '222222222222']));
        $deployer = $this->existing();
        $deployer->releaseError = new \Aws\Exception\AwsException('denied', new \Aws\Command('UpdateFunctionUrlConfig'), ['code' => 'AccessDeniedException', 'message' => 'not authorized']);
        $tester = $this->tester($deployer);

        $lines = $this->deploy($tester);

        self::assertSame(1, $tester->getStatusCode());
        self::assertSame(['failed', 'aws.access_denied'], [self::last($lines)['outcome'], self::last($lines)['error']['key']]);
    }

    public function test_a_failing_deploy_command_keeps_its_exit_code_and_its_output_off_stdout(): void
    {
        $path = "$this->project/arco.php";
        file_put_contents($path, str_replace("->variables(['APP_ENV' => 'production'])", "->variables(['APP_ENV' => 'production'])->deployCommands(['scripts/migrate.php'])", (string) file_get_contents($path)));
        $this->aws->append(new Result(['Account' => '222222222222']));
        $tester = $this->tester($this->existing(), console: new FakeConsole([new CommandResult(3, "SQLSTATE[HY000] connection refused\n")]));

        $lines = $this->deploy($tester);

        self::assertSame(3, $tester->getStatusCode());
        $result = self::last($lines);
        self::assertSame(['failed', 'deploy.command_failed', false], [$result['outcome'], $result['error']['key'], $result['rolled_back']]);
        self::assertContains('deploy.command', self::keys($lines, 'started'));
        self::assertStringNotContainsString('SQLSTATE', $tester->getDisplay());
        self::assertStringContainsString('SQLSTATE[HY000] connection refused', $tester->getErrorOutput());
    }

    public function test_json_works_with_a_prebuilt_package(): void
    {
        $pipeline = new DeployPipeline(new Builder(new FakeRunner()), new BinaryProvider(new GuzzleDownloader(), sys_get_temp_dir(), \Multiapi\Arco\Binary\RuntimeManifest::load()), new Packager());
        $config = \Multiapi\Arco\Config\ConfigResolver::resolve((new \Multiapi\Arco\Config\ConfigLoader())->load("$this->project/arco.php"), 'production');
        [$build, $package] = $pipeline->package($config, new \Multiapi\Arco\Build\PlainPhpPreset(), $this->project, fn () => null);
        $zip = TempPaths::path('arco-prebuilt-', '.zip');
        copy($package->path, $zip);
        $build->cleanup();
        $this->aws->append(new Result(['Account' => '222222222222']));
        $tester = $this->tester($this->existing());

        $lines = $this->deploy($tester, ['--package' => $zip]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame('deployed', self::last($lines)['outcome']);
        $started = self::keys($lines, 'started');
        self::assertSame('deploy.using_package', $started[0]);
        self::assertNotContains('build.staging', $started);
    }

    public function test_a_warning_before_the_deploy_is_a_warning_event(): void
    {
        $this->aws->append(new Result(['Account' => '222222222222']));
        // An unrecognised endpoint is warned about before the client setup.
        putenv('AWS_ENDPOINT_URL=localhost:4566');
        $tester = $this->tester($this->existing());

        $lines = $this->deploy($tester);

        self::assertSame('deploy.endpoint_unrecognised', $lines[0]['key']);
        self::assertSame('warning', $lines[0]['status']);
        self::assertSame(['value' => 'localhost:4566'], $lines[0]['params']);
    }

    public function test_resolved_secret_values_are_never_written(): void
    {
        $path = "$this->project/arco.php";
        file_put_contents($path, str_replace(
            "->variables(['APP_ENV' => 'production'])",
            "->variables(['APP_ENV' => 'production', 'APP_KEY' => 'arco-ssm:/shop/production/app-key'])->deployCommands(['scripts/seed.php " . self::SECRET . "'])",
            (string) file_get_contents($path),
        ));
        $this->aws->append(
            new Result(['Account' => '222222222222']),
            new Result(['Parameters' => [['Name' => '/shop/production/app-key', 'Value' => self::SECRET]], 'InvalidParameters' => []]),
        );
        $tester = $this->tester($this->existing(), console: new FakeConsole([new CommandResult(0, '')]));

        $lines = $this->deploy($tester);

        self::assertSame('deployed', self::last($lines)['outcome']);
        self::assertStringNotContainsString(self::SECRET, $tester->getDisplay());
        $command = array_values(array_filter($lines, fn (array $l) => ($l['key'] ?? null) === 'deploy.command'))[0];
        self::assertSame(['command' => 'scripts/seed.php [redacted]'], $command['params']);
    }

    public function test_a_missing_cloudflare_token_is_refused_before_any_aws_call_and_a_given_token_is_never_written(): void
    {
        file_put_contents("$this->project/arco.php", str_replace(
            "->variables(['APP_ENV' => 'production'])",
            "->domain('arco.acmeapp.io')->dns('cloudflare')",
            (string) file_get_contents("$this->project/arco.php"),
        ));
        $tester = $this->tester($this->existing(), cloudflare: new FakeCloudflare());

        $lines = $this->deploy($tester);

        self::assertSame(1, $tester->getStatusCode());
        self::assertCount(1, $lines);
        self::assertSame(['refused', 'cloudflare.token_missing'], [$lines[0]['outcome'], $lines[0]['error']['key']]);
        self::assertStringContainsString('CLOUDFLARE_API_TOKEN', $lines[0]['error']['message']);

        $cloudflare = (new FakeCloudflare())->queue(FakeCloudflare::json(403, ['success' => false, 'errors' => [['code' => 10000, 'message' => 'Authentication error ' . FakeCloudflare::TOKEN]]]));
        $this->aws->append(new Result(['Account' => '222222222222']));
        $tester = $this->tester($this->existing(), cloudflare: $cloudflare);
        $lines = $this->deploy($tester, ['--cloudflare-token' => FakeCloudflare::TOKEN]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertSame('refused', self::last($lines)['outcome']);
        self::assertStringStartsWith('cloudflare.', self::last($lines)['error']['key']);
        self::assertStringNotContainsString(FakeCloudflare::TOKEN, $tester->getDisplay());
    }

    public function test_a_build_failure_is_failed_not_refused(): void
    {
        file_put_contents("$this->project/composer.json", '{"require":{}}');
        $this->aws->append(new Result(['Account' => '222222222222']));
        $deployer = $this->existing();
        $tester = $this->tester($deployer, runner: new FakeRunner(['install' => 'Your requirements could not be resolved']));

        $lines = $this->deploy($tester);

        self::assertSame(1, $tester->getStatusCode());
        $result = self::last($lines);
        self::assertSame(['failed', 'build.command_failed', false], [$result['outcome'], $result['error']['key'], $result['rolled_back']]);
        self::assertContains('build.composer', self::keys($lines, 'started'));
        self::assertSame(['describe'], $deployer->calls, 'nothing in AWS changed: still failed, the build broke');
    }

    public function test_php_deprecations_and_warnings_go_to_stderr_never_stdout(): void
    {
        $this->aws->append(new Result(['Account' => '222222222222']));
        $tester = $this->tester($this->existing(), onDeployer: function (): void {
            trigger_error('Something old is deprecated', E_USER_DEPRECATED);
            trigger_error('Something looks wrong', E_USER_WARNING);
        });

        // PHPUnit lowers error_reporting while its own handler runs; bin/arco runs with PHP's E_ALL.
        $level = error_reporting(E_ALL);
        try {
            $lines = $this->deploy($tester);
        } finally {
            error_reporting($level);
        }

        self::assertSame('deployed', self::last($lines)['outcome']);
        self::assertStringNotContainsString('deprecated', $tester->getDisplay());
        self::assertStringContainsString('PHP Deprecated: Something old is deprecated', $tester->getErrorOutput());
        self::assertStringContainsString('PHP Warning: Something looks wrong', $tester->getErrorOutput());
    }

    public function test_the_failing_command_output_and_log_tail_on_stderr_are_redacted(): void
    {
        $path = "$this->project/arco.php";
        file_put_contents($path, str_replace(
            "->variables(['APP_ENV' => 'production'])",
            "->variables(['APP_ENV' => 'production', 'APP_KEY' => 'arco-ssm:/shop/production/app-key'])->deployCommands(['scripts/migrate.php'])",
            (string) file_get_contents($path),
        ));
        $this->aws->append(
            new Result(['Account' => '222222222222']),
            new Result(['Parameters' => [['Name' => '/shop/production/app-key', 'Value' => self::SECRET]], 'InvalidParameters' => []]),
        );
        $tester = $this->tester($this->existing(), console: new FakeConsole([new CommandResult(3, 'key is ' . self::SECRET . "\n", null, null, ['tail ' . self::SECRET])]));

        $this->deploy($tester);

        self::assertSame(3, $tester->getStatusCode());
        $stderr = $tester->getErrorOutput();
        self::assertStringNotContainsString(self::SECRET, $stderr);
        self::assertStringContainsString('key is [redacted]', $stderr);
        self::assertStringContainsString('tail [redacted]', $stderr);
    }

    public function test_a_secret_equal_to_the_app_or_environment_name_never_redacts_them(): void
    {
        $path = "$this->project/arco.php";
        file_put_contents($path, str_replace(
            "->variables(['APP_ENV' => 'production'])",
            "->variables(['APP_ENV' => 'production', 'STAGE' => 'arco-ssm:/shop/production/stage'])",
            (string) file_get_contents($path),
        ));
        $this->aws->append(
            new Result(['Account' => '222222222222']),
            new Result(['Parameters' => [['Name' => '/shop/production/stage', 'Value' => 'production']], 'InvalidParameters' => []]),
        );
        $tester = $this->tester($this->existing());

        $lines = $this->deploy($tester);

        $result = self::last($lines);
        self::assertSame(['deployed', 'shop', 'production'], [$result['outcome'], $result['app'], $result['environment']]);
        $staging = array_values(array_filter($lines, fn (array $l) => ($l['key'] ?? null) === 'build.staging'))[0];
        self::assertSame('production', $staging['params']['environment']);
    }
}
