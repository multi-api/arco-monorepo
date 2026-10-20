<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\E2e;

use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ConfigLoader;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Deploy\ResourcesStack;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Deploys examples/laravel (environment `e2e`, Lambda URL only) to the dedicated Arco E2E account and
 * runs the Floci suite's checks there, storage included. Run only by `bin/test-docker e2e-aws`
 * (composer test:e2e-aws); see docs/maintainers/e2e.md.
 *
 * Inputs: AWS_PROFILE (required), ARCO_E2E_ACCOUNT (required, the 12-digit account id the profile
 * must resolve to), ARCO_E2E_DRY_RUN (default 1). Every run gets its own app name arco-e2e-<8 hex>,
 * passed to arco.php as ARCO_E2E_APP.
 *
 * Before anything else AwsE2eCleanup::connect() refuses an AWS_ENDPOINT_URL* override and any account
 * but ARCO_E2E_ACCOUNT. A dry run then only packages the app and prints what a real run would create
 * and what the cleanup finds for the app name, calling no mutating API. A real run creates the APP_KEY
 * parameter, deploys, checks, runs `arco remove`, then deletes what remove retains (AwsE2eCleanup).
 */
#[Group('e2e-aws')]
final class AwsE2eTest extends TestCase
{
    use E2eChecks;

    private const ENV = AwsE2eCleanup::ENVIRONMENT;
    private const REGION = 'us-east-1';

    private static ?string $runApp = null;
    private static bool $dryRun = true;
    private static ?AwsE2eCleanup $cleanup = null;
    /** From the first call that may create something on: tearDownAfterClass removes and cleans up. */
    private static bool $mayHaveCreated = false;

    protected static function environment(): string
    {
        return self::ENV;
    }

    /** @return array<string, string> */
    protected static function processEnv(): array
    {
        \assert(self::$runApp !== null);
        return ['ARCO_E2E_APP' => self::$runApp];
    }

    public static function setUpBeforeClass(): void
    {
        $profile = self::input('AWS_PROFILE');
        $account = self::input('ARCO_E2E_ACCOUNT');
        $dryRun = getenv('ARCO_E2E_DRY_RUN');
        self::$dryRun = $dryRun === false || $dryRun === '' || $dryRun !== '0';

        // The guard: no call to AWS before it, and only sts:GetCallerIdentity inside it.
        $aws = new AwsClients($profile, self::REGION);
        self::$cleanup = AwsE2eCleanup::connect($aws, $account, null, static function (string $line): void {
            fwrite(STDERR, "$line\n");
        });

        self::$runApp = AwsE2eCleanup::newAppName();
        // The config loader of this process (dry-run listing) reads arco.php the way the CLI does.
        self::setEnv('ARCO_E2E_APP', self::$runApp);
        self::$app = self::copyExample();
        fwrite(STDERR, sprintf("\n[e2e-aws] account %s, profile %s, app %s, %s\n", $account, $profile, self::$runApp, self::$dryRun ? 'DRY RUN' : 'REAL RUN'));

        if (self::$dryRun) {
            self::dryRun($account);
            return;
        }

        self::$mayHaveCreated = true;
        try {
            self::putAppKey($aws);
            $deploy = self::deploy();
            if (! $deploy->isSuccessful()) {
                self::fail("[deploy] arco deploy failed:\n" . $deploy->getOutput() . $deploy->getErrorOutput());
            }
            fwrite(STDERR, "\n" . self::tail($deploy->getOutput(), 40) . "\n");
            self::readInfo();
            fwrite(STDERR, sprintf("[deploy] url %s\n", self::$url));
        } catch (\Throwable $e) {
            // PHPUnit skips tearDownAfterClass when setUpBeforeClass fails: remove and clean up here.
            try {
                self::tearDownAfterClass();
            } catch (\Throwable $cleanup) {
                fwrite(STDERR, '[cleanup] FAILED: ' . $cleanup->getMessage() . "\n");
            }
            throw $e;
        }
    }

    public static function tearDownAfterClass(): void
    {
        $cleanup = self::$cleanup;
        $app = self::$runApp;
        if (! self::$mayHaveCreated || $cleanup === null || $app === null) {
            return;
        }
        // Once only (also called from a failed setUpBeforeClass).
        self::$mayHaveCreated = false;
        $began = microtime(true);
        $remove = self::arco(['remove', '--env=' . self::ENV, '--yes', '--no-interaction'], 900);
        self::report('remove', $began, $remove->isSuccessful() ? 'ok' : 'FAILED');
        if (! $remove->isSuccessful()) {
            fwrite(STDERR, "[remove] arco remove failed:\n" . self::tail($remove->getOutput() . $remove->getErrorOutput(), 40) . "\n");
        }

        $began = microtime(true);
        $plan = $cleanup->plan($app);
        self::printPlan($plan);
        $cleanup->apply($plan);
        self::report('cleanup', $began, 'ok');
        if (! $remove->isSuccessful()) {
            throw new \RuntimeException("arco remove failed (output above): functions, roles or stacks of $app may be left in the account.");
        }
    }

