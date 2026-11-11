<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy;

use Aws\Iam\Exception\IamException;
use Aws\Lambda\Exception\LambdaException;
use Aws\LambdaWeb\Exception\LambdaWebException;
use Aws\EventBridge\Exception\EventBridgeException;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Multiapi\Arco\Binary\BinaryProvider;
use Multiapi\Arco\Build\Builder;
use Multiapi\Arco\Build\ProcessBuilder;
use Multiapi\Arco\Build\ProcessPreset;
use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Config\Environment;
use Multiapi\Arco\Deploy\AwsDeployer;
use Multiapi\Arco\Deploy\ConsoleFunction;
use Multiapi\Arco\Deploy\DeployException;
use Multiapi\Arco\Deploy\DeployPipeline;
use Multiapi\Arco\Package\ConsoleArtifacts;
use Multiapi\Arco\Package\Package;
use Multiapi\Arco\Package\Packager;
use Multiapi\Arco\Tests\Support\TempPaths;
use Multiapi\Arco\Tests\Unit\Build\FakeRunner;
use Multiapi\Arco\Tests\Unit\Commands\FakeDeployer;
use Multiapi\Arco\Tests\Unit\Commands\FakeVerifier;
use PHPUnit\Framework\TestCase;

/** A process (Go) app through the deploy: variables, pipeline, the classic functions and the kind check. */
final class ProcessDeployTest extends TestCase
{
    /**
     * @param array<string, array<string, mixed>> $queues
     * @param array<string, array<string, mixed>> $schedules
     * @param array<string, array<string, mixed>> $storage
     */
    private static function config(array $queues = ['emails' => []], array $schedules = ['cleanup' => ['every' => '1h']], array $storage = []): AppConfig
    {
        $raw = App::make('shop')->region('us-east-1')->variables(['LOG_LEVEL' => 'info'])
            ->environment('production', fn (Environment $e) => $e->account('111111111111'))->toArray();
        $raw['process'] = ['language' => 'go'];
        $raw['queues'] = $queues;
        $raw['schedules'] = $schedules;
        if ($storage !== []) {
            $raw['storage'] = $storage;
        }
        return ConfigResolver::resolve($raw, 'production');
    }

    private static function preset(AppConfig $config): ProcessPreset
    {
        return new ProcessPreset($config->process ?? throw new \LogicException('not a process app'));
    }

    private static function pipeline(): DeployPipeline
    {
        return new DeployPipeline(
            new Builder(new FakeRunner()),
            new BinaryProvider(new \Multiapi\Arco\Binary\GuzzleDownloader(), sys_get_temp_dir(), \Multiapi\Arco\Binary\RuntimeManifest::load()),
            new Packager(),
            fn () => throw new \LogicException('process apps have no Laravel schedule'),
            new ProcessBuilder(new FakeRunner(onRun: function (array $command): void {
                file_put_contents($command[4], 'ELF');
            }), 'go', fn (): bool => true),
        );
    }

    /** A process zip without any PHP package: checkPackages() would refuse it for a PHP app with queues. */
    private static function processZip(int $bytes = 8): Package
    {
        $path = TempPaths::path('arco-go-', '.zip');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('index.js', '//');
        $zip->addFromString('worker.js', '//');
        $zip->addFromString('app/bin/app', 'ELF');
        $zip->close();
        return new Package($path, str_repeat('f', 64), $bytes);
    }

