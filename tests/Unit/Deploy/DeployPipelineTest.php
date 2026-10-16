<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy;

use Multiapi\Arco\Binary\BinaryProvider;
use Multiapi\Arco\Build\Builder;
use Multiapi\Arco\Build\PlainPhpPreset;
use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Config\Environment;
use Multiapi\Arco\Config\Runtime;
use Multiapi\Arco\Console\CommandResult;
use Multiapi\Arco\Deploy\CommandPlan;
use Multiapi\Arco\Deploy\ConsoleFunction;
use Multiapi\Arco\Deploy\ConsoleRetriesNotDisabled;
use Multiapi\Arco\Deploy\DeployCommandFailed;
use Multiapi\Arco\Deploy\DeployException;
use Multiapi\Arco\Deploy\DeployPipeline;
use Multiapi\Arco\Deploy\DeployResult;
use Multiapi\Arco\Deploy\VerificationResult;
use Multiapi\Arco\Deploy\Verifier;
use Multiapi\Arco\Package\Packager;
use Multiapi\Arco\Tests\Unit\Binary\ElfInspectorTest;
use Multiapi\Arco\Tests\Unit\Build\FakeRunner;
use Multiapi\Arco\Tests\Unit\Commands\FakeConsole;
use Multiapi\Arco\Tests\Unit\Commands\FakeDeployer;
use Multiapi\Arco\Tests\Unit\Commands\FakeVerifier;
use PHPUnit\Framework\TestCase;