    protected function setUp(): void
    {
        if (self::$dryRun) {
            self::markTestSkipped('Dry run (ARCO_E2E_DRY_RUN=0 deploys for real).');
        }
        $this->http = new E2eHttp();
        self::$started = microtime(true);
    }

    #[Depends('test_up')]
    public function test_storage(): void
    {
        $data = $this->http->json('storage', self::url('/e2e/storage'));
        self::assertTrue(($data['ok'] ?? null) === true, '[storage] S3 did not return the file just written: ' . json_encode($data));
    }

    /** For this process and its children: Symfony Process passes on only variables $_SERVER or $_ENV also has. */
    public static function setEnv(string $name, ?string $value): void
    {
        if ($value === null) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
            return;
        }
        putenv("$name=$value");
        $_ENV[$name] = $_SERVER[$name] = $value;
    }

    private static function input(string $name): string
    {
        $value = getenv($name);
        if (! is_string($value) || $value === '') {
            self::fail("Refusing the real-AWS end-to-end run: $name is not set. Run it with composer test:e2e-aws (docs/maintainers/e2e.md).");
        }
        return $value;
    }

    /** Packages the app and prints what a real run would create and what the cleanup finds now. */
    private static function dryRun(string $account): void
    {
        $began = microtime(true);
        $package = self::arco(['package', '--env=' . self::ENV, '--no-interaction'], 1800);
        self::report('package', $began, $package->isSuccessful() ? 'ok' : 'FAILED');
        if (! $package->isSuccessful()) {
            self::fail("[package] arco package failed:\n" . $package->getOutput() . $package->getErrorOutput());
        }

        \assert(self::$app !== null && self::$runApp !== null && self::$cleanup !== null);
        $config = ConfigResolver::resolve((new ConfigLoader())->load(self::$app . '/arco.php'), self::ENV)->withAccount($account);
        $create = self::resourcesToCreate($config);
        fwrite(STDERR, "[dry run] a real run would create (besides the role, log groups and scheduler rule):\n  " . implode("\n  ", $create) . "\n");

        $plan = self::$cleanup->plan(self::$runApp);
        self::printPlan($plan);
    }

    /**
     * The named resources a real run creates for $config (besides the role, log groups and scheduler rule).
     *
     * @return list<string> "<type> <name>"
     */
    public static function resourcesToCreate(AppConfig $config): array
    {
        $create = ["ssm-parameter /{$config->app}/" . self::ENV . '/app-key (created by the run)', "web-function {$config->functionName()}"];
        if ($config->consoleEnabled()) {
            $create[] = "lambda-function {$config->consoleFunctionName()}";
        }
        $create[] = 'cloudformation-stack ' . ResourcesStack::name($config);
        foreach (ResourcesStack::createdQueues($config) as $queue) {
            $create[] = "lambda-function {$config->workerFunctionName($queue->name)}";
            $create[] = "sqs-queue {$queue->physicalName($config)}";
            $dlq = $queue->dlqName($config);
            if ($dlq !== null) {
                $create[] = "sqs-queue $dlq";
            }
        }
        foreach (ResourcesStack::createdStorage($config) as $storage) {
            $create[] = "s3-bucket {$storage->bucketName($config)}";
        }
        foreach ([$config->cacheTable, $config->failedJobsTable] as $table) {
            if ($table !== null && $table->existing === null) {
                $create[] = "dynamodb-table {$table->tableName($config)}";
            }
        }
        return $create;
    }

    /** @param list<array{type: string, name: string}> $plan */
    private static function printPlan(array $plan): void
    {
        \assert(self::$cleanup !== null);
        $lines = array_map(static fn (array $item): string => "{$item['type']} {$item['name']}", $plan);
        fwrite(STDERR, '[cleanup] ' . ($plan === [] ? 'finds nothing to delete for ' . self::$runApp : "would delete:\n  " . implode("\n  ", $lines)) . "\n");
        foreach (self::$cleanup->skipped() as $skip) {
            fwrite(STDERR, "[cleanup] leaves alone {$skip['type']} {$skip['name']}: {$skip['reason']}\n");
        }
    }

    /** The APP_KEY arco.php references (arco-ssm:/<app>/e2e/app-key), tagged with the run's app. */
    private static function putAppKey(AwsClients $aws): void
    {
        \assert(self::$runApp !== null);
        $aws->ssm()->putParameter([
            'Name' => '/' . self::$runApp . '/' . self::ENV . '/app-key',
            'Type' => 'SecureString',
            'Value' => 'base64:' . base64_encode(random_bytes(32)),
            'Tags' => [
                ['Key' => 'arco:app', 'Value' => self::$runApp],
                ['Key' => 'arco:environment', 'Value' => self::ENV],
            ],
        ]);
    }
}