    public function test_variables_carry_the_queue_urls_and_none_of_laravels(): void
    {
        $config = self::config(['emails' => [], 'welcome-mail' => []], [], ['uploads' => []]);

        $variables = DeployPipeline::variables($config, self::preset($config));

        self::assertSame('https://sqs.us-east-1.amazonaws.com/111111111111/shop-production-emails', $variables['ARCO_QUEUE_EMAILS_URL']);
        self::assertSame('https://sqs.us-east-1.amazonaws.com/111111111111/shop-production-welcome-mail', $variables['ARCO_QUEUE_WELCOME_MAIL_URL']);
        self::assertSame([
            'emails' => 'https://sqs.us-east-1.amazonaws.com/111111111111/shop-production-emails',
            'welcome-mail' => 'https://sqs.us-east-1.amazonaws.com/111111111111/shop-production-welcome-mail',
        ], json_decode($variables['ARCO_QUEUES'], true));
        self::assertArrayHasKey('ARCO_STORAGE', $variables);
        foreach (['QUEUE_CONNECTION', 'SQS_PREFIX', 'SQS_QUEUE', 'FILESYSTEM_DISK', 'AWS_BUCKET', 'AWS_URL', 'CACHE_STORE'] as $laravel) {
            self::assertArrayNotHasKey($laravel, $variables);
        }
        self::assertSame('info', $variables['LOG_LEVEL']);
        self::assertSame(['1', 'shop', 'production'], [$variables['ARCO'], $variables['ARCO_APP'], $variables['ARCO_ENV']]);
        self::assertSame([
            'ARCO_QUEUE_EMAILS_URL' => 'https://sqs.us-east-1.amazonaws.com/111111111111/shop-production-emails',
            'ARCO_QUEUE_WELCOME_MAIL_URL' => 'https://sqs.us-east-1.amazonaws.com/111111111111/shop-production-welcome-mail',
            'ARCO_QUEUES' => $variables['ARCO_QUEUES'],
        ], DeployPipeline::processQueueVariables($config));
        self::assertSame([], DeployPipeline::processQueueVariables(self::config([])));
    }

