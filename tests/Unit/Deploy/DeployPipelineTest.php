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
use Multiapi\Arco\Deploy\DeployPipeline;
use Multiapi\Arco\Deploy\DeployResult;
use Multiapi\Arco\Deploy\VerificationResult;
use Multiapi\Arco\Deploy\Verifier;
use Multiapi\Arco\Package\Packager;
use Multiapi\Arco\Tests\Unit\Binary\ElfInspectorTest;
use Multiapi\Arco\Tests\Unit\Build\FakeRunner;
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
        self::assertSame(['deploy'], $deployer->calls);
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
        self::assertSame(['deploy', 'rollback:rev-1'], $deployer->calls);
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
        self::assertSame(['deploy', 'rollback:rev-1'], $deployer->calls);
    }

    public function test_failed_first_deploy_has_nothing_to_roll_back(): void
    {
        $deployer = new FakeDeployer(null);

        $outcome = $this->pipeline()->deploy($this->config(), new PlainPhpPreset(), $this->project, $deployer, new FakeVerifier(false), fn () => null);

        self::assertFalse($outcome->rolledBack);
        self::assertSame(['deploy'], $deployer->calls);
    }

    public function test_domains_are_attached_after_a_verified_deploy_with_the_presets_cached_paths(): void
    {
        $deployer = new FakeDeployer('rev-1');
        $preset = new class extends PlainPhpPreset {
            public function cachedPaths(): array { return ['/build/*']; }
        };

        $outcome = $this->pipeline()->deploy($this->config(domains: true), $preset, $this->project, $deployer, new FakeVerifier(true), fn () => null);

        self::assertSame(['deploy', 'domains:/build/*'], $deployer->calls);
        self::assertSame(['shop.example.com'], $outcome->result->domains);
    }

    public function test_domains_are_not_attached_when_verification_fails(): void
    {
        $deployer = new FakeDeployer('rev-1');

        $this->pipeline()->deploy($this->config(domains: true), new PlainPhpPreset(), $this->project, $deployer, new FakeVerifier(false), fn () => null);

        self::assertSame(['deploy', 'rollback:rev-1'], $deployer->calls);
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
        self::assertSame(['deploy', 'domains:'], $deployer->calls);
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

        self::assertSame(['deploy', 'domains:', 'coldStart'], $deployer->calls);
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
        self::assertSame(['deploy'], $deployer->calls);
    }

    public function test_a_failed_verification_skips_the_cold_start_lookup(): void
    {
        $verifier = new FakeVerifier(false);

        $this->pipeline()->deploy($this->config(), new PlainPhpPreset(), $this->project, new FakeDeployer('rev-1'), $verifier, fn () => null);

        self::assertSame([], $verifier->calls);
    }
}