final class DeployPipelineTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/arco-pipe-' . bin2hex(random_bytes(4));
        mkdir("$this->project/public", 0777, true);
        file_put_contents("$this->project/public/index.php", '<?php echo "ok";');
    }

    private function config(bool $domains = false): AppConfig
    {
        return ConfigResolver::resolve(
            App::make('shop')->region('us-east-1')
                ->domain($domains ? ['shop.example.com'] : [])
                ->runtime(fn (Runtime $r) => $r->binary(ElfInspectorTest::fakeElf(0xB7)))
                ->variables(['LOG_CHANNEL' => 'syslog'])
                ->environment('production', fn (Environment $e) => $e->variables(['APP_ENV' => 'production']))
                ->toArray(),
            'production',
        );
    }

    private function pipeline(): DeployPipeline
    {
        return new DeployPipeline(new Builder(new FakeRunner()), new BinaryProvider(new \Multiapi\Arco\Binary\GuzzleDownloader(), sys_get_temp_dir(), \Multiapi\Arco\Binary\RuntimeManifest::load()), new Packager());
    }

    public function test_successful_deploy_merges_variables_and_cleans_up(): void
    {
        $deployer = new FakeDeployer('rev-1');
        $steps = [];
        $preset = new class extends PlainPhpPreset {
            public function defaultVariables(): array { return ['LOG_CHANNEL' => 'stderr', 'SESSION_DRIVER' => 'cookie']; }
        };

        $outcome = $this->pipeline()->deploy($this->config(), $preset, $this->project, $deployer, new FakeVerifier(true), function (string $key) use (&$steps): void { $steps[] = $key; });

        self::assertTrue($outcome->verification->ok);
        self::assertFalse($outcome->rolledBack);
        self::assertSame(['prepare', 'release', 'prune'], $deployer->calls);
        self::assertSame([
            'LOG_CHANNEL' => 'syslog',
            'SESSION_DRIVER' => 'cookie',
            'APP_ENV' => 'production',
            'ARCO' => '1',
            'ARCO_APP' => 'shop',
            'ARCO_ENV' => 'production',
        ], $deployer->variables);
        self::assertContains('package.ready', $steps);
        self::assertSame([], glob(sys_get_temp_dir() . '/arco-build-*/package.zip') ?: [], 'staging dirs are removed');
    }

    public function test_failed_verification_rolls_back_to_previous_revision(): void
    {
        $deployer = new FakeDeployer('rev-1');

        $outcome = $this->pipeline()->deploy($this->config(), new PlainPhpPreset(), $this->project, $deployer, new FakeVerifier(false), fn () => null);

        self::assertTrue($outcome->rolledBack);
        self::assertSame(['prepare', 'release', 'rollback:rev-1'], $deployer->calls);
    }

    public function test_verifier_exception_rolls_back_then_rethrows(): void
    {
        $deployer = new FakeDeployer('rev-1');
        $verifier = new class implements Verifier {
            public function verify(AppConfig $config, DeployResult $result, string $healthPath, int $sinceMs, ?callable $onStep = null): VerificationResult
            {
                throw new \RuntimeException('CloudWatch Logs throttled');
            }

            public function coldStart(AppConfig $config, DeployResult $result, int $sinceMs, VerificationResult $verification, ?callable $onStep = null): VerificationResult
            {
                return $verification;
            }
        };

        try {
            $this->pipeline()->deploy($this->config(), new PlainPhpPreset(), $this->project, $deployer, $verifier, fn () => null);
            self::fail('The verifier exception must propagate.');
        } catch (\RuntimeException $e) {
            self::assertSame('CloudWatch Logs throttled', $e->getMessage());
        }
        self::assertSame(['prepare', 'release', 'rollback:rev-1'], $deployer->calls);
    }

    public function test_failed_first_deploy_has_nothing_to_roll_back(): void
    {
        $deployer = new FakeDeployer(null);

        $outcome = $this->pipeline()->deploy($this->config(), new PlainPhpPreset(), $this->project, $deployer, new FakeVerifier(false), fn () => null);

        self::assertFalse($outcome->rolledBack);
        self::assertSame(['prepare', 'release'], $deployer->calls);
    }

    public function test_domains_are_attached_after_a_verified_deploy_with_the_presets_cached_paths(): void
    {
        $deployer = new FakeDeployer('rev-1');
        $preset = new class extends PlainPhpPreset {
            public function cachedPaths(): array { return ['/build/*']; }
        };

        $outcome = $this->pipeline()->deploy($this->config(domains: true), $preset, $this->project, $deployer, new FakeVerifier(true), fn () => null);

        self::assertSame(['prepare', 'release', 'domains:/build/*', 'prune'], $deployer->calls);
        self::assertSame(['shop.example.com'], $outcome->result->domains);
    }

    public function test_domains_are_not_attached_when_verification_fails(): void
    {
        $deployer = new FakeDeployer('rev-1');

        $this->pipeline()->deploy($this->config(domains: true), new PlainPhpPreset(), $this->project, $deployer, new FakeVerifier(false), fn () => null);

        self::assertSame(['prepare', 'release', 'rollback:rev-1'], $deployer->calls);
    }

    public function test_a_prebuilt_package_is_deployed_without_building(): void
    {
        [$build, $built] = $this->pipeline()->package($this->config(), new PlainPhpPreset(), $this->project, fn () => null);
        $zip = sys_get_temp_dir() . '/arco-prebuilt-' . bin2hex(random_bytes(4)) . '.zip';
        copy($built->path, $zip);
        $build->cleanup();
        $package = \Multiapi\Arco\Package\PackageReader::open($zip);

        $before = glob(sys_get_temp_dir() . '/arco-build-*') ?: [];
        $deployer = new FakeDeployer('rev-1');
        $steps = [];
        $outcome = $this->pipeline()->deployPackage($this->config(domains: true), new PlainPhpPreset(), $package, $deployer, new FakeVerifier(true), function (string $key) use (&$steps): void { $steps[] = $key; });

        self::assertTrue($outcome->verification->ok);
        self::assertSame(['prepare', 'release', 'domains:', 'prune'], $deployer->calls);
        self::assertSame($zip, $deployer->package?->path);
        self::assertSame($built->hash, $deployer->package->hash);
        self::assertSame('production', $deployer->variables['ARCO_ENV']);
        self::assertNotContains('build.staging', $steps);
        self::assertNotContains('package.ready', $steps);
        self::assertContains('deploy.verifying', $steps);
        self::assertSame([], array_diff(glob(sys_get_temp_dir() . '/arco-build-*') ?: [], $before), 'nothing is staged');
        self::assertFileExists($zip, 'the user\'s package is left in place');
    }

    public function test_the_cold_start_is_read_last_after_the_custom_domains(): void
    {
        $deployer = new FakeDeployer('rev-1');
        $verifier = new FakeVerifier(true, 950, function () use ($deployer): void { $deployer->calls[] = 'coldStart'; });

        $outcome = $this->pipeline()->deploy($this->config(domains: true), new PlainPhpPreset(), $this->project, $deployer, $verifier, fn () => null);

        self::assertSame(['prepare', 'release', 'domains:', 'coldStart', 'prune'], $deployer->calls);
        self::assertSame(950, $outcome->verification->initDurationMs);
        self::assertTrue($outcome->verification->ok);
    }

    public function test_a_failing_cold_start_lookup_never_fails_a_finished_deploy(): void
    {
        $deployer = new FakeDeployer('rev-1');
        $verifier = new FakeVerifier(true, 950, function (): void { throw new \RuntimeException('CloudWatch Logs throttled'); });

        $outcome = $this->pipeline()->deploy($this->config(), new PlainPhpPreset(), $this->project, $deployer, $verifier, fn () => null);

        self::assertTrue($outcome->verification->ok);
        self::assertNull($outcome->verification->initDurationMs);
        self::assertSame(['prepare', 'release', 'prune'], $deployer->calls);
    }

    public function test_a_failed_verification_skips_the_cold_start_lookup(): void
    {
        $verifier = new FakeVerifier(false);

        $this->pipeline()->deploy($this->config(), new PlainPhpPreset(), $this->project, new FakeDeployer('rev-1'), $verifier, fn () => null);

        self::assertSame([], $verifier->calls);
    }

    public function test_the_console_live_alias_moves_only_after_a_verified_deploy(): void
    {
        $deployer = new FakeDeployer('rev-1', consoleVersion: '7', previousConsoleVersion: '6');
        $verifier = new FakeVerifier(true, 950, function () use ($deployer): void { $deployer->calls[] = 'coldStart'; });

        $this->pipeline()->deploy($this->config(domains: true), new PlainPhpPreset(), $this->project, $deployer, $verifier, fn () => null);

        self::assertSame(['prepare', 'release', 'live:7', 'domains:', 'coldStart', 'prune'], $deployer->calls);
    }

    public function test_a_rollback_leaves_the_console_live_alias_where_it_was(): void
    {
        $deployer = new FakeDeployer('rev-1', consoleVersion: '7', previousConsoleVersion: '6');

        $outcome = $this->pipeline()->deploy($this->config(), new PlainPhpPreset(), $this->project, $deployer, new FakeVerifier(false), fn () => null);

        self::assertTrue($outcome->rolledBack);
        self::assertSame(['prepare', 'release', 'rollback:rev-1'], $deployer->calls, 'live never moved, so nothing to restore');
    }

    public function test_a_failed_first_deploy_never_points_the_console_live_alias(): void
    {
        $deployer = new FakeDeployer(null, consoleVersion: '1');

        $this->pipeline()->deploy($this->config(), new PlainPhpPreset(), $this->project, $deployer, new FakeVerifier(false), fn () => null);

        self::assertSame(['prepare', 'release'], $deployer->calls);
    }

    public function test_the_console_gets_a_zip_without_the_binary(): void
    {
        $deployer = new FakeDeployer('rev-1');
        $before = glob(sys_get_temp_dir() . '/arco-console-*') ?: [];

        $this->pipeline()->deploy($this->config(), new PlainPhpPreset(), $this->project, $deployer, new FakeVerifier(true), fn () => null);

        self::assertNotNull($deployer->console);
        self::assertStringStartsWith('runtime/', (string) $deployer->console?->download?->key);
        self::assertSame([], array_diff(glob(sys_get_temp_dir() . '/arco-console-*') ?: [], $before), 'the derived files are removed after the deploy');
    }

    /** A web zip whose console part claims $bytes unzipped (the 3-byte binary is removed from it). */
    public static function zipClaiming(int $bytes): \Multiapi\Arco\Package\Package
    {
        $path = sys_get_temp_dir() . '/arco-big-' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('bin/frankenphp', 'ELF');
        $zip->addFromString('app/index.php', '<?php');
        $zip->close();
        return new \Multiapi\Arco\Package\Package($path, str_repeat('d', 64), $bytes + 3);
    }

    public function test_an_oversized_console_zip_skips_the_console_function(): void
    {
        $deployer = new FakeDeployer('rev-1');
        $steps = [];

        $outcome = $this->pipeline()->deployPackage($this->config(), new PlainPhpPreset(), self::zipClaiming(\Multiapi\Arco\Deploy\ConsoleFunction::MAX_UNZIPPED_BYTES + 1), $deployer, new FakeVerifier(true), function (string $key) use (&$steps): void { $steps[] = $key; });

        self::assertTrue($outcome->verification->ok, 'the web function still deploys');
        self::assertNull($deployer->console);
        self::assertContains('deploy.console_skipped_size', $steps);
        self::assertSame(['prepare', 'release', 'prune'], $deployer->calls);
    }

    public function test_pruning_runs_last_and_only_after_a_verified_deploy(): void
    {
        $deployer = new FakeDeployer('rev-1', consoleVersion: '7', previousConsoleVersion: '6');
        $this->pipeline()->deploy($this->config(), new PlainPhpPreset(), $this->project, $deployer, new FakeVerifier(true), fn () => null);
        self::assertSame(['prepare', 'release', 'live:7', 'prune'], $deployer->calls);

        $deployer = new FakeDeployer('rev-1', consoleVersion: '7', previousConsoleVersion: '6');
        $this->pipeline()->deploy($this->config(), new PlainPhpPreset(), $this->project, $deployer, new FakeVerifier(false), fn () => null);
        self::assertNotContains('prune', $deployer->calls, 'a failed verification and its rollback never prune (a verifier exception: test_verifier_exception_rolls_back_then_rethrows)');
    }

    public function test_a_refused_console_alias_move_after_a_verified_shift_is_a_warning_and_the_deploy_goes_on(): void
    {
        $deployer = new FakeDeployer('rev-1', consoleVersion: '7', previousConsoleVersion: '6');
        $deployer->activateError = new \RuntimeException('not authorized to perform: lambda:UpdateAlias');
        $verifier = new FakeVerifier(true);
        $steps = [];

        $outcome = $this->pipeline()->deploy($this->config(domains: true), new PlainPhpPreset(), $this->project, $deployer, $verifier, function (string $key, array $params) use (&$steps): void { $steps[$key] = $params; });

        self::assertTrue($outcome->verification->ok);
        self::assertFalse($outcome->rolledBack);
        self::assertSame(['prepare', 'release', 'live:7', 'domains:', 'prune'], $deployer->calls);
        self::assertSame(['coldStart'], $verifier->calls);
        self::assertSame([
            'revision' => 'rev-2',
            'function' => 'shop-production-console',
            'version' => '7',
            'error' => 'not authorized to perform: lambda:UpdateAlias',
        ], $steps['deploy.console_not_activated'] ?? null);
    }

    public function test_failing_retries_after_the_alias_moved_get_their_own_warning(): void
    {
        $deployer = new FakeDeployer('rev-1', consoleVersion: '7', previousConsoleVersion: '6');
        $deployer->activateError = new ConsoleRetriesNotDisabled('not authorized to perform: lambda:PutFunctionEventInvokeConfig');
        $steps = [];

        $outcome = $this->pipeline()->deploy($this->config(), new PlainPhpPreset(), $this->project, $deployer, new FakeVerifier(true), function (string $key, array $params) use (&$steps): void { $steps[$key] = $params; });

        self::assertTrue($outcome->verification->ok);
        self::assertArrayNotHasKey('deploy.console_not_activated', $steps);
        self::assertSame([
            'function' => 'shop-production-console',
            'version' => '7',
            'error' => 'not authorized to perform: lambda:PutFunctionEventInvokeConfig',
        ], $steps['deploy.console_retries_not_disabled'] ?? null);
        self::assertSame(['prepare', 'release', 'live:7', 'prune'], $deployer->calls);
    }

    public function test_a_refused_prune_is_a_warning_on_a_successful_deploy(): void
    {
        $deployer = new FakeDeployer('rev-1', consoleVersion: '7', previousConsoleVersion: '6');
        $deployer->pruneWarning = 'deploy.prune_versions_failed';
        $steps = [];

        $outcome = $this->pipeline()->deploy($this->config(), new PlainPhpPreset(), $this->project, $deployer, new FakeVerifier(true), function (string $key) use (&$steps): void { $steps[] = $key; });

        self::assertTrue($outcome->verification->ok);
        self::assertFalse($outcome->rolledBack);
        self::assertSame('deploy.prune_versions_failed', end($steps));
    }

    /**
     * @param list<CommandResult> $results
     * @param list<string> $commands
     */
    private function plan(FakeDeployer $deployer, array $results, array $commands = ['migrate --force', 'config:cache']): CommandPlan
    {
        $console = new FakeConsole($results, function (string $call) use ($deployer): void { $deployer->calls[] = $call; });
        return new CommandPlan($commands, $console, fn () => null);
    }

    public function test_deploy_commands_run_on_the_new_version_before_traffic_moves(): void
    {
        $deployer = new FakeDeployer('rev-1', consoleVersion: '7', previousConsoleVersion: '6');

        $outcome = $this->pipeline()->deploy($this->config(), new PlainPhpPreset(), $this->project, $deployer, new FakeVerifier(true), fn () => null, $this->plan($deployer, [new CommandResult(0, ''), new CommandResult(0, '')]));

        self::assertSame(['prepare', 'console:migrate --force@7', 'console:config:cache@7', 'release', 'live:7', 'prune'], $deployer->calls);
        self::assertSame(['migrate --force', 'config:cache'], $outcome->commandsRan);
    }

    public function test_each_result_is_handed_over_before_the_next_command_runs(): void
    {
        $deployer = new FakeDeployer('rev-1', consoleVersion: '7');
        $console = new FakeConsole([new CommandResult(0, "one\n"), new CommandResult(0, "two\n")], function (string $call) use ($deployer): void { $deployer->calls[] = $call; });
        $plan = new CommandPlan(['a', 'b'], $console, function (string $command, CommandResult $result) use ($deployer): void { $deployer->calls[] = "result:$command:" . trim($result->output); });

        $this->pipeline()->deploy($this->config(), new PlainPhpPreset(), $this->project, $deployer, new FakeVerifier(true), fn () => null, $plan);

        self::assertSame(['prepare', 'console:a@7', 'result:a:one', 'console:b@7', 'result:b:two', 'release', 'live:7', 'prune'], $deployer->calls);
    }

    public function test_a_failing_command_stops_the_deploy_before_traffic(): void
    {
        $deployer = new FakeDeployer('rev-1', consoleVersion: '7');

        try {
            $this->pipeline()->deploy($this->config(), new PlainPhpPreset(), $this->project, $deployer, new FakeVerifier(true), fn () => null, $this->plan($deployer, [new CommandResult(1, 'SQLSTATE[HY000]')]));
            self::fail('The failing command must stop the deploy.');
        } catch (DeployCommandFailed $e) {
            self::assertSame('migrate --force', $e->command);
            self::assertSame(1, $e->result->exitCode);
            self::assertSame(['migrate --force'], $e->ran);
        }
        self::assertSame(['prepare', 'console:migrate --force@7'], $deployer->calls, 'no release, no live alias, no prune');
    }

    public function test_a_crashed_command_also_stops_the_deploy(): void
    {
        $deployer = new FakeDeployer('rev-1', consoleVersion: '7');

        try {
            $this->pipeline()->deploy($this->config(), new PlainPhpPreset(), $this->project, $deployer, new FakeVerifier(true), fn () => null, $this->plan($deployer, [new CommandResult(0, '', 'Sandbox.Timedout', 'Task timed out')]));
            self::fail('The crashed command must stop the deploy.');
        } catch (DeployCommandFailed $e) {
            self::assertTrue($e->result->crashed());
        }
        self::assertSame(['prepare', 'console:migrate --force@7'], $deployer->calls);
    }

    public function test_an_invoke_error_stops_the_deploy_and_counts_the_command_as_possibly_run(): void
    {
        $deployer = new FakeDeployer('rev-1', consoleVersion: '7');
        $error = new \RuntimeException('cURL error 28: Operation timed out');

        try {
            $this->pipeline()->deploy($this->config(), new PlainPhpPreset(), $this->project, $deployer, new FakeVerifier(true), fn () => null, $this->plan($deployer, [new CommandResult(0, ''), $error]));
            self::fail('An invoke error must stop the deploy.');
        } catch (DeployCommandFailed $e) {
            self::assertSame('config:cache', $e->command);
            self::assertNull($e->result, 'the outcome is unknown');
            self::assertSame(['migrate --force', 'config:cache'], $e->ran, 'the interrupted command may have run');
            self::assertSame($error, $e->getPrevious());
        }
        self::assertSame(['prepare', 'console:migrate --force@7', 'console:config:cache@7'], $deployer->calls, 'no release, no live alias, no prune');
    }

    public function test_a_rollback_after_deploy_commands_reports_them(): void
    {
        $deployer = new FakeDeployer('rev-1', consoleVersion: '7', previousConsoleVersion: '6');

        $outcome = $this->pipeline()->deploy($this->config(), new PlainPhpPreset(), $this->project, $deployer, new FakeVerifier(false), fn () => null, $this->plan($deployer, [new CommandResult(0, '')], ['migrate --force']));

        self::assertTrue($outcome->rolledBack);
        self::assertSame(['migrate --force'], $outcome->commandsRan);
        self::assertSame(['prepare', 'console:migrate --force@7', 'release', 'rollback:rev-1'], $deployer->calls, 'web-only rollback: live never moved');
    }

    public function test_commands_with_a_package_over_the_console_limit_fail_before_any_change(): void
    {
        $deployer = new FakeDeployer('rev-1', consoleVersion: '7');

        try {
            // The console zip (web zip minus the binary) is over the limit even though the binary is not counted.
            $this->pipeline()->deployPackage($this->config(), new PlainPhpPreset(), self::zipClaiming(ConsoleFunction::MAX_UNZIPPED_BYTES + 1), $deployer, new FakeVerifier(true), fn () => null, $this->plan($deployer, []));
            self::fail('Commands cannot run without the console function.');
        } catch (DeployException $e) {
            self::assertSame('deploy.commands_package_too_large', $e->key);
        }
        self::assertSame([], $deployer->calls);
    }

    public function test_an_empty_plan_with_an_oversized_console_still_deploys(): void
    {
        $deployer = new FakeDeployer('rev-1', consoleVersion: '7');
        $steps = [];

        $outcome = $this->pipeline()->deployPackage($this->config(), new PlainPhpPreset(), self::zipClaiming(ConsoleFunction::MAX_UNZIPPED_BYTES + 1), $deployer, new FakeVerifier(true), function (string $key) use (&$steps): void { $steps[] = $key; }, $this->plan($deployer, [], []));

        self::assertTrue($outcome->verification->ok);
        self::assertContains('deploy.console_skipped_size', $steps);
    }

    public function test_commands_without_a_console_version_stop_before_traffic(): void
    {
        $deployer = new FakeDeployer('rev-1', consoleVersion: null);

        try {
            $this->pipeline()->deploy($this->config(), new PlainPhpPreset(), $this->project, $deployer, new FakeVerifier(true), fn () => null, $this->plan($deployer, []));
            self::fail('Commands need the console version of this deploy.');
        } catch (DeployException $e) {
            self::assertSame('deploy.commands_without_console', $e->key);
        }
        self::assertSame(['prepare'], $deployer->calls);
    }
}