    public function test_package_builds_go_and_writes_the_process_zip_without_a_runtime_binary(): void
    {
        $project = TempPaths::path('arco-go-project-');
        mkdir("$project/cmd/api", 0777, true);
        file_put_contents("$project/cmd/api/main.go", "package main\n");
        $config = self::config();
        $steps = [];

        [$build, $package] = self::pipeline()->package($config, self::preset($config), $project, function (string $key, array $params) use (&$steps): void {
            $steps[] = $key;
        });

        try {
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($package->path));
            self::assertNotFalse($zip->locateName('app/bin/app'));
            self::assertNotFalse($zip->locateName('worker.js'));
            self::assertFalse($zip->locateName('bin/frankenphp'));
            $zip->close();
            self::assertNull($package->manifest?->runtimeBuild);
            self::assertSame('go', $package->manifest?->preset);
            self::assertNotContains('binary.using', $steps);
            self::assertSame(['build.staging', 'build.copying', 'build.go', 'package.writing'], array_slice(array_values(array_filter($steps, fn (string $key) => $key !== 'package.progress')), 0, 4));
        } finally {
            $build->cleanup();
        }
    }

    public function test_the_web_zip_is_the_code_of_the_console_and_the_workers(): void
    {
        $deployer = new FakeDeployer('rev-1');
        $package = self::processZip();
        $config = self::config();

        $outcome = self::pipeline()->deployPackage($config, self::preset($config), $package, $deployer, new FakeVerifier(true), fn () => null);

        self::assertTrue($outcome->verification->ok);
        self::assertSame($package, $deployer->console?->package);
        self::assertNull($deployer->console?->download);
        self::assertSame('https://sqs.us-east-1.amazonaws.com/111111111111/shop-production-emails', $deployer->variables['ARCO_QUEUE_EMAILS_URL']);
        self::assertArrayNotHasKey('QUEUE_CONNECTION', $deployer->variables);
    }

    public function test_a_web_only_process_app_deploys_without_a_console(): void
    {
        $deployer = new FakeDeployer('rev-1');
        $config = self::config([], []);

        $outcome = self::pipeline()->deployPackage($config, self::preset($config), self::processZip(ConsoleFunction::MAX_UNZIPPED_BYTES + 1048576), $deployer, new FakeVerifier(true), fn () => null);

        self::assertTrue($outcome->verification->ok, 'a web-only app keeps the 512 MiB limit');
        self::assertNull($deployer->console);
        self::assertContains('prepare', $deployer->calls);
    }

    public function test_a_process_zip_over_the_classic_limit_with_a_queue_fails_before_aws(): void
    {
        $deployer = new FakeDeployer('rev-1');
        $config = self::config(['emails' => []], []);

        try {
            self::pipeline()->deployPackage($config, self::preset($config), self::processZip(251 * 1048576), $deployer, new FakeVerifier(true), fn () => null);
            self::fail('expected deploy.process_package_too_large');
        } catch (DeployException $e) {
            self::assertSame('deploy.process_package_too_large', $e->key);
            self::assertSame(['size' => 251, 'max' => 250], $e->params);
        }
        self::assertSame([], $deployer->calls);
        self::assertSame(0, $deployer->ownershipChecks);
    }

    public function test_a_process_app_without_schedules_removes_an_existing_console_after_verification(): void
    {
        $deployer = new FakeDeployer('rev-1');
        $deployer->consoleExists = true;
        $config = self::config(['emails' => []], []);

        self::pipeline()->deployPackage($config, self::preset($config), self::processZip(), $deployer, new FakeVerifier(true), fn () => null);

        self::assertContains('removeConsole', $deployer->calls);
    }

    public function test_a_failed_schedule_sync_is_a_warning_that_names_no_laravel_rule(): void
    {
        $deployer = new FakeDeployer('rev-1');
        $deployer->syncSchedulerError = new \RuntimeException('not authorized to perform: tag:GetResources');
        $config = self::config([], []);
        $steps = [];

        $outcome = self::pipeline()->deployPackage($config, self::preset($config), self::processZip(), $deployer, new FakeVerifier(true), function (string $key, array $params = []) use (&$steps): void { $steps[$key] = $params; });

        self::assertTrue($outcome->verification->ok);
        self::assertArrayNotHasKey('deploy.scheduler_failed', $steps);
        self::assertSame(['error' => 'not authorized to perform: tag:GetResources'], $steps['deploy.schedules_failed'] ?? null);
        self::assertContains('deploy.schedules_failed', \Multiapi\Arco\Cli\StepPrinter::WARNINGS);
    }

    private static function lambdaMissing(string $operation): LambdaException
    {
        return AwsMocks::error(LambdaException::class, $operation, 'ResourceNotFoundException', 404);
    }

    private static function ready(): Result
    {
        return new Result(['State' => 'Active', 'LastUpdateStatus' => 'Successful']);
    }

    public function test_prepare_creates_the_worker_and_the_console_on_node_from_the_uploaded_web_zip(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['Account' => '111111111111']),                                             // GetCallerIdentity
            new Result([]),                                                                      // HeadBucket
            new Result([]), new Result([]), new Result([]), new Result([]),                      // bucket hardening PUTs
            AwsMocks::error(S3Exception::class, 'HeadObject', 'NotFound', 404),
            new Result(['VersionId' => 'v1']),                                                   // PutObject (web zip)
            AwsMocks::error(IamException::class, 'GetRole', 'NoSuchEntity', 404),
            new Result(['Role' => ['Arn' => 'arn:aws:iam::111111111111:role/arco-shop-production']]), // CreateRole
            new Result([]),                                                                      // PutRolePolicy
            AwsMocks::error(LambdaWebException::class, 'GetWebFunction', 'ResourceNotFoundException', 404),
            new Result(['revision' => ['revisionId' => 'rev-1'], 'endpoint' => ['domainName' => 'abc.on.aws']]),
            new Result(['state' => 'Active', 'stateReason' => 'ok']),                           // GetWebFunctionRevision
            AwsMocks::error(S3Exception::class, 'HeadObject', 'NotFound', 404),                // the same zip, console prefix
            new Result(['VersionId' => 'cv1']),                                                  // PutObject
            // console, emails, orders: live versions, lookups, CreateFunction, ready, PublishVersion
            self::lambdaMissing('GetAlias'), self::lambdaMissing('GetAlias'), self::lambdaMissing('GetAlias'),
            self::lambdaMissing('GetFunctionConfiguration'), self::lambdaMissing('GetFunctionConfiguration'), self::lambdaMissing('GetFunctionConfiguration'),
            new Result(['State' => 'Pending']), new Result(['State' => 'Pending']), new Result(['State' => 'Pending']),
            self::ready(), self::ready(), self::ready(),
            new Result(['Version' => '1']), new Result(['Version' => '1']), new Result(['Version' => '1']),
        );
        $config = self::config(['emails' => [], 'orders' => ['fifo' => true]]);
        $package = self::processZip();
        $variables = DeployPipeline::variables($config, self::preset($config));

        $prepared = (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->prepare($config, $package, new ConsoleArtifacts($package, null), $variables, fn () => null);

        $by = [];
        foreach ($mocks->calls as $i => $operation) {
            $by[$operation][] = $mocks->params[$i];
        }
        $webKey = 'shop/production/' . str_repeat('f', 64) . '.zip';
        $consoleKey = 'shop/production/console-' . str_repeat('f', 64) . '.zip';
        self::assertSame([$webKey, $consoleKey], array_column($by['PutObject'], 'Key'));
        self::assertNotContains('CopyObject', $mocks->calls, 'no runtime binary for a process app');
        self::assertCount(3, $by['CreateFunction']);
        [$console, $emails, $orders] = $by['CreateFunction'];
        self::assertSame(['shop-production-console', 'shop-production-queue-emails', 'shop-production-queue-orders'], [$console['FunctionName'], $emails['FunctionName'], $orders['FunctionName']]);
        foreach ([$console, $emails, $orders] as $create) {
            self::assertSame('nodejs24.x', $create['Runtime']);
            self::assertSame('worker.handler', $create['Handler']);
            self::assertSame(['arm64'], $create['Architectures']);
            self::assertSame(['S3Bucket' => 'arco-111111111111-us-east-1', 'S3Key' => $consoleKey, 'S3ObjectVersion' => 'cv1'], $create['Code']);
            self::assertArrayNotHasKey('ARCO_BINARY_KEY', $create['Environment']['Variables']);
            self::assertSame('https://sqs.us-east-1.amazonaws.com/111111111111/shop-production-emails', $create['Environment']['Variables']['ARCO_QUEUE_EMAILS_URL']);
        }
        self::assertSame('{"cleanup":{"route":"/_arco/schedule/cleanup","timeout":60}}', $console['Environment']['Variables']['ARCO_SCHEDULES']);
        self::assertSame(72, $console['Timeout']);
        self::assertSame('emails', $emails['Environment']['Variables']['ARCO_WORKER_QUEUE']);
        self::assertSame('/_arco/queues/emails', $emails['Environment']['Variables']['ARCO_QUEUE_ROUTE']);
        self::assertArrayNotHasKey('ARCO_QUEUE_FIFO', $emails['Environment']['Variables']);
        self::assertArrayNotHasKey('ARCO_QUEUE_TRIES', $emails['Environment']['Variables']);
        self::assertArrayNotHasKey('ARCO_QUEUE_MAX_RECEIVES', $emails['Environment']['Variables']);
        self::assertSame('1', $orders['Environment']['Variables']['ARCO_QUEUE_FIFO']);
        self::assertSame('1', $prepared->consoleVersion);
        self::assertSame(['emails', 'orders'], array_keys($prepared->workerVersions));
    }

    /**
     * The ownership reads of an existing classic function (GetFunctionConfiguration, ListTags).
     *
     * @return list<Result>
     */
    private static function existingFunction(string $name, string $runtime): array
    {
        return [
            new Result(['FunctionName' => $name, 'FunctionArn' => "arn:aws:lambda:us-east-1:111111111111:function:$name", 'Runtime' => $runtime]),
            new Result(['Tags' => ['arco:app' => 'shop', 'arco:environment' => 'production']]),
        ];
    }

    private static function noRule(): EventBridgeException
    {
        return AwsMocks::error(EventBridgeException::class, 'DescribeRule', 'ResourceNotFoundException', 400);
    }

    private const READS = ['GetRole', 'GetWebFunction', 'GetFunctionConfiguration', 'ListTags', 'DescribeRule'];

    public function test_a_php_console_refuses_a_process_deploy_before_any_change(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            AwsMocks::error(IamException::class, 'GetRole', 'NoSuchEntity', 404),
            AwsMocks::error(LambdaWebException::class, 'GetWebFunction', 'ResourceNotFoundException', 404),
            ...[...self::existingFunction('shop-production-console', 'provided.al2023'), self::noRule()],
        );

        try {
            (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->checkOwnership(self::config([]), fn () => null);
            self::fail('expected deploy.runtime_kind_changed');
        } catch (DeployException $e) {
            self::assertSame('deploy.runtime_kind_changed', $e->key);
            self::assertSame(['function' => 'shop-production-console', 'runtime' => 'provided.al2023'], $e->params);
        }
        self::assertSame([], array_values(array_diff($mocks->calls, self::READS)), 'reads only');
    }

    public function test_a_process_worker_refuses_a_php_deploy_and_a_matching_runtime_passes(): void
    {
        $php = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->queue('emails')
            ->environment('production', fn (Environment $e) => $e->account('111111111111'))->toArray(), 'production');
        $mocks = new AwsMocks();
        $mocks->handler->append(
            AwsMocks::error(IamException::class, 'GetRole', 'NoSuchEntity', 404),
            AwsMocks::error(LambdaWebException::class, 'GetWebFunction', 'ResourceNotFoundException', 404),
            self::lambdaMissing('GetFunctionConfiguration'),
            ...[...self::existingFunction('shop-production-queue-emails', 'nodejs24.x'), self::noRule(), new Result(['Stacks' => []])],
        );

        try {
            (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->checkOwnership($php, fn () => null);
            self::fail('expected deploy.runtime_kind_changed');
        } catch (DeployException $e) {
            self::assertSame('deploy.runtime_kind_changed', $e->key);
            self::assertSame(['function' => 'shop-production-queue-emails', 'runtime' => 'nodejs24.x'], $e->params);
        }

        $mocks = new AwsMocks();
        $mocks->handler->append(
            AwsMocks::error(IamException::class, 'GetRole', 'NoSuchEntity', 404),
            AwsMocks::error(LambdaWebException::class, 'GetWebFunction', 'ResourceNotFoundException', 404),
            ...[...self::existingFunction('shop-production-console', 'nodejs24.x'), ...self::existingFunction('shop-production-queue-emails', 'nodejs24.x'), self::noRule(), new Result(['Stacks' => []])],
        );
        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->checkOwnership(self::config(), fn () => null);
        self::assertNotContains('TagResource', $mocks->calls);
    }

    /** @return list<\Throwable|Result> the ownership reads of a first deploy with an existing console */
    private static function consoleReads(string $runtime, bool $queue = false): array
    {
        return [
            AwsMocks::error(IamException::class, 'GetRole', 'NoSuchEntity', 404),
            AwsMocks::error(LambdaWebException::class, 'GetWebFunction', 'ResourceNotFoundException', 404),
            ...self::existingFunction('shop-production-console', $runtime),
            ...($queue ? [self::lambdaMissing('GetFunctionConfiguration')] : []),
            self::noRule(),
            new Result(['Stacks' => []]),
        ];
    }

    public function test_the_kind_check_compares_runtime_families(): void
    {
        $php = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->console()
            ->environment('production', fn (Environment $e) => $e->account('111111111111'))->toArray(), 'production');

        $mocks = new AwsMocks();
        $mocks->handler->append(...self::consoleReads('provided.al2'));
        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->checkOwnership($php, fn () => null);

        $mocks = new AwsMocks();
        $mocks->handler->append(
            AwsMocks::error(IamException::class, 'GetRole', 'NoSuchEntity', 404),
            AwsMocks::error(LambdaWebException::class, 'GetWebFunction', 'ResourceNotFoundException', 404),
            self::lambdaMissing('GetFunctionConfiguration'),
            ...[...self::existingFunction('shop-production-queue-emails', 'nodejs22.x'), self::noRule(), new Result(['Stacks' => []])],
        );
        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->checkOwnership(self::config(), fn () => null);

        $mocks = new AwsMocks();
        $mocks->handler->append(...self::consoleReads('nodejs24.x'));
        try {
            (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->checkOwnership($php, fn () => null);
            self::fail('expected deploy.runtime_kind_changed');
        } catch (DeployException $e) {
            self::assertSame(['function' => 'shop-production-console', 'runtime' => 'nodejs24.x'], $e->params);
        }
    }

    public function test_prune_of_a_process_deploy_leaves_the_shared_runtime_binaries_alone(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['Contents' => []]),                     // ListObjectsV2 (web zips)
            new Result(['Contents' => []]),                     // ListObjectsV2 (console zips)
            new Result(['ResourceTagMappingList' => []]),       // GetResources, if the binaries were pruned
            new Result(['Contents' => []]),
        );
        $prepared = new \Multiapi\Arco\Deploy\PreparedDeploy('rev-2', null, 'arco-111111111111-us-east-1', 'shop/production/k.zip', null, null, 'shop/production/console-k.zip', null, []);

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->prune(self::config([]), $prepared, fn () => null);

        self::assertSame(['ListObjectsV2', 'ListObjectsV2'], $mocks->calls, 'no FrankenPHP binaries to keep or delete');
    }
}
