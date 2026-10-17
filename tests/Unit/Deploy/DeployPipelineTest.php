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
use Multiapi\Arco\Tests\Support\TempPaths;
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
        $this->project = TempPaths::path('arco-pipe-');
        mkdir("$this->project/public", 0777, true);
        file_put_contents("$this->project/public/index.php", '<?php echo "ok";');
        file_put_contents("$this->project/composer.json", '{}');
    }

    /** Stands in for `composer install`: writes what a queue worker needs (DeployPipeline::QUEUE_PACKAGES), minus $this->withoutVendor. */
    private function composerInstall(): \Closure
    {
        return function (array $command, string $cwd): void {
            if (($command[1] ?? '') !== 'install') {
                return;
            }
            foreach (array_diff(self::QUEUE_FILES, $this->withoutVendor) as $file) {
                @mkdir(dirname("$cwd/$file"), 0777, true);
                file_put_contents("$cwd/$file", '<?php');
            }
        };
    }

    /** @var list<string> */
    private array $withoutVendor = [];

    private const QUEUE_FILES = ['vendor/multiapi/arco-laravel/src/Console/WorkCommand.php', 'vendor/aws/aws-sdk-php/src/Sdk.php'];

    /** $console null: never set, as in an arco.php written before the console was opt-in. */
    private function config(bool $domains = false, ?bool $console = true): AppConfig
    {
        $app = App::make('shop');
        if ($console !== null) {
            $app->console($console);
        }
        return ConfigResolver::resolve(
            $app->region('us-east-1')
                ->domain($domains ? ['shop.example.com'] : [])
                ->runtime(fn (Runtime $r) => $r->binary(ElfInspectorTest::fakeElf(0xB7)))
                ->variables(['LOG_CHANNEL' => 'syslog'])
                ->environment('production', fn (Environment $e) => $e->variables(['APP_ENV' => 'production']))
                ->toArray(),
            'production',
        );
    }

    private function pipeline(?\Closure $scheduleInspector = null): DeployPipeline
    {
        return new DeployPipeline(new Builder(new FakeRunner(onRun: $this->composerInstall())), new BinaryProvider(new \Multiapi\Arco\Binary\GuzzleDownloader(), sys_get_temp_dir(), \Multiapi\Arco\Binary\RuntimeManifest::load()), new Packager(), $scheduleInspector ?? fn (string $appDir) => null);
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
        self::assertSame(['prepare', 'release', 'scheduler:off', 'prune'], $deployer->calls);
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

    private function sqsConfig(array $variables = []): AppConfig
    {
        return ConfigResolver::resolve(
            App::make('shop')->region('us-east-1')
                ->runtime(fn (Runtime $r) => $r->binary(ElfInspectorTest::fakeElf(0xB7)))
                ->variables($variables)
                ->queue('jobs')->queue('emails', default: true)
                ->environment('production', fn (Environment $e) => $e->account('123456789012'))
                ->toArray(),
            'production',
        );
    }

    public function test_queues_add_their_variables_before_the_users(): void
    {
        $variables = DeployPipeline::variables($this->sqsConfig(), new PlainPhpPreset());

        self::assertSame('sqs', $variables['QUEUE_CONNECTION']);
        self::assertSame('https://sqs.us-east-1.amazonaws.com/123456789012', $variables['SQS_PREFIX']);
        self::assertSame('https://sqs.us-east-1.amazonaws.com/123456789012/shop-production-emails', $variables['SQS_QUEUE']);
        self::assertSame(
            '{"emails":"https://sqs.us-east-1.amazonaws.com/123456789012/shop-production-emails","jobs":"https://sqs.us-east-1.amazonaws.com/123456789012/shop-production-jobs"}',
            $variables['ARCO_QUEUES'],
        );
    }

    public function test_a_user_variable_wins_and_the_override_is_noted(): void
    {
        $config = $this->sqsConfig(['QUEUE_CONNECTION' => 'database', 'SQS_QUEUE' => 'mine']);
        $deployer = new FakeDeployer('rev-1');
        $steps = [];

        $this->pipeline()->deploy($config, new PlainPhpPreset(), $this->project, $deployer, new FakeVerifier(true), function (string $key, array $params = []) use (&$steps): void { $steps[] = [$key, $params]; });

        self::assertSame('database', $deployer->variables['QUEUE_CONNECTION']);
        self::assertSame('mine', $deployer->variables['SQS_QUEUE']);
        self::assertSame('https://sqs.us-east-1.amazonaws.com/123456789012', $deployer->variables['SQS_PREFIX']);
        self::assertSame(
            [['deploy.variable_overridden', ['key' => 'QUEUE_CONNECTION']], ['deploy.variable_overridden', ['key' => 'SQS_QUEUE']]],
            array_values(array_filter($steps, fn (array $step) => $step[0] === 'deploy.variable_overridden')),
        );
    }

    private function resourcesConfig(callable $build, array $variables = []): AppConfig
    {
        $app = $build(App::make('shop')->region('us-east-1')->runtime(fn (Runtime $r) => $r->binary(ElfInspectorTest::fakeElf(0xB7)))->variables($variables));
        return ConfigResolver::resolve($app->environment('production', fn (Environment $e) => $e->account('123456789012'))->toArray(), 'production');
    }

    public function test_storage_adds_the_default_disk_and_every_bucket(): void
    {
        $variables = DeployPipeline::variables($this->resourcesConfig(fn (App $app) => $app->storage('uploads')->storage('media', existing: 'legacy.bucket', default: true)), new PlainPhpPreset());

        self::assertSame([
            'FILESYSTEM_DISK' => 's3',
            'AWS_BUCKET' => 'legacy.bucket',
            'AWS_URL' => 'https://legacy.bucket.s3.us-east-1.amazonaws.com',
            'ARCO_STORAGE' => '{"uploads":{"bucket":"shop-production-uploads-123456789012","url":"https://shop-production-uploads-123456789012.s3.us-east-1.amazonaws.com"},"media":{"bucket":"legacy.bucket","url":"https://legacy.bucket.s3.us-east-1.amazonaws.com"}}',
            'ARCO' => '1',
            'ARCO_APP' => 'shop',
            'ARCO_ENV' => 'production',
        ], $variables);
    }

    public function test_tables_arco_creates_add_their_variables(): void
    {
        $variables = DeployPipeline::variables($this->resourcesConfig(fn (App $app) => $app->cache()->failedJobsTable()), new PlainPhpPreset());

        self::assertSame([
            'CACHE_STORE' => 'dynamodb',
            'DYNAMODB_CACHE_TABLE' => 'shop-production-cache',
            'ARCO_CACHE_TABLE' => 'shop-production-cache',
            'QUEUE_FAILED_DRIVER' => 'dynamodb',
            'ARCO_FAILED_JOBS_TABLE' => 'shop-production-failed-jobs',
            'ARCO' => '1',
            'ARCO_APP' => 'shop',
            'ARCO_ENV' => 'production',
        ], $variables);
    }

    public function test_existing_tables_leave_the_cache_attributes_to_the_app(): void
    {
        $variables = DeployPipeline::variables($this->resourcesConfig(fn (App $app) => $app->cache(existing: 'Legacy_Cache')->failedJobsTable(existing: 'Legacy_Failed')), new PlainPhpPreset());

        self::assertSame([
            'CACHE_STORE' => 'dynamodb',
            'DYNAMODB_CACHE_TABLE' => 'Legacy_Cache',
            'QUEUE_FAILED_DRIVER' => 'dynamodb',
            'ARCO_FAILED_JOBS_TABLE' => 'Legacy_Failed',
            'ARCO' => '1',
            'ARCO_APP' => 'shop',
            'ARCO_ENV' => 'production',
        ], $variables);
    }

    public function test_a_user_variable_wins_over_storage_and_tables_and_the_override_is_noted(): void
    {
        $user = ['FILESYSTEM_DISK' => 'local', 'AWS_BUCKET' => 'b', 'AWS_URL' => 'u', 'CACHE_STORE' => 'file', 'DYNAMODB_CACHE_TABLE' => 't', 'QUEUE_FAILED_DRIVER' => 'database', 'ARCO_STORAGE' => '{}'];
        $config = $this->resourcesConfig(fn (App $app) => $app->storage('uploads')->cache()->failedJobsTable(), $user);
        $deployer = new FakeDeployer('rev-1');
        $steps = [];

        $this->pipeline()->deploy($config, new PlainPhpPreset(), $this->project, $deployer, new FakeVerifier(true), function (string $key, array $params = []) use (&$steps): void { $steps[] = [$key, $params]; });

        foreach ($user as $key => $value) {
            self::assertSame($value, $deployer->variables[$key]);
        }
        // arco-laravel would otherwise force the store's table and the failed driver back to Arco's.
        self::assertArrayNotHasKey('ARCO_CACHE_TABLE', $deployer->variables);
        self::assertArrayNotHasKey('ARCO_FAILED_JOBS_TABLE', $deployer->variables);
        self::assertSame(
            array_map(fn (string $key) => ['deploy.variable_overridden', ['key' => $key]], ['FILESYSTEM_DISK', 'AWS_BUCKET', 'AWS_URL', 'CACHE_STORE', 'DYNAMODB_CACHE_TABLE', 'QUEUE_FAILED_DRIVER']),
            array_values(array_filter($steps, fn (array $step) => $step[0] === 'deploy.variable_overridden')),
        );
    }

    public function test_each_user_override_of_a_table_drops_arcos_wiring_of_that_table(): void
    {
        $variables = fn (array $user): array => DeployPipeline::variables($this->resourcesConfig(fn (App $app) => $app->cache()->failedJobsTable(), $user), new PlainPhpPreset());

        $own = $variables(['DYNAMODB_CACHE_TABLE' => 'mine']);
        self::assertSame('mine', $own['DYNAMODB_CACHE_TABLE']);
        self::assertArrayNotHasKey('ARCO_CACHE_TABLE', $own);
        self::assertSame('shop-production-failed-jobs', $own['ARCO_FAILED_JOBS_TABLE']);

        $file = $variables(['CACHE_STORE' => 'file']);
        self::assertArrayNotHasKey('ARCO_CACHE_TABLE', $file);

        self::assertSame('shop-production-cache', $variables(['CACHE_STORE' => 'dynamodb'])['ARCO_CACHE_TABLE'], 'the same value is no override');

        $database = $variables(['QUEUE_FAILED_DRIVER' => 'database-uuids']);
        self::assertArrayNotHasKey('ARCO_FAILED_JOBS_TABLE', $database);
        self::assertSame('shop-production-cache', $database['ARCO_CACHE_TABLE']);

        self::assertSame('shop-production-failed-jobs', $variables(['QUEUE_FAILED_DRIVER' => 'dynamodb'])['ARCO_FAILED_JOBS_TABLE']);
    }

    public function test_without_queues_or_resources_no_variables_are_added(): void
    {
        $variables = DeployPipeline::variables($this->config(), new PlainPhpPreset());

        foreach (['QUEUE_CONNECTION', 'SQS_PREFIX', 'SQS_QUEUE', 'ARCO_QUEUES', 'FILESYSTEM_DISK', 'AWS_BUCKET', 'AWS_URL', 'ARCO_STORAGE', 'CACHE_STORE', 'DYNAMODB_CACHE_TABLE', 'ARCO_CACHE_TABLE', 'QUEUE_FAILED_DRIVER', 'ARCO_FAILED_JOBS_TABLE'] as $key) {
            self::assertArrayNotHasKey($key, $variables);
        }
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

        self::assertSame(['prepare', 'release', 'scheduler:off', 'domains:/build/*', 'prune'], $deployer->calls);
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
        $zip = TempPaths::path('arco-prebuilt-', '.zip');
        copy($built->path, $zip);
        $build->cleanup();
        $package = \Multiapi\Arco\Package\PackageReader::open($zip);

        $before = glob(sys_get_temp_dir() . '/arco-build-*') ?: [];
        $deployer = new FakeDeployer('rev-1');
        $steps = [];
        $outcome = $this->pipeline()->deployPackage($this->config(domains: true), new PlainPhpPreset(), $package, $deployer, new FakeVerifier(true), function (string $key) use (&$steps): void { $steps[] = $key; });

        self::assertTrue($outcome->verification->ok);
        self::assertSame(['prepare', 'release', 'scheduler:off', 'domains:', 'prune'], $deployer->calls);
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

        self::assertSame(['prepare', 'release', 'scheduler:off', 'domains:', 'coldStart', 'prune'], $deployer->calls);
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
        self::assertSame(['prepare', 'release', 'scheduler:off', 'prune'], $deployer->calls);
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

        self::assertSame(['prepare', 'release', 'live:7', 'scheduler:off', 'domains:', 'coldStart', 'prune'], $deployer->calls);
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

    /**
     * A pipeline whose manifest has 1.13.0-r1 (no PHP CLI) and 1.13.0-r2 (with one); downloads write
     * $content, or throw $failure when it is given.
     */
    private function cliPipeline(string $content = 'php-cli-bytes', ?\Throwable $failure = null): DeployPipeline
    {
        $elf = (string) file_get_contents(ElfInspectorTest::fakeElf(0xB7));
        $build = fn (string $id) => [
            'url' => "https://example.test/frankenphp-$id/frankenphp-linux-aarch64",
            'sha256' => hash('sha256', $elf),
            'size' => strlen($elf),
            'frankenphp' => '1.13.0',
            'php' => '8.5.11',
        ];
        $manifest = \Multiapi\Arco\Binary\RuntimeManifest::fromArray([
            'default' => '1.13.0-r2',
            'builds' => [
                '1.13.0-r1' => $build('1.13.0-r1'),
                '1.13.0-r2' => [...$build('1.13.0-r2'), 'php_cli' => [
                    'url' => 'https://example.test/frankenphp-1.13.0-r2/php-linux-aarch64',
                    'sha256' => hash('sha256', 'php-cli-bytes'),
                    'size' => strlen('php-cli-bytes'),
                ]],
            ],
        ]);
        $downloader = new class ($content, $failure) implements \Multiapi\Arco\Binary\Downloader {
            public function __construct(private string $content, private ?\Throwable $failure) {}
            public function download(string $url, string $destination, ?callable $onProgress = null): void
            {
                if ($this->failure !== null) {
                    throw $this->failure;
                }
                // Like Guzzle: an unknown total first, then the bytes as they arrive.
                if ($onProgress !== null) {
                    $onProgress(0, 0);
                    $onProgress(6, strlen($this->content));
                    $onProgress(6, strlen($this->content));
                }
                file_put_contents($destination, $this->content);
                if ($onProgress !== null) {
                    $onProgress(strlen($this->content), strlen($this->content));
                }
            }
        };
        $cache = TempPaths::path('arco-cache-');
        return new DeployPipeline(new Builder(new FakeRunner()), new BinaryProvider($downloader, $cache, $manifest), new Packager());
    }

    /**
     * A small web zip whose manifest names $runtimeBuild (null: a custom binary) and claims $bytes
     * unzipped. Its console zip claims $bytes - 3 without the PHP CLI (the 3-byte binary is removed)
     * and $bytes + 10 with the 13-byte CLI of cliPipeline(). It holds what queue workers need, except $without.
     *
     * @param list<string> $without
     */
    private static function webPackage(?string $runtimeBuild, int $bytes = 8, array $without = []): \Multiapi\Arco\Package\Package
    {
        $path = TempPaths::path('arco-web-', '.zip');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('bin/frankenphp', 'ELF');
        $zip->addFromString('app/index.php', '<?php');
        foreach (array_diff(self::QUEUE_FILES, $without) as $file) {
            $zip->addFromString("app/$file", '<?php');
        }
        $zip->close();
        $hash = str_repeat('e', 64);
        return new \Multiapi\Arco\Package\Package($path, $hash, $bytes, new \Multiapi\Arco\Package\PackageManifest('shop', 'production', 'plain', $runtimeBuild, $hash, $bytes, 'dev', '2026-10-16T00:00:00Z'));
    }

    /** @return array{0: FakeDeployer, 1: array<string, array<string, string|int>>} */
    private function deployWeb(DeployPipeline $pipeline, ?string $runtimeBuild, int $bytes = 8): array
    {
        $deployer = new FakeDeployer('rev-1');
        $steps = [];
        $pipeline->deployPackage($this->config(), new PlainPhpPreset(), self::webPackage($runtimeBuild, $bytes), $deployer, new FakeVerifier(true), function (string $key, array $params) use (&$steps): void { $steps[$key] = $params; });
        return [$deployer, $steps];
    }

    public function test_a_build_with_a_php_cli_puts_it_in_the_console_zip(): void
    {
        [$deployer, $steps] = $this->deployWeb($this->cliPipeline(), '1.13.0-r2');

        self::assertNotNull($deployer->console);
        self::assertNull($deployer->console->download);
        self::assertArrayNotHasKey('deploy.console_downloads_binary', $steps);
    }

    public function test_the_first_php_cli_download_reports_progress_and_the_cached_one_does_not(): void
    {
        $pipeline = $this->cliPipeline();
        $deploy = function () use ($pipeline): array {
            $steps = [];
            $pipeline->deployPackage($this->config(), new PlainPhpPreset(), self::webPackage('1.13.0-r2'), new FakeDeployer('rev-1'), new FakeVerifier(true), function (string $key, array $params) use (&$steps): void { $steps[] = [$key, $params]; });
            return $steps;
        };

        $first = $deploy();
        $second = $deploy();

        self::assertSame([
            ['deploy.php_cli_downloading', ['build' => '1.13.0-r2', 'size' => 0]],
            ['package.progress', ['percent' => 46]],
            ['package.progress', ['percent' => 100]],
        ], array_slice($first, 0, 3));
        self::assertSame([], array_filter($second, fn (array $step) => in_array($step[0], ['deploy.php_cli_downloading', 'package.progress'], true)), 'a cached CLI is not downloaded again');
    }

    public function test_a_console_that_fits_only_without_the_php_cli_falls_back_to_the_download(): void
    {
        [$deployer, $steps] = $this->deployWeb($this->cliPipeline(), '1.13.0-r2', ConsoleFunction::MAX_UNZIPPED_BYTES + 3);

        self::assertNotNull($deployer->console?->download);
        self::assertSame(ConsoleFunction::MAX_UNZIPPED_BYTES, $deployer->console->package->bytes, 'the rebuilt zip, without bin/php');
        self::assertSame(['reason' => 'php_cli_too_large'], $steps['deploy.console_downloads_binary'] ?? null);
        self::assertArrayNotHasKey('deploy.console_skipped_size', $steps);
    }

    public function test_a_console_too_large_even_without_the_php_cli_is_skipped(): void
    {
        $max = ConsoleFunction::MAX_UNZIPPED_BYTES;
        [$deployer, $steps] = $this->deployWeb($this->cliPipeline(), '1.13.0-r2', $max + 4);

        self::assertNull($deployer->console);
        self::assertSame(['size' => (int) round(($max + 1) / 1048576), 'max' => (int) round($max / 1048576)], $steps['deploy.console_skipped_size'] ?? null);
        self::assertArrayNotHasKey('deploy.console_downloads_binary', $steps);

        $deployer = new FakeDeployer('rev-1', consoleVersion: '7');
        try {
            $this->cliPipeline()->deployPackage($this->config(), new PlainPhpPreset(), self::webPackage('1.13.0-r2', $max + 4), $deployer, new FakeVerifier(true), fn () => null, $this->plan($deployer, []));
            self::fail('Commands cannot run without the console function.');
        } catch (DeployException $e) {
            self::assertSame('deploy.commands_package_too_large', $e->key);
        }
        self::assertSame([], $deployer->calls);
    }

    public function test_a_console_with_the_php_cli_just_under_the_limit_keeps_it(): void
    {
        [$deployer, $steps] = $this->deployWeb($this->cliPipeline(), '1.13.0-r2', ConsoleFunction::MAX_UNZIPPED_BYTES - 10);

        self::assertNotNull($deployer->console);
        self::assertNull($deployer->console->download);
        self::assertSame(ConsoleFunction::MAX_UNZIPPED_BYTES, $deployer->console->package->bytes);
        self::assertArrayNotHasKey('deploy.console_downloads_binary', $steps);
    }

    public function test_a_custom_binary_falls_back_to_the_download(): void
    {
        [$deployer, $steps] = $this->deployWeb($this->cliPipeline(), null);

        self::assertNotNull($deployer->console?->download);
        self::assertSame(['reason' => 'custom_binary'], $steps['deploy.console_downloads_binary'] ?? null);
    }

    public function test_a_package_of_an_old_build_without_a_php_cli_falls_back(): void
    {
        [$deployer, $steps] = $this->deployWeb($this->cliPipeline(), '1.13.0-r1');

        self::assertNotNull($deployer->console?->download);
        self::assertSame(['reason' => 'no_php_cli'], $steps['deploy.console_downloads_binary'] ?? null);
    }

    public function test_a_package_of_a_build_this_arco_does_not_know_falls_back_with_its_own_reason(): void
    {
        [$deployer, $steps] = $this->deployWeb($this->cliPipeline(), '9.9.9-r1');

        self::assertNotNull($deployer->console?->download);
        self::assertSame(['reason' => 'unknown_build', 'build' => '9.9.9-r1'], $steps['deploy.console_downloads_binary'] ?? null);
    }

    public function test_a_php_cli_that_cannot_be_downloaded_falls_back_and_the_deploy_goes_on(): void
    {
        $url = 'https://example.test/frankenphp-1.13.0-r2/php-linux-aarch64';
        $failure = \Multiapi\Arco\Binary\BinaryException::make('binary.download_failed', ['url' => $url, 'message' => 'cURL error 6: Could not resolve host']);

        [$deployer, $steps] = $this->deployWeb($this->cliPipeline(failure: $failure), '1.13.0-r2');

        self::assertNotNull($deployer->console?->download, 'the console downloads FrankenPHP at cold start instead');
        self::assertSame(
            ['reason' => 'php_cli_unavailable', 'url' => $url, 'message' => 'cURL error 6: Could not resolve host'],
            $steps['deploy.console_downloads_binary'] ?? null,
        );
        self::assertSame(['prepare', 'release', 'scheduler:off', 'prune'], $deployer->calls, 'the deploy goes on');
    }

    public function test_a_php_cli_that_fails_its_checksum_stops_the_deploy_before_any_aws_change(): void
    {
        // Same length as the expected bytes, so the size check passes and the checksum rejects it.
        $deployer = new FakeDeployer('rev-1');
        try {
            $this->cliPipeline('PHP-CLI-BYTES')->deployPackage($this->config(), new PlainPhpPreset(), self::webPackage('1.13.0-r2'), $deployer, new FakeVerifier(true), fn () => null);
            self::fail('A PHP CLI with the wrong checksum must stop the deploy.');
        } catch (\Multiapi\Arco\Binary\BinaryException $e) {
            self::assertSame('binary.checksum_mismatch', $e->key);
        }
        self::assertSame([], $deployer->calls);
    }

    /** A web zip whose console part claims $bytes unzipped (the 3-byte binary is removed from it). */
    public static function zipClaiming(int $bytes): \Multiapi\Arco\Package\Package
    {
        $path = TempPaths::path('arco-big-', '.zip');
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
        self::assertNotContains('deploy.console_downloads_binary', $steps, 'no console is deployed, so it downloads nothing');
        self::assertSame(['prepare', 'release', 'scheduler:off', 'prune'], $deployer->calls);
    }

    public function test_pruning_runs_last_and_only_after_a_verified_deploy(): void
    {
        $deployer = new FakeDeployer('rev-1', consoleVersion: '7', previousConsoleVersion: '6');
        $this->pipeline()->deploy($this->config(), new PlainPhpPreset(), $this->project, $deployer, new FakeVerifier(true), fn () => null);
        self::assertSame(['prepare', 'release', 'live:7', 'scheduler:off', 'prune'], $deployer->calls);

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
        self::assertSame(['prepare', 'release', 'live:7', 'scheduler:off', 'domains:', 'prune'], $deployer->calls);
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
        self::assertSame(['prepare', 'release', 'live:7', 'scheduler:off', 'prune'], $deployer->calls);
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

        self::assertSame(['prepare', 'console:migrate --force@7', 'console:config:cache@7', 'release', 'live:7', 'scheduler:off', 'prune'], $deployer->calls);
        self::assertSame(['migrate --force', 'config:cache'], $outcome->commandsRan);
    }

    public function test_each_result_is_handed_over_before_the_next_command_runs(): void
    {
        $deployer = new FakeDeployer('rev-1', consoleVersion: '7');
        $console = new FakeConsole([new CommandResult(0, "one\n"), new CommandResult(0, "two\n")], function (string $call) use ($deployer): void { $deployer->calls[] = $call; });
        $plan = new CommandPlan(['a', 'b'], $console, function (string $command, CommandResult $result) use ($deployer): void { $deployer->calls[] = "result:$command:" . trim($result->output); });

        $this->pipeline()->deploy($this->config(), new PlainPhpPreset(), $this->project, $deployer, new FakeVerifier(true), fn () => null, $plan);

        self::assertSame(['prepare', 'console:a@7', 'result:a:one', 'console:b@7', 'result:b:two', 'release', 'live:7', 'scheduler:off', 'prune'], $deployer->calls);
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

    public function test_console_not_packaged_when_disabled(): void
    {
        $deployer = new FakeDeployer('rev-1');
        $steps = [];

        $outcome = $this->pipeline()->deploy($this->config(console: false), new PlainPhpPreset(), $this->project, $deployer, new FakeVerifier(true), function (string $key) use (&$steps): void { $steps[] = $key; });

        self::assertTrue($outcome->verification->ok);
        self::assertSame(['prepare', 'release', 'prune'], $deployer->calls, 'no console to remove: it does not exist');
        self::assertNull($deployer->console);
        self::assertNotContains('deploy.console_downloads_binary', $steps);
        self::assertNotContains('deploy.console_skipped_size', $steps);
    }

    public function test_console_never_set_and_absent_deploys_the_web_function_only(): void
    {
        $deployer = new FakeDeployer('rev-1');

        $this->pipeline()->deploy($this->config(console: null), new PlainPhpPreset(), $this->project, $deployer, new FakeVerifier(true), fn () => null);

        self::assertSame(['prepare', 'release', 'prune'], $deployer->calls);
        self::assertNull($deployer->console);
    }

    public function test_an_oversized_package_without_console_skips_no_console(): void
    {
        $deployer = new FakeDeployer('rev-1');
        $steps = [];

        $this->pipeline()->deployPackage($this->config(console: false), new PlainPhpPreset(), self::zipClaiming(ConsoleFunction::MAX_UNZIPPED_BYTES + 1), $deployer, new FakeVerifier(true), function (string $key) use (&$steps): void { $steps[] = $key; });

        self::assertNotContains('deploy.console_skipped_size', $steps, 'no console package is built at all');
        self::assertNull($deployer->console);
    }

    public function test_upgrade_guard_stops_before_any_change(): void
    {
        $deployer = new FakeDeployer('rev-1');
        $deployer->consoleExists = true;
        $steps = [];

        try {
            $this->pipeline()->deploy($this->config(console: null), new PlainPhpPreset(), $this->project, $deployer, new FakeVerifier(true), function (string $key) use (&$steps): void { $steps[] = $key; });
            self::fail('An existing console with no console setting must stop the deploy.');
        } catch (DeployException $e) {
            self::assertSame('deploy.console_setting_missing', $e->key);
            self::assertSame(['environment' => 'production'], $e->params);
        }
        self::assertSame([], $deployer->calls, 'no prepare, release or removal');
        self::assertNotContains('build.staging', $steps, 'nothing is built');
    }

    public function test_upgrade_guard_also_covers_a_prebuilt_package(): void
    {
        $deployer = new FakeDeployer('rev-1');
        $deployer->consoleExists = true;

        try {
            $this->pipeline()->deployPackage($this->config(console: null), new PlainPhpPreset(), self::webPackage(null), $deployer, new FakeVerifier(true), fn () => null);
            self::fail('An existing console with no console setting must stop the deploy.');
        } catch (DeployException $e) {
            self::assertSame('deploy.console_setting_missing', $e->key);
        }
        self::assertSame([], $deployer->calls);
    }

    public function test_console_false_removes_existing_console_after_release(): void
    {
        $deployer = new FakeDeployer('rev-1');
        $deployer->consoleExists = true;

        $this->pipeline()->deploy($this->config(console: false), new PlainPhpPreset(), $this->project, $deployer, new FakeVerifier(true), fn () => null);

        self::assertSame(['prepare', 'release', 'removeConsole', 'prune'], $deployer->calls);
        self::assertNull($deployer->console);

        $failed = new FakeDeployer('rev-1');
        $failed->consoleExists = true;
        $this->pipeline()->deploy($this->config(console: false), new PlainPhpPreset(), $this->project, $failed, new FakeVerifier(false), fn () => null);

        self::assertSame(['prepare', 'release', 'rollback:rev-1'], $failed->calls, 'the console stays while the old revision serves');
    }

    public function test_an_enabled_console_is_not_looked_up(): void
    {
        $deployer = new FakeDeployer('rev-1', consoleVersion: '7');
        $deployer->consoleExists = true;

        $this->pipeline()->deploy($this->config(), new PlainPhpPreset(), $this->project, $deployer, new FakeVerifier(true), fn () => null);

        self::assertSame(0, $deployer->consoleLookups);
        self::assertSame(['prepare', 'release', 'live:7', 'scheduler:off', 'prune'], $deployer->calls);
    }

    public function test_deploy_commands_need_console(): void
    {
        $deployer = new FakeDeployer('rev-1', consoleVersion: '7');
        $steps = [];

        try {
            $this->pipeline()->deploy($this->config(console: false), new PlainPhpPreset(), $this->project, $deployer, new FakeVerifier(true), function (string $key) use (&$steps): void { $steps[] = $key; }, $this->plan($deployer, [], ['migrate --force']));
            self::fail('Deploy commands need the console function.');
        } catch (DeployException $e) {
            self::assertSame('deploy.commands_need_console', $e->key);
            self::assertSame(['environment' => 'production'], $e->params);
        }
        self::assertSame([], $deployer->calls);
        self::assertNotContains('build.staging', $steps);
    }

    public function test_an_empty_plan_without_console_deploys(): void
    {
        $deployer = new FakeDeployer('rev-1');

        $outcome = $this->pipeline()->deploy($this->config(console: false), new PlainPhpPreset(), $this->project, $deployer, new FakeVerifier(true), fn () => null, $this->plan($deployer, [], []));

        self::assertTrue($outcome->verification->ok);
    }

    public function test_a_failed_console_removal_is_a_warning_and_the_deploy_goes_on(): void
    {
        $deployer = new FakeDeployer('rev-1');
        $deployer->consoleExists = true;
        $deployer->removeConsoleError = new \RuntimeException('AccessDenied on lambda:DeleteFunction');
        $steps = [];

        $outcome = $this->pipeline()->deploy($this->config(domains: true, console: false), new PlainPhpPreset(), $this->project, $deployer, new FakeVerifier(true), function (string $key, array $params) use (&$steps): void { $steps[$key] = $params; });

        self::assertTrue($outcome->verification->ok);
        self::assertFalse($outcome->rolledBack);
        self::assertSame(['prepare', 'release', 'removeConsole', 'domains:', 'prune'], $deployer->calls, 'domains and prune still run');
        self::assertSame(['function' => 'shop-production-console', 'error' => 'AccessDenied on lambda:DeleteFunction'], $steps['deploy.console_remove_failed'] ?? null);
    }

    private function schedulerConfig(?int $every, ?bool $console = null): AppConfig
    {
        $app = App::make('shop')->region('us-east-1')->runtime(fn (Runtime $r) => $r->binary(ElfInspectorTest::fakeElf(0xB7)));
        if ($every !== null) {
            $app->scheduler($every);
        }
        if ($console !== null) {
            $app->console($console);
        }
        return ConfigResolver::resolve($app->environment('production')->toArray(), 'production');
    }

    public function test_scheduler_synced_after_console_activation(): void
    {
        $deployer = new FakeDeployer('rev-1', consoleVersion: '7');
        $steps = [];

        $outcome = $this->pipeline()->deploy($this->schedulerConfig(5), new PlainPhpPreset(), $this->project, $deployer, new FakeVerifier(true), function (string $key, array $params) use (&$steps): void { $steps[$key] = $params; });

        self::assertTrue($outcome->verification->ok);
        self::assertSame(['prepare', 'release', 'live:7', 'scheduler:5', 'prune'], $deployer->calls);
        self::assertSame(['every' => 5], $steps['deploy.scheduler_set'] ?? null);

        // Lambda's retries could not be turned off, but the alias did move: the scheduler still follows it.
        $retries = new FakeDeployer('rev-1', consoleVersion: '7');
        $retries->activateError = new ConsoleRetriesNotDisabled('denied');
        $this->pipeline()->deploy($this->schedulerConfig(5), new PlainPhpPreset(), $this->project, $retries, new FakeVerifier(true), fn () => null);
        self::assertSame(['prepare', 'release', 'live:7', 'scheduler:5', 'prune'], $retries->calls);
    }

    public function test_scheduler_skipped_when_console_not_activated(): void
    {
        $deployer = new FakeDeployer('rev-1', consoleVersion: '7');
        $deployer->activateError = new \RuntimeException('not authorized to perform: lambda:UpdateAlias');
        $steps = [];

        $outcome = $this->pipeline()->deploy($this->schedulerConfig(5), new PlainPhpPreset(), $this->project, $deployer, new FakeVerifier(true), function (string $key, array $params) use (&$steps): void { $steps[$key] = $params; });

        self::assertTrue($outcome->verification->ok);
        self::assertFalse($outcome->rolledBack);
        self::assertSame(['prepare', 'release', 'live:7', 'prune'], $deployer->calls, 'the rule is not touched');
        self::assertArrayHasKey('deploy.console_not_activated', $steps);
        self::assertSame(['function' => 'shop-production-console', 'every' => 5], $steps['deploy.scheduler_skipped'] ?? null);
        self::assertContains('deploy.scheduler_skipped', \Multiapi\Arco\Cli\StepPrinter::WARNINGS);

        // No console version in this deploy (e.g. skipped for size): nothing was activated either.
        $noVersion = new FakeDeployer('rev-1');
        $keys = [];
        $this->pipeline()->deploy($this->schedulerConfig(5), new PlainPhpPreset(), $this->project, $noVersion, new FakeVerifier(true), function (string $key) use (&$keys): void { $keys[] = $key; });
        self::assertSame(['prepare', 'release', 'prune'], $noVersion->calls);
        self::assertContains('deploy.scheduler_skipped', $keys);

        // A failed verification rolls back: no scheduler change, no warning about it.
        $failed = new FakeDeployer('rev-1', consoleVersion: '7');
        $keys = [];
        $this->pipeline()->deploy($this->schedulerConfig(5), new PlainPhpPreset(), $this->project, $failed, new FakeVerifier(false), function (string $key) use (&$keys): void { $keys[] = $key; });
        self::assertSame(['prepare', 'release', 'rollback:rev-1'], $failed->calls);
        self::assertNotContains('deploy.scheduler_skipped', $keys);
    }

    private function retentionConfig(int|false|null $days): AppConfig
    {
        $app = App::make('shop')->region('us-east-1')->console(false)->runtime(fn (Runtime $r) => $r->binary(ElfInspectorTest::fakeElf(0xB7)));
        if ($days !== null) {
            $app->logRetention($days);
        }
        return ConfigResolver::resolve($app->environment('production')->toArray(), 'production');
    }

    public function test_log_retention_applied_after_release_and_failure_is_a_warning(): void
    {
        $deployer = new FakeDeployer('rev-1');
        $steps = [];
        $this->pipeline()->deploy($this->retentionConfig(30), new PlainPhpPreset(), $this->project, $deployer, new FakeVerifier(true), function (string $key, array $params) use (&$steps): void { $steps[$key] = $params; });
        self::assertSame(['prepare', 'release', 'retention:30', 'prune'], $deployer->calls);
        self::assertSame(['days' => 30, 'groups' => 'web'], $steps['deploy.log_retention'] ?? null);

        // Not managed: no call.
        $none = new FakeDeployer('rev-1');
        $this->pipeline()->deploy($this->retentionConfig(null), new PlainPhpPreset(), $this->project, $none, new FakeVerifier(true), fn () => null);
        self::assertSame(['prepare', 'release', 'prune'], $none->calls);

        // AccessDenied: warning, the deploy still succeeds.
        $denied = new FakeDeployer('rev-1');
        $denied->logRetentionError = AwsMocks::error(\Aws\CloudWatchLogs\Exception\CloudWatchLogsException::class, 'PutRetentionPolicy', 'AccessDeniedException', 400, 'denied');
        $steps = [];
        $outcome = $this->pipeline()->deploy($this->retentionConfig(false), new PlainPhpPreset(), $this->project, $denied, new FakeVerifier(true), function (string $key, array $params) use (&$steps): void { $steps[$key] = $params; });
        self::assertTrue($outcome->verification->ok);
        self::assertSame(['error' => 'denied'], $steps['deploy.log_retention_failed'] ?? null);
        self::assertContains('deploy.log_retention_failed', \Multiapi\Arco\Cli\StepPrinter::WARNINGS);
        self::assertContains('prune', $denied->calls);
    }

    public function test_scheduler_removed_when_turned_off(): void
    {
        $deployer = new FakeDeployer('rev-1', consoleVersion: '7');
        $deployer->schedulerRuleExists = true;
        $steps = [];

        $outcome = $this->pipeline()->deploy($this->schedulerConfig(null, console: true), new PlainPhpPreset(), $this->project, $deployer, new FakeVerifier(true), function (string $key) use (&$steps): void { $steps[] = $key; });

        self::assertTrue($outcome->verification->ok);
        self::assertSame(['prepare', 'release', 'live:7', 'scheduler:off', 'prune'], $deployer->calls);
        self::assertContains('deploy.scheduler_removed', $steps);

        // console(false): removeConsole() takes the rule with it, so the scheduler is not synced apart.
        $off = new FakeDeployer('rev-1');
        $off->consoleExists = true;
        $this->pipeline()->deploy($this->schedulerConfig(null, console: false), new PlainPhpPreset(), $this->project, $off, new FakeVerifier(true), fn () => null);
        self::assertSame(['prepare', 'release', 'removeConsole', 'prune'], $off->calls);
    }

    public function test_a_failed_scheduler_sync_is_a_warning_and_the_deploy_goes_on(): void
    {
        $deployer = new FakeDeployer('rev-1', consoleVersion: '7');
        $deployer->syncSchedulerError = new \RuntimeException('not authorized to perform: events:PutRule');
        $steps = [];

        $outcome = $this->pipeline()->deploy($this->schedulerConfig(5), new PlainPhpPreset(), $this->project, $deployer, new FakeVerifier(true), function (string $key, array $params) use (&$steps): void { $steps[$key] = $params; });

        self::assertTrue($outcome->verification->ok);
        self::assertSame(['prepare', 'release', 'live:7', 'scheduler:5', 'prune'], $deployer->calls);
        self::assertSame(['rule' => 'arco-shop-production-scheduler', 'error' => 'not authorized to perform: events:PutRule'], $steps['deploy.scheduler_failed'] ?? null);
        self::assertContains('deploy.scheduler_failed', \Multiapi\Arco\Cli\StepPrinter::WARNINGS);
    }

    public function test_warns_when_scheduler_slower_than_tasks(): void
    {
        $config = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->runtime(fn (Runtime $r) => $r->binary(ElfInspectorTest::fakeElf(0xB7)))->scheduler(30)->environment('production')->toArray(), 'production');
        $inspected = [];
        $inspector = function (string $appDir) use (&$inspected): array {
            $inspected[] = $appDir;
            return [['task' => 'reports:send', 'minutes' => range(0, 55, 5)], ['task' => 'backup:run', 'minutes' => [0]]];
        };
        $steps = [];

        $outcome = $this->pipeline($inspector)->deploy($config, new PlainPhpPreset(), $this->project, new FakeDeployer('rev-1'), new FakeVerifier(true), function (string $key, array $params) use (&$steps): void { $steps[$key] = $params; });

        self::assertTrue($outcome->verification->ok);
        self::assertCount(1, $inspected);
        self::assertStringEndsWith('/app', $inspected[0]);
        self::assertSame(['every' => 30, 'tasks' => 'reports:send', 'required' => 5], $steps['deploy.scheduler_too_slow'] ?? null);
        self::assertContains('deploy.scheduler_too_slow', \Multiapi\Arco\Cli\StepPrinter::WARNINGS);
    }

    public function test_an_unreadable_schedule_or_no_scheduler_gives_no_warning(): void
    {
        $config = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->runtime(fn (Runtime $r) => $r->binary(ElfInspectorTest::fakeElf(0xB7)))->scheduler(30)->environment('production')->toArray(), 'production');
        $steps = [];
        $record = function (string $key) use (&$steps): void { $steps[] = $key; };

        $outcome = $this->pipeline(fn (string $appDir) => null)->deploy($config, new PlainPhpPreset(), $this->project, new FakeDeployer('rev-1'), new FakeVerifier(true), $record);
        self::assertTrue($outcome->verification->ok);

        $called = false;
        $this->pipeline(function () use (&$called): array { $called = true; return []; })->deploy($this->config(), new PlainPhpPreset(), $this->project, new FakeDeployer('rev-1'), new FakeVerifier(true), $record);
        self::assertFalse($called, 'no scheduler: the schedule is not read');

        $throwing = $this->pipeline(fn () => throw new \RuntimeException('boom'))->deploy($config, new PlainPhpPreset(), $this->project, new FakeDeployer('rev-1'), new FakeVerifier(true), $record);
        self::assertTrue($throwing->verification->ok);
        self::assertNotContains('deploy.scheduler_too_slow', $steps);
    }

    private function queueConfig(): AppConfig
    {
        return ConfigResolver::resolve(
            App::make('shop')->console(true)->region('us-east-1')
                ->runtime(fn (Runtime $r) => $r->binary(ElfInspectorTest::fakeElf(0xB7)))
                ->queue('emails')
                ->environment('production')
                ->toArray(),
            'production',
        );
    }

    public function test_resources_are_deployed_after_the_build_and_before_prepare(): void
    {
        $deployer = new FakeDeployer('rev-1');
        $steps = [];

        $this->pipeline()->deploy($this->queueConfig(), new PlainPhpPreset(), $this->project, $deployer, new FakeVerifier(true), function (string $key) use (&$steps): void { $steps[] = $key; });

        self::assertSame(['resources', 'prepare', 'release', 'scheduler:off', 'workers:emails', 'prune'], $deployer->calls);
        self::assertContains('deploy.queue_created', $steps);
        self::assertLessThan(array_search('deploy.queue_created', $steps, true), array_search('package.ready', $steps, true));
    }

    public function test_a_failed_resources_deploy_stops_before_any_traffic_moves(): void
    {
        $deployer = new FakeDeployer('rev-1');
        $deployer->resourcesError = DeployException::make('deploy.queue_retained', ['queue' => 'emails', 'existing' => "queue('emails', existing: 'arn')"]);

        try {
            $this->pipeline()->deploy($this->queueConfig(), new PlainPhpPreset(), $this->project, $deployer, new FakeVerifier(true), fn () => null);
            self::fail('expected deploy.queue_retained');
        } catch (DeployException $e) {
            self::assertSame('deploy.queue_retained', $e->key);
        }
        self::assertSame(['resources'], $deployer->calls);
    }

    public function test_a_resources_stack_left_without_queues_is_still_handled(): void
    {
        $deployer = new FakeDeployer('rev-1');
        $deployer->resourcesStack = true;

        $this->pipeline()->deploy($this->config(), new PlainPhpPreset(), $this->project, $deployer, new FakeVerifier(true), fn () => null);

        self::assertSame(['resources', 'prepare', 'release', 'scheduler:off', 'prune'], $deployer->calls);
    }

    public function test_storage_and_tables_are_deployed_with_the_resources_before_prepare(): void
    {
        $deployer = new FakeDeployer('rev-1');
        $steps = [];
        $config = ConfigResolver::resolve(
            App::make('shop')->region('us-east-1')->runtime(fn (Runtime $r) => $r->binary(ElfInspectorTest::fakeElf(0xB7)))->storage('uploads')->cache()->environment('production', fn ($e) => $e->account('111111111111'))->toArray(),
            'production',
        );

        $this->pipeline()->deploy($config, new PlainPhpPreset(), $this->project, $deployer, new FakeVerifier(true), function (string $key) use (&$steps): void { $steps[] = $key; });

        self::assertSame('resources', $deployer->calls[0]);
        self::assertContains('prepare', $deployer->calls);
        self::assertContains('deploy.storage_created', $steps);
        self::assertContains('deploy.table_created', $steps);
    }

    public function test_only_existing_queues_and_no_stack_need_no_resources_deploy(): void
    {
        $deployer = new FakeDeployer('rev-1');
        $config = ConfigResolver::resolve(
            App::make('shop')->console(true)->region('us-east-1')
                ->runtime(fn (Runtime $r) => $r->binary(ElfInspectorTest::fakeElf(0xB7)))
                ->queue('legacy', existing: 'legacy', dlq: false)
                ->environment('production')
                ->toArray(),
            'production',
        );

        $this->pipeline()->deploy($config, new PlainPhpPreset(), $this->project, $deployer, new FakeVerifier(true), fn () => null);

        self::assertSame(['prepare', 'release', 'scheduler:off', 'workers:legacy', 'prune'], $deployer->calls);
    }

    /** Queues without the console: the console zip is still built, for the workers. */
    private function workersOnlyConfig(): AppConfig
    {
        return ConfigResolver::resolve(
            App::make('shop')->region('us-east-1')
                ->runtime(fn (Runtime $r) => $r->binary(ElfInspectorTest::fakeElf(0xB7)))
                ->queue('emails')
                ->environment('production')
                ->toArray(),
            'production',
        );
    }

    public function test_queues_build_the_console_zip_even_when_the_console_is_off(): void
    {
        $deployer = new FakeDeployer('rev-1');
        $deployer->workerVersions = ['emails' => ['version' => '3', 'previous' => '2']];
        $steps = [];

        $this->pipeline()->deployPackage($this->workersOnlyConfig(), new PlainPhpPreset(), self::webPackage(null), $deployer, new FakeVerifier(true), function (string $key, array $params) use (&$steps): void { $steps[$key] = $params; });

        self::assertNotNull($deployer->console, 'the workers run the console zip');
        self::assertSame(['resources', 'prepare', 'release', 'workers:emails', 'prune'], $deployer->calls, 'no scheduler sync and no console activation without the console');
        self::assertSame(['queue' => 'emails', 'function' => 'shop-production-queue-emails', 'version' => '3'], $steps['deploy.worker_ready'] ?? null);
    }

    public function test_queues_with_a_package_too_large_for_lambda_stop_before_any_change(): void
    {
        $deployer = new FakeDeployer('rev-1');

        try {
            $this->pipeline()->deployPackage($this->workersOnlyConfig(), new PlainPhpPreset(), self::webPackage(null, ConsoleFunction::MAX_UNZIPPED_BYTES + 4), $deployer, new FakeVerifier(true), fn () => null);
            self::fail('expected deploy.workers_package_too_large');
        } catch (DeployException $e) {
            self::assertSame('deploy.workers_package_too_large', $e->key);
        }
        self::assertSame([], $deployer->calls);
    }

    public function test_queues_need_a_package_with_arco_laravel_and_the_aws_sdk_before_any_change(): void
    {
        foreach ([
            [[self::QUEUE_FILES[0]], 'multiapi/arco-laravel:^0.5'],
            [[self::QUEUE_FILES[1]], 'aws/aws-sdk-php'],
            [self::QUEUE_FILES, 'multiapi/arco-laravel:^0.5 aws/aws-sdk-php'],
        ] as [$without, $packages]) {
            $deployer = new FakeDeployer('rev-1');
            try {
                $this->pipeline()->deployPackage($this->workersOnlyConfig(), new PlainPhpPreset(), self::webPackage(null, without: $without), $deployer, new FakeVerifier(true), fn () => null);
                self::fail('expected deploy.queues_need_packages');
            } catch (DeployException $e) {
                self::assertSame('deploy.queues_need_packages', $e->key);
                self::assertSame($packages, $e->params['packages'] ?? null);
            }
            self::assertSame([], $deployer->calls, 'nothing in AWS changed');
        }
    }

    public function test_the_package_check_also_runs_on_a_fresh_build_and_never_without_queues(): void
    {
        $this->withoutVendor = [self::QUEUE_FILES[0]];
        $deployer = new FakeDeployer('rev-1');
        try {
            $this->pipeline()->deploy($this->queueConfig(), new PlainPhpPreset(), $this->project, $deployer, new FakeVerifier(true), fn () => null);
            self::fail('expected deploy.queues_need_packages');
        } catch (DeployException $e) {
            self::assertSame('deploy.queues_need_packages', $e->key);
        }
        self::assertSame([], $deployer->calls);

        $deployer = new FakeDeployer('rev-1');
        $this->pipeline()->deployPackage($this->config(), new PlainPhpPreset(), self::webPackage(null, without: self::QUEUE_FILES), $deployer, new FakeVerifier(true), fn () => null);
        self::assertContains('release', $deployer->calls, 'apps without queues need neither package');
    }

    public function test_a_worker_failure_after_release_is_a_warning_and_the_deploy_goes_on(): void
    {
        $deployer = new FakeDeployer('rev-1');
        $deployer->workersError = new \RuntimeException('no lambda:CreateEventSourceMapping');
        $steps = [];

        $outcome = $this->pipeline()->deployPackage($this->workersOnlyConfig(), new PlainPhpPreset(), self::webPackage(null), $deployer, new FakeVerifier(true), function (string $key, array $params) use (&$steps): void { $steps[$key] = $params; });

        self::assertTrue($outcome->verification->ok);
        self::assertSame(['error' => 'no lambda:CreateEventSourceMapping'], $steps['deploy.workers_sync_failed'] ?? null, 'its own warning: no queue `*` in the per-queue text');
        self::assertArrayNotHasKey('deploy.worker_failed', $steps);
        self::assertContains('prune', $deployer->calls, 'the rest of the deploy still runs');
        self::assertContains('deploy.workers_sync_failed', \Multiapi\Arco\Cli\StepPrinter::WARNINGS);
    }

    public function test_workers_follow_a_verified_revision_only(): void
    {
        $deployer = new FakeDeployer('rev-1');

        $outcome = $this->pipeline()->deployPackage($this->workersOnlyConfig(), new PlainPhpPreset(), self::webPackage(null), $deployer, new FakeVerifier(false), fn () => null);

        self::assertFalse($outcome->verification->ok);
        self::assertSame(['resources', 'prepare', 'release', 'rollback:rev-1'], $deployer->calls, 'the worker aliases and mappings never moved');
    }

    public function test_workers_left_from_removed_queues_are_cleaned_up_without_queues(): void
    {
        $deployer = new FakeDeployer('rev-1');
        $deployer->workersLeft = true;

        $this->pipeline()->deploy($this->config(), new PlainPhpPreset(), $this->project, $deployer, new FakeVerifier(true), fn () => null);

        self::assertSame(['prepare', 'release', 'scheduler:off', 'workers:', 'prune'], $deployer->calls);
    }
}
