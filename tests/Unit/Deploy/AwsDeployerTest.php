<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy;

use Aws\CloudFormation\Exception\CloudFormationException;
use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\EventBridge\Exception\EventBridgeException;
use Aws\Iam\Exception\IamException;
use Aws\Lambda\Exception\LambdaException;
use Aws\LambdaWeb\Exception\LambdaWebException;
use Aws\ResourceGroupsTaggingAPI\Exception\ResourceGroupsTaggingAPIException;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\Sqs\Exception\SqsException;
use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Config\Environment;
use Multiapi\Arco\Deploy\AwsDeployer;
use Multiapi\Arco\Deploy\DeployException;
use Multiapi\Arco\Deploy\SchedulerRule;
use Multiapi\Arco\Deploy\DeployResult;
use Multiapi\Arco\Deploy\PreparedDeploy;
use Multiapi\Arco\Package\ConsoleArtifacts;
use Multiapi\Arco\Package\ConsoleDownload;
use Multiapi\Arco\Package\Package;
use Multiapi\Arco\Tests\Support\TempPaths;
use PHPUnit\Framework\TestCase;

final class AwsDeployerTest extends TestCase
{
    private function config(): AppConfig
    {
        return ConfigResolver::resolve(App::make('shop')->region('us-east-1')->environment('production')->toArray(), 'production');
    }

    private function consoleConfig(): AppConfig
    {
        return ConfigResolver::resolve(App::make('shop')->region('us-east-1')->environment('production')->console()->toArray(), 'production');
    }

    private function package(): Package
    {
        $file = TempPaths::file('pkg');
        file_put_contents((string) $file, 'zip');
        return new Package((string) $file, str_repeat('a', 64), 3);
    }

    private static function lambdaMissing(string $operation): LambdaException
    {
        return AwsMocks::error(LambdaException::class, $operation, 'ResourceNotFoundException', 404);
    }

    private static function noRule(): EventBridgeException
    {
        return AwsMocks::error(EventBridgeException::class, 'DescribeRule', 'ResourceNotFoundException', 400);
    }

    private function console(): ConsoleArtifacts
    {
        $zip = (string) TempPaths::file('console');
        file_put_contents($zip, 'console-zip');
        $binary = (string) TempPaths::file('bin');
        file_put_contents($binary, 'ELF');
        return new ConsoleArtifacts(new Package($zip, str_repeat('c', 64), 11), new ConsoleDownload($binary, (string) hash_file('sha256', $binary), 3, 'runtime/1.13.0-r1/frankenphp'));
    }

    public function test_first_deploy(): void
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
            AwsMocks::error(LambdaWebException::class, 'CreateWebFunction', 'ValidationException', 400, 'The role defined for the function cannot be assumed by Lambda.'),
            new Result(['revision' => ['revisionId' => 'rev-1'], 'endpoint' => ['domainName' => 'abc.xpt.lambda-web.us-east-1.on.aws']]),
            new Result(['state' => 'Active', 'stateReason' => 'ok']),                           // GetWebFunctionRevision
            AwsMocks::error(S3Exception::class, 'HeadObject', 'NotFound', 404),                // console zip
            new Result(['VersionId' => 'cv1']),                                                  // PutObject (console zip)
            AwsMocks::error(S3Exception::class, 'HeadObject', 'NotFound', 404),                // binary
            new Result(['VersionId' => 'bv1']),                                                  // PutObject (binary)
            self::lambdaMissing('GetAlias'),                                                     // no console version yet
            self::lambdaMissing('GetFunctionConfiguration'),
            new Result(['State' => 'Pending']),                                                  // CreateFunction
            new Result(['State' => 'Active', 'LastUpdateStatus' => 'Successful']),
            new Result(['Version' => '1']),                                                      // PublishVersion
            // release
            new Result(['state' => 'Active', 'domainName' => 'abc.xpt.lambda-web.us-east-1.on.aws', 'revisionWeights' => []]),
            new Result([]),                                                                      // UpdateWebFunctionEndpoint
            new Result(['state' => 'Active', 'updateStatus' => 'Successful', 'domainName' => 'abc.xpt.lambda-web.us-east-1.on.aws', 'revisionWeights' => [['revisionId' => 'rev-1', 'weight' => 100]]]),
            // after verification
            self::lambdaMissing('UpdateAlias'),
            new Result([]),                                                                      // CreateAlias
            new Result([]),                                                                      // PutFunctionEventInvokeConfig
            // prune
            new Result(['FunctionVersion' => '1']),                                              // GetAlias
            new Result(['Versions' => [['Version' => '$LATEST'], ['Version' => '1']]]),          // ListVersionsByFunction
            new Result(['Contents' => []]),                                                      // ListObjectsV2 (web zips)
            new Result(['Contents' => []]),                                                      // ListObjectsV2 (console zips)
            new Result(['ResourceTagMappingList' => []]),                                        // GetResources: the new function is not listed yet
            new Result(['Contents' => [['Key' => 'runtime/1.13.0-r1/frankenphp', 'LastModified' => new \DateTimeImmutable('1969-01-01')]]]), // ListObjectsV2 (runtime binaries)
        );
        $steps = [];
        $record = function (string $key) use (&$steps): void { $steps[] = $key; };
        $deployer = new AwsDeployer($mocks->clients(), AwsMocks::noSleep());
        $console = $this->console();

        $prepared = $deployer->prepare($this->consoleConfig(), $this->package(), $console, ['APP_ENV' => 'production'], $record);
        self::assertNotContains('UpdateWebFunctionEndpoint', $mocks->calls, 'prepare never moves traffic');
        $result = $deployer->release($this->config(), $prepared, $record);
        self::assertNotContains('UpdateAlias', $mocks->calls, 'live moves only once the revision is verified');
        self::assertNotContains('ListObjectsV2', $mocks->calls, 'nothing is pruned before the revision is verified');
        $deployer->activateConsole($this->config(), (string) $result->consoleVersion);
        $deployer->prune($this->config(), $prepared, $record);

        self::assertSame([
            'GetCallerIdentity', 'HeadBucket', 'PutPublicAccessBlock', 'PutBucketPolicy', 'PutBucketVersioning', 'PutBucketLifecycleConfiguration', 'HeadObject', 'PutObject', 'GetRole', 'CreateRole', 'PutRolePolicy',
            'GetWebFunction', 'CreateWebFunction', 'CreateWebFunction', 'GetWebFunctionRevision',
            'HeadObject', 'PutObject', 'HeadObject', 'PutObject',
            'GetAlias', 'GetFunctionConfiguration', 'CreateFunction', 'GetFunctionConfiguration', 'PublishVersion',
            'GetWebFunctionEndpoint', 'UpdateWebFunctionEndpoint', 'GetWebFunctionEndpoint',
            'UpdateAlias', 'CreateAlias', 'PutFunctionEventInvokeConfig',
            'GetAlias', 'ListVersionsByFunction', 'ListObjectsV2', 'ListObjectsV2', 'GetResources', 'ListObjectsV2',
        ], $mocks->calls);
        self::assertSame('runtime/', $mocks->params[35]['Prefix']);
        self::assertSame('runtime/1.13.0-r1/frankenphp', $prepared->binaryKey);
        self::assertNotContains('DeleteObjects', $mocks->calls, 'the binary this deploy uses is never pruned, even before the tag listing shows its function');
        $consoleKey = 'shop/production/console-' . str_repeat('c', 64) . '.zip';
        self::assertSame($consoleKey, $mocks->params[15]['Key']);
        self::assertSame('runtime/1.13.0-r1/frankenphp', $mocks->params[17]['Key']);
        self::assertSame(['S3Bucket' => 'arco-111111111111-us-east-1', 'S3Key' => $consoleKey, 'S3ObjectVersion' => 'cv1'], $mocks->params[21]['Code']);
        self::assertSame([
            'ARCO_BINARY_BUCKET' => 'arco-111111111111-us-east-1',
            'ARCO_BINARY_KEY' => 'runtime/1.13.0-r1/frankenphp',
            'ARCO_BINARY_SHA256' => $console->download?->sha256,
        ], array_intersect_key($mocks->params[21]['Environment']['Variables'], array_flip(['ARCO_BINARY_BUCKET', 'ARCO_BINARY_KEY', 'ARCO_BINARY_SHA256', 'ARCO_BINARY_VERSION'])));
        self::assertSame('production', $mocks->params[21]['Environment']['Variables']['APP_ENV']);
        self::assertSame(['arco:app' => 'shop', 'arco:environment' => 'production'], $mocks->params[21]['Tags']);
        $policy = json_decode($mocks->params[10]['PolicyDocument'], true);
        self::assertContains('arn:aws:s3:::arco-111111111111-us-east-1/runtime/*', array_column($policy['Statement'], 'Resource'));
        self::assertSame('1', $prepared->consoleVersion);
        self::assertNull($prepared->previousConsoleVersion);
        self::assertSame($consoleKey, $prepared->consoleKey);
        self::assertSame('https://abc.xpt.lambda-web.us-east-1.on.aws', $result->url());
        self::assertSame('rev-1', $result->revisionId);
        self::assertNull($result->previousRevisionId);
        self::assertSame('1', $result->consoleVersion);
        self::assertSame('arco-111111111111-us-east-1', $result->bucket);
        self::assertSame('shop/production/' . str_repeat('a', 64) . '.zip', $result->key);
        self::assertSame([['revisionId' => 'rev-1', 'weight' => 100]], $mocks->params[25]['revisionWeights']);
        self::assertSame('1', $mocks->params[28]['FunctionVersion']);
        self::assertSame('shop/production/', $mocks->params[32]['Prefix']);
        self::assertSame('shop/production/console-', $mocks->params[33]['Prefix']);
        self::assertSame(['deploy.step_bucket', 'deploy.step_upload', 'deploy.step_role', 'deploy.step_function', 'deploy.step_console', 'deploy.step_traffic'], $steps);
    }

    public function test_a_console_with_bin_php_uploads_no_binary_and_sets_no_binary_variables_but_keeps_the_binary_statement(): void
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
            new Result(['revision' => ['revisionId' => 'rev-1'], 'endpoint' => ['domainName' => 'abc.xpt.lambda-web.us-east-1.on.aws']]),
            new Result(['state' => 'Active', 'stateReason' => 'ok']),                           // GetWebFunctionRevision
            AwsMocks::error(S3Exception::class, 'HeadObject', 'NotFound', 404),                // console zip
            new Result(['VersionId' => 'cv1']),                                                  // PutObject (console zip)
            self::lambdaMissing('GetAlias'),
            self::lambdaMissing('GetFunctionConfiguration'),
            new Result(['State' => 'Pending']),                                                  // CreateFunction
            new Result(['State' => 'Active', 'LastUpdateStatus' => 'Successful']),
            new Result(['Version' => '1']),                                                      // PublishVersion
        );
        $zip = (string) TempPaths::file('console');
        file_put_contents($zip, 'console-zip-with-bin-php');
        $console = new ConsoleArtifacts(new Package($zip, str_repeat('c', 64), 24), null);

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->prepare($this->consoleConfig(), $this->package(), $console, ['APP_ENV' => 'production']);

        self::assertCount(0, $mocks->handler);
        $byOperation = [];
        foreach ($mocks->calls as $i => $operation) {
            $byOperation[$operation][] = $mocks->params[$i];
        }
        foreach ([...$byOperation['HeadObject'], ...$byOperation['PutObject']] as $params) {
            self::assertStringStartsNotWith('runtime/', $params['Key'], 'no binary upload');
        }
        self::assertCount(2, $byOperation['PutObject'], 'web zip and console zip only');
        $variables = $byOperation['CreateFunction'][0]['Environment']['Variables'];
        self::assertSame('production', $variables['APP_ENV']);
        self::assertSame([], array_filter(array_keys($variables), fn (string $name) => str_starts_with($name, 'ARCO_BINARY_')));
        $policy = json_decode($byOperation['PutRolePolicy'][0]['PolicyDocument'], true);
        self::assertContains('ArcoRuntimeBinary', array_column($policy['Statement'], 'Sid'), 'an older live console version may still download its binary');
    }

    public function test_redeploy_creates_a_revision_and_remembers_the_previous_one(): void
    {
        $ready = fn () => new Result(['State' => 'Active', 'LastUpdateStatus' => 'Successful']);
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['Account' => '111111111111']),
            new Result([]),                                   // HeadBucket
            new Result([]), new Result([]), new Result([]), new Result([]),
            new Result(['VersionId' => 'v1']),                // HeadObject (web zip already uploaded)
            new Result(['Role' => ['Arn' => 'arn:role']]),    // GetRole
            new Result([]),                                   // PutRolePolicy
            new Result(['functionName' => 'shop-production', 'state' => 'Active']),
            new Result(['domainName' => 'abc.on.aws', 'state' => 'Active', 'revisionWeights' => [['revisionId' => 'rev-1', 'weight' => 100]]]),
            new Result(['revisionId' => 'rev-2', 'state' => 'Pending']),
            new Result(['state' => 'Active', 'stateReason' => 'ok']),
            new Result(['VersionId' => 'cv1']),               // HeadObject (console zip already uploaded)
            new Result(['ContentLength' => 3, 'VersionId' => 'bv1']), // HeadObject (same binary already there)
            new Result([]),                                   // CopyObject (restarts its grace period)
            new Result(['FunctionVersion' => '6']),           // GetAlias
            $ready(), $ready(),                               // exists, idle
            new Result([]),                                   // UpdateFunctionCode
            $ready(),
            new Result([]),                                   // UpdateFunctionConfiguration
            $ready(),
            new Result(['Version' => '7']),
            new Result(['state' => 'Active', 'domainName' => 'abc.on.aws', 'revisionWeights' => [['revisionId' => 'rev-1', 'weight' => 100]]]),
            new Result([]),
            new Result(['state' => 'Active', 'updateStatus' => 'Successful', 'domainName' => 'abc.on.aws', 'revisionWeights' => [['revisionId' => 'rev-2', 'weight' => 100]]]),
        );
        $deployer = new AwsDeployer($mocks->clients(), AwsMocks::noSleep());

        $result = $deployer->release($this->config(), $deployer->prepare($this->consoleConfig(), $this->package(), $this->console(), []));

        self::assertSame('rev-2', $result->revisionId);
        self::assertSame('rev-1', $result->previousRevisionId);
        self::assertSame('7', $result->consoleVersion);
        self::assertSame('6', $result->previousConsoleVersion);
        self::assertSame([
            'GetCallerIdentity', 'HeadBucket', 'PutPublicAccessBlock', 'PutBucketPolicy', 'PutBucketVersioning', 'PutBucketLifecycleConfiguration', 'HeadObject', 'GetRole', 'PutRolePolicy', 'GetWebFunction', 'GetWebFunctionEndpoint',
            'CreateWebFunctionRevision', 'GetWebFunctionRevision', 'HeadObject', 'HeadObject', 'CopyObject',
            'GetAlias', 'GetFunctionConfiguration', 'GetFunctionConfiguration', 'UpdateFunctionCode', 'GetFunctionConfiguration', 'UpdateFunctionConfiguration', 'GetFunctionConfiguration', 'PublishVersion',
            'GetWebFunctionEndpoint', 'UpdateWebFunctionEndpoint', 'GetWebFunctionEndpoint',
        ], $mocks->calls, 'release neither moves live nor prunes');
        self::assertNotContains('PutObject', $mocks->calls, 'web zip, console zip and binary are uploaded once');
        self::assertSame('runtime/1.13.0-r1/frankenphp', $mocks->params[15]['Key']);
        self::assertSame('', $mocks->params[21]['KMSKeyArn'], 'no kmsKey in the config clears a key set by an earlier deploy');
    }

    public function test_prune_keeps_the_live_version_read_now_the_previous_one_and_the_four_newest(): void
    {
        $versions = fn (array $numbers) => array_map(fn (string $v) => ['Version' => $v], $numbers);
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['FunctionVersion' => '5']),                                              // live moved to 5 since prepare()
            new Result(['Versions' => $versions(['$LATEST', '1', '2', '3', '10']), 'NextMarker' => 'm1']),
            new Result(['Versions' => $versions(['4', '5', '6', '7', '8', '9', '11', '12'])]),
            new Result([]), new Result([]), new Result([]), new Result([]), new Result([]), new Result([]), // DeleteFunction 1, 2, 4, 6, 7, 8
            new Result(['Contents' => []]),
            new Result(['Contents' => []]),
            new Result(['ResourceTagMappingList' => []]), new Result(['Contents' => []]),
        );
        $prepared = new PreparedDeploy('rev-2', 'rev-1', 'b', 'shop/production/a.zip', '11', '3', 'shop/production/console-c.zip');
        self::assertNull($prepared->binaryKey);
        $steps = [];

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->prune($this->config(), $prepared, function (string $key) use (&$steps): void { $steps[] = $key; });

        self::assertSame(['FunctionName' => 'shop-production-console', 'Name' => 'live'], array_intersect_key($mocks->params[0], ['FunctionName' => 1, 'Name' => 1]));
        self::assertSame('m1', $mocks->params[2]['Marker']);
        $deleted = [];
        foreach ($mocks->calls as $i => $call) {
            if ($call === 'DeleteFunction') {
                self::assertSame('shop-production-console', $mocks->params[$i]['FunctionName']);
                $deleted[] = $mocks->params[$i]['Qualifier'];
            }
        }
        self::assertSame(['1', '2', '4', '6', '7', '8'], $deleted, 'keeps 5 (live now), 3 (live before the deploy), 9 to 12 (newest) and $LATEST');
        self::assertSame([], $steps, 'no warning when pruning works');
        self::assertCount(0, $mocks->handler);
    }

    /** @return iterable<string, array{0: list<mixed>, 1: list<string>}> */
    public static function pruneFailures(): iterable
    {
        $denied = fn (string $operation) => AwsMocks::error(LambdaException::class, $operation, 'AccessDeniedException', 403, "not authorized to perform: lambda:$operation");
        $runtime = [new Result(['ResourceTagMappingList' => []]), new Result(['Contents' => []])];
        $old = array_map(fn (int $i) => ['Key' => "shop/production/$i.zip", 'LastModified' => new \DateTimeImmutable("2026-01-01 +$i hours")], range(1, 21));
        yield 'ListVersionsByFunction' => [[
            new Result(['FunctionVersion' => '2']), $denied('ListVersionsByFunction'),
            new Result(['Contents' => []]), new Result(['Contents' => []]), ...$runtime,
        ], ['deploy.prune_versions_failed']];
        yield 'DeleteFunction' => [[
            new Result(['FunctionVersion' => '7']), new Result(['Versions' => array_map(fn (int $v) => ['Version' => (string) $v], range(1, 7))]), $denied('DeleteFunction'),
            new Result(['Contents' => []]), new Result(['Contents' => []]), ...$runtime,
        ], ['deploy.prune_versions_failed']];
        yield 'S3 DeleteObjects' => [[
            new Result(['FunctionVersion' => '2']), new Result(['Versions' => [['Version' => '1'], ['Version' => '2']]]),
            new Result(['Contents' => $old]), AwsMocks::error(S3Exception::class, 'DeleteObjects', 'AccessDenied', 403), ...$runtime,
        ], ['deploy.prune_packages_failed']];
        yield 'tagging GetResources' => [[
            new Result(['FunctionVersion' => '2']), new Result(['Versions' => [['Version' => '1'], ['Version' => '2']]]),
            new Result(['Contents' => []]), new Result(['Contents' => []]),
            AwsMocks::error(ResourceGroupsTaggingAPIException::class, 'GetResources', 'AccessDeniedException', 400, 'not authorized to perform: tag:GetResources'),
        ], ['deploy.prune_runtime_failed']];
        yield 'unreadable KMS-encrypted variables' => [[
            new Result(['FunctionVersion' => '2']), new Result(['Versions' => [['Version' => '1'], ['Version' => '2']]]),
            new Result(['Contents' => []]), new Result(['Contents' => []]),
            new Result(['ResourceTagMappingList' => [['ResourceARN' => 'arn:other-console']]]),
            new Result(['Versions' => [['Version' => '4', 'Environment' => ['Error' => ['ErrorCode' => 'AccessDeniedException', 'Message' => 'KMS access was denied']]]]]),
        ], ['deploy.prune_runtime_failed']];
    }

    /**
     * @param list<mixed> $responses
     * @param list<string> $warnings
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('pruneFailures')]
    public function test_pruning_failures_are_warnings(array $responses, array $warnings): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(...$responses);
        $steps = [];
        $prepared = new PreparedDeploy('rev-2', 'rev-1', 'b', 'shop/production/a.zip', '2', '1', 'shop/production/console-c.zip');

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->prune($this->config(), $prepared, function (string $key, array $params = []) use (&$steps): void {
            $steps[] = $key;
            self::assertNotSame('', $params['error'] ?? '');
        });

        self::assertSame($warnings, $steps);
        self::assertCount(0, $mocks->handler);
    }

    public function test_without_console_artifacts_the_console_function_is_left_alone(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['Account' => '111111111111']),
            new Result([]), new Result([]), new Result([]), new Result([]), new Result([]),
            new Result(['VersionId' => 'v1']),
            new Result(['Role' => ['Arn' => 'arn:role']]),
            new Result([]),
            new Result(['functionName' => 'shop-production', 'state' => 'Active']),
            new Result(['domainName' => 'abc.on.aws', 'state' => 'Active', 'revisionWeights' => [['revisionId' => 'rev-1', 'weight' => 100]]]),
            new Result(['revisionId' => 'rev-2', 'state' => 'Pending']),
            new Result(['state' => 'Active', 'stateReason' => 'ok']),
            new Result(['state' => 'Active', 'domainName' => 'abc.on.aws', 'revisionWeights' => [['revisionId' => 'rev-1', 'weight' => 100]]]),
            new Result([]),
            new Result(['state' => 'Active', 'updateStatus' => 'Successful', 'domainName' => 'abc.on.aws', 'revisionWeights' => [['revisionId' => 'rev-2', 'weight' => 100]]]),
            new Result(['Contents' => []]),                   // ListObjectsV2 (web zips only)
            new Result(['ResourceTagMappingList' => []]),     // GetResources (runtime binaries)
            new Result(['Contents' => []]),                   // ListObjectsV2 (runtime binaries)
        );
        $steps = [];
        $deployer = new AwsDeployer($mocks->clients(), AwsMocks::noSleep());

        $prepared = $deployer->prepare($this->config(), $this->package(), null, [], function (string $key) use (&$steps): void { $steps[] = $key; });
        $result = $deployer->release($this->config(), $prepared);
        $deployer->prune($this->config(), $prepared);

        self::assertNull($prepared->consoleVersion);
        self::assertNull($result->consoleVersion);
        self::assertNotContains('deploy.step_console', $steps);
        $policy = json_decode($mocks->params[8]['PolicyDocument'], true);
        self::assertContains('ArcoRuntimeBinary', array_column($policy['Statement'], 'Sid'), 'the console left in place may still download its binary');
        self::assertSame([], array_values(array_intersect(['GetAlias', 'CreateFunction', 'UpdateFunctionCode', 'PublishVersion', 'UpdateAlias', 'GetAlias', 'ListVersionsByFunction'], $mocks->calls)));
        self::assertCount(0, $mocks->handler);
    }

    public function test_rollback_routes_traffic_and_the_console_alias_back(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['state' => 'Active', 'domainName' => 'x', 'revisionWeights' => [['revisionId' => 'rev-2', 'weight' => 100]]]),
            new Result([]),
            new Result(['state' => 'Active', 'updateStatus' => 'Successful', 'domainName' => 'x', 'revisionWeights' => [['revisionId' => 'rev-1', 'weight' => 100]]]),
            new Result([]),                                   // UpdateAlias
            new Result([]),                                   // PutFunctionEventInvokeConfig
        );

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->rollback($this->config(), 'rev-1', '6');

        self::assertSame(['GetWebFunctionEndpoint', 'UpdateWebFunctionEndpoint', 'GetWebFunctionEndpoint', 'UpdateAlias', 'PutFunctionEventInvokeConfig'], $mocks->calls);
        self::assertSame([['revisionId' => 'rev-1', 'weight' => 100]], $mocks->params[1]['revisionWeights']);
        self::assertSame('6', $mocks->params[3]['FunctionVersion']);
    }

    public function test_without_domains_nothing_about_domains_is_called(): void
    {
        $mocks = new AwsMocks();
        $result = new DeployResult('abc.on.aws', 'rev-1', null, 'b', 'k');

        self::assertSame($result, (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->attachDomains($this->config(), $result, ['/build/*']));
        self::assertSame([], $mocks->calls);
    }

    public function test_remove_without_domains_tolerates_missing_cloudformation_permissions(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            AwsMocks::error(CloudFormationException::class, 'DescribeStacks', 'AccessDenied', 403),
            AwsMocks::error(EventBridgeException::class, 'DescribeRule', 'AccessDeniedException', 400), // no events: access, no rule
            new Result(['ResourceTagMappingList' => []]),                                       // GetResources: no workers
            AwsMocks::error(CloudFormationException::class, 'DescribeStacks', 'AccessDenied', 403), // no resources stack
            self::lambdaMissing('DeleteFunction'),                                              // console never deployed
            new Result([]),                                                                     // DeleteWebFunction
            AwsMocks::error(LambdaWebException::class, 'GetWebFunction', 'ResourceNotFoundException', 404),
            new Result([]), new Result([]),                                                     // DeleteRolePolicy, DeleteRole
        );
        $steps = [];

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->remove($this->config(), function (string $key) use (&$steps): void { $steps[] = $key; });

        self::assertSame(['DescribeStacks', 'DescribeRule', 'GetResources', 'DescribeStacks', 'DeleteFunction', 'DeleteWebFunction', 'GetWebFunction', 'DeleteRolePolicy', 'DeleteRole'], $mocks->calls);
        self::assertSame('shop-production-console', $mocks->params[4]['FunctionName']);
        self::assertSame(['remove.scheduler_unreadable', 'remove.step_console', 'remove.step_function'], $steps, 'an unreadable rule is a warning, and the remove goes on');
        self::assertContains('remove.scheduler_unreadable', \Multiapi\Arco\Cli\StepPrinter::WARNINGS);
    }

    public function test_remove_with_domains_removes_the_distribution_before_the_function(): void
    {
        $config = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->environment('production', fn (Environment $e) => $e->domain('a.example.com')->dns(false))->toArray(), 'production');
        $missing = fn () => AwsMocks::error(CloudFormationException::class, 'DescribeStacks', 'ValidationError', 400, 'Stack with id arco-shop-production does not exist');
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['Stacks' => [['StackName' => 'arco-shop-production', 'StackStatus' => 'CREATE_COMPLETE', 'Tags' => [['Key' => 'arco:app', 'Value' => 'shop'], ['Key' => 'arco:environment', 'Value' => 'production']], 'Outputs' => [['OutputKey' => 'DistributionDomain', 'OutputValue' => 'd1.cloudfront.net']]]]]),
            new Result(['Stacks' => [['StackName' => 'arco-shop-production', 'StackStatus' => 'CREATE_COMPLETE']]]),
            new Result([]),                                                                     // DeleteStack
            $missing(),
            new Result(['CertificateSummaryList' => []]),
            self::noRule(),
            new Result(['ResourceTagMappingList' => []]),                                       // GetResources: no workers
            AwsMocks::error(CloudFormationException::class, 'DescribeStacks', 'ValidationError', 400, 'Stack with id arco-shop-production-resources does not exist'),
            new Result([]),                                                                     // DeleteFunction (console)
            new Result([]),
            AwsMocks::error(LambdaWebException::class, 'GetWebFunction', 'ResourceNotFoundException', 404),
            new Result([]), new Result([]),
        );
        $steps = [];

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->remove($config, function (string $key) use (&$steps): void { $steps[] = $key; });

        self::assertSame(['DescribeStacks', 'DescribeStacks', 'DeleteStack', 'DescribeStacks', 'ListCertificates', 'DescribeRule', 'GetResources', 'DescribeStacks', 'DeleteFunction', 'DeleteWebFunction', 'GetWebFunction', 'DeleteRolePolicy', 'DeleteRole'], $mocks->calls);
        self::assertSame(['remove.step_distribution', 'remove.step_console', 'remove.step_function'], $steps);
    }

    public function test_describe_includes_the_console_version_and_the_distribution(): void
    {
        $config = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->console()->environment('production', fn (Environment $e) => $e->domain('a.example.com'))->toArray(), 'production');
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['functionName' => 'shop-production', 'state' => 'Active']),
            new Result(['domainName' => 'abc.on.aws', 'state' => 'Active', 'revisionWeights' => [['revisionId' => 'rev-1', 'weight' => 100]]]),
            new Result(['FunctionVersion' => '7']),                                              // GetAlias
            new Result(['Stacks' => [['StackName' => 'arco-shop-production', 'StackStatus' => 'UPDATE_COMPLETE', 'Outputs' => [['OutputKey' => 'DistributionDomain', 'OutputValue' => 'd1.cloudfront.net']]]]]),
            new Result(['functionName' => 'shop-production', 'state' => 'Active']),
            new Result(['domainName' => 'abc.on.aws', 'state' => 'Active', 'revisionWeights' => []]),
            AwsMocks::error(LambdaException::class, 'GetAlias', 'AccessDeniedException', 403),
            AwsMocks::error(CloudFormationException::class, 'DescribeStacks', 'AccessDenied', 403),
        );
        $deployer = new AwsDeployer($mocks->clients(), AwsMocks::noSleep());

        $status = $deployer->describe($config);
        self::assertSame('shop-production-console', $status?->consoleFunction);
        self::assertSame('7', $status?->consoleVersion);
        self::assertNull($status?->consoleVersionError);
        self::assertSame('d1.cloudfront.net', $status?->distributionDomain);
        self::assertSame('UPDATE_COMPLETE', $status?->distributionStatus);

        $status = $deployer->describe($config);
        self::assertSame('abc.on.aws', $status?->domainName, 'still described without Lambda or CloudFormation read access');
        self::assertNull($status?->consoleVersion);
        self::assertSame('AccessDeniedException', $status?->consoleVersionError, 'unknown, not "not deployed yet"');
        self::assertNull($status?->distributionDomain);
    }

    public function test_describe_does_not_look_up_the_console_when_it_is_off(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['functionName' => 'shop-production', 'state' => 'Active']),
            new Result(['domainName' => 'abc.on.aws', 'state' => 'Active', 'revisionWeights' => []]),
        );

        $status = (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->describe($this->config());

        self::assertNull($status?->consoleFunction);
        self::assertNotContains('GetAlias', $mocks->calls);
    }

    public function test_remove_after_domain_was_dropped_cleans_aliases_in_the_dns_account(): void
    {
        // domain() removed from arco.php, dns() kept; the stack remembers its aliases.
        $config = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->environment('production', fn (Environment $e) => $e->dns('common'))->toArray(), 'production');
        $stack = fn (string $status) => new Result(['Stacks' => [['StackName' => 'arco-shop-production', 'StackStatus' => $status,
            'Tags' => [['Key' => 'arco:app', 'Value' => 'shop'], ['Key' => 'arco:environment', 'Value' => 'production']],
            'Outputs' => [['OutputKey' => 'DistributionDomain', 'OutputValue' => 'd1.cloudfront.net'], ['OutputKey' => 'Aliases', 'OutputValue' => 'arco.acmeapp.io']]]]]);
        $alias = ['Name' => 'arco.acmeapp.io.', 'Type' => 'A', 'AliasTarget' => ['HostedZoneId' => 'Z2FDTNDATAQYW2', 'DNSName' => 'd1.cloudfront.net.', 'EvaluateTargetHealth' => false]];
        $mocks = new AwsMocks();
        $mocks->handler->append(
            $stack('CREATE_COMPLETE'),                                                          // stack exists?
            $stack('CREATE_COMPLETE'),                                                          // CustomDomains::remove
            $stack('CREATE_COMPLETE'), new Result([]),                                          // Stacks::delete
            AwsMocks::error(CloudFormationException::class, 'DescribeStacks', 'ValidationError', 400, 'Stack with id arco-shop-production does not exist'),
            new Result(['CertificateSummaryList' => []]),
            self::noRule(),
            new Result(['ResourceTagMappingList' => []]),                                       // GetResources: no workers
            AwsMocks::error(CloudFormationException::class, 'DescribeStacks', 'ValidationError', 400, 'Stack with id arco-shop-production-resources does not exist'),
            new Result([]),                                                                     // DeleteFunction (console)
            new Result([]),
            AwsMocks::error(LambdaWebException::class, 'GetWebFunction', 'ResourceNotFoundException', 404),
            new Result([]), new Result([]),
        );
        $dns = new AwsMocks();
        $dns->handler->append(
            new Result(['HostedZones' => [['Id' => '/hostedzone/Z1', 'Name' => 'acmeapp.io.', 'Config' => ['PrivateZone' => false]]], 'IsTruncated' => false]),
            new Result(['ResourceRecordSets' => [$alias]]),
            new Result(['ChangeInfo' => ['Id' => '/change/R', 'Status' => 'INSYNC']]),
            new Result(['ChangeInfo' => ['Id' => '/change/R', 'Status' => 'INSYNC']]),
        );

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep(), $dns->clients()))->remove($config);

        self::assertSame(['ListHostedZonesByName', 'ListResourceRecordSets', 'ChangeResourceRecordSets', 'GetChange'], $dns->calls);
        self::assertSame([['Action' => 'DELETE', 'ResourceRecordSet' => $alias]], $dns->params[2]['ChangeBatch']['Changes']);
        self::assertContains('DeleteStack', $mocks->calls);
        self::assertCount(0, $mocks->handler);
    }

    public function test_has_domain_resources(): void
    {
        $withDomains = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->environment('production', fn (Environment $e) => $e->domain('a.example.com'))->toArray(), 'production');
        $missing = fn () => AwsMocks::error(CloudFormationException::class, 'DescribeStacks', 'ValidationError', 400, 'Stack with id arco-shop-production does not exist');
        $mocks = new AwsMocks();
        $mocks->handler->append(
            $missing(),                                                                          // no domains, no stack
            $missing(),                                                                          // domains, no stack...
            new Result(['CertificateSummaryList' => [['CertificateArn' => 'arn:c']]]),           // ...but a managed certificate
            new Result(['Tags' => [['Key' => 'arco:app', 'Value' => 'shop'], ['Key' => 'arco:environment', 'Value' => 'production'], ['Key' => 'arco:managed', 'Value' => 'true']]]),
            new Result(['Certificate' => ['CertificateArn' => 'arn:c', 'Status' => 'ISSUED']]),
        );
        $deployer = new AwsDeployer($mocks->clients(), AwsMocks::noSleep());

        self::assertFalse($deployer->hasDomainResources($this->config()));
        self::assertTrue($deployer->hasDomainResources($withDomains));
    }

    public function test_remove_console(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['Name' => 'arco-shop-production-scheduler']),                          // DescribeRule: a rule is left
            new Result(['FailedEntryCount' => 0]), new Result([]), new Result([]),              // RemoveTargets, DeleteRule, RemovePermission
            new Result([]),                                                                     // DeleteFunction
            self::noRule(),
            self::lambdaMissing('DeleteFunction'),
        );
        $deployer = new AwsDeployer($mocks->clients(), AwsMocks::noSleep());
        $steps = [];
        $onStep = function (string $key, array $params) use (&$steps): void { $steps[] = [$key, $params]; };

        $deployer->removeConsole($this->config(), $onStep);
        $deployer->removeConsole($this->config(), $onStep); // already gone: ResourceNotFoundException is swallowed

        self::assertSame(['DescribeRule', 'RemoveTargets', 'DeleteRule', 'RemovePermission', 'DeleteFunction', 'DescribeRule', 'DeleteFunction'], $mocks->calls, 'the rule goes before the function it invokes');
        self::assertSame('shop-production-console', $mocks->params[4]['FunctionName']);
        self::assertSame([
            ['deploy.scheduler_removed', []],
            ['deploy.console_removed', ['function' => 'shop-production-console']],
            ['deploy.console_removed', ['function' => 'shop-production-console']],
        ], $steps);
    }

    public function test_remove_deletes_scheduler_rule_before_console(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            AwsMocks::error(CloudFormationException::class, 'DescribeStacks', 'AccessDenied', 403),
            new Result(['Name' => 'arco-shop-production-scheduler']),                          // DescribeRule
            new Result(['FailedEntryCount' => 0]), new Result([]), new Result([]),              // RemoveTargets, DeleteRule, RemovePermission
            new Result(['ResourceTagMappingList' => []]),                                       // GetResources: no workers
            AwsMocks::error(CloudFormationException::class, 'DescribeStacks', 'AccessDenied', 403), // no resources stack
            new Result([]),                                                                     // DeleteFunction (console)
            new Result([]),                                                                     // DeleteWebFunction
            AwsMocks::error(LambdaWebException::class, 'GetWebFunction', 'ResourceNotFoundException', 404),
            new Result([]), new Result([]),                                                     // DeleteRolePolicy, DeleteRole
        );
        $steps = [];

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->remove($this->config(), function (string $key) use (&$steps): void { $steps[] = $key; });

        self::assertSame(['DescribeStacks', 'DescribeRule', 'RemoveTargets', 'DeleteRule', 'RemovePermission', 'GetResources', 'DescribeStacks', 'DeleteFunction', 'DeleteWebFunction', 'GetWebFunction', 'DeleteRolePolicy', 'DeleteRole'], $mocks->calls);
        self::assertSame('arco-shop-production-scheduler', $mocks->params[3]['Name']);
        self::assertSame('shop-production-console', $mocks->params[7]['FunctionName']);
        self::assertSame(['remove.step_scheduler', 'remove.step_console', 'remove.step_function'], $steps);
    }

    public function test_sync_scheduler_points_the_rule_at_the_live_alias(): void
    {
        $config = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->scheduler(5)->environment('production')->toArray(), 'production');
        $alias = 'arn:aws:lambda:us-east-1:123456789012:function:shop-production-console:live';
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['AliasArn' => $alias, 'FunctionVersion' => '7']),
            AwsMocks::error(EventBridgeException::class, 'DescribeRule', 'ResourceNotFoundException', 400),
            new Result(['RuleArn' => 'arn:aws:events:us-east-1:123456789012:rule/arco-shop-production-scheduler']),
            new Result(['FailedEntryCount' => 0]),
            new Result(['Statement' => '{}']),
        );
        $steps = [];

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->syncScheduler($config, function (string $key, array $params) use (&$steps): void { $steps[] = [$key, $params]; });

        self::assertSame(['GetAlias', 'DescribeRule', 'PutRule', 'PutTargets', 'AddPermission'], $mocks->calls);
        self::assertSame(['FunctionName' => 'shop-production-console', 'Name' => 'live'], array_intersect_key($mocks->params[0], ['FunctionName' => 1, 'Name' => 1]));
        self::assertSame([['Key' => 'arco:app', 'Value' => 'shop'], ['Key' => 'arco:environment', 'Value' => 'production']], $mocks->params[2]['Tags']);
        self::assertSame('ENABLED', $mocks->params[2]['State'], 'a new rule starts enabled');
        self::assertSame($alias, $mocks->params[3]['Targets'][0]['Arn']);
        self::assertSame([['deploy.scheduler_set', ['every' => 5]]], $steps);
    }

    public function test_sync_scheduler_keeps_a_paused_rule_paused(): void
    {
        $config = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->scheduler(5)->environment('production')->toArray(), 'production');
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['AliasArn' => 'arn:alias', 'FunctionVersion' => '7']),
            new Result(['Name' => 'arco-shop-production-scheduler', 'State' => 'DISABLED']),
            new Result(['RuleArn' => 'arn:rule']),
            new Result(['FailedEntryCount' => 0]),
            new Result([]),
        );
        $steps = [];

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->syncScheduler($config, function (string $key, array $params) use (&$steps): void { $steps[] = [$key, $params]; });

        self::assertSame('DISABLED', $mocks->params[2]['State']);
        self::assertSame([['deploy.scheduler_set', ['every' => 5]], ['deploy.scheduler_paused', ['environment' => 'production']]], $steps);
        self::assertNotContains('deploy.scheduler_paused', \Multiapi\Arco\Cli\StepPrinter::WARNINGS, 'a note, not a warning');
    }

    public function test_describe_reads_whether_the_scheduler_is_paused(): void
    {
        $with = fn (?int $every) => ConfigResolver::resolve(($every === null ? App::make('shop') : App::make('shop')->scheduler($every))->region('us-east-1')->environment('production')->toArray(), 'production');
        $web = fn () => [new Result(['functionName' => 'shop-production', 'state' => 'Active']), new Result(['domainName' => 'abc.on.aws', 'state' => 'Active', 'revisionWeights' => []])];
        $mocks = new AwsMocks();
        $alias = new Result(['FunctionVersion' => '7']);
        $mocks->handler->append(...[
            ...$web(), $alias, new Result(['State' => 'DISABLED']),
            ...$web(), $alias, new Result(['State' => 'ENABLED']),
            ...$web(), $alias, AwsMocks::error(EventBridgeException::class, 'DescribeRule', 'ResourceNotFoundException', 400),
            ...$web(), $alias, AwsMocks::error(EventBridgeException::class, 'DescribeRule', 'AccessDeniedException', 400),
            ...$web(),
        ]);
        $deployer = new AwsDeployer($mocks->clients(), AwsMocks::noSleep());

        self::assertTrue($deployer->describe($with(5))?->schedulerPaused);
        self::assertFalse($deployer->describe($with(5))?->schedulerPaused);
        self::assertNull($deployer->describe($with(5))?->schedulerPaused, 'no rule');
        self::assertNull($deployer->describe($with(5))?->schedulerPaused, 'unreadable');
        $before = count($mocks->calls);
        self::assertNull($deployer->describe($with(null))?->schedulerPaused);
        self::assertNotContains('DescribeRule', array_slice($mocks->calls, $before), 'no scheduler: no lookup');
        self::assertCount(0, $mocks->handler);
    }

    public function test_apply_log_retention(): void
    {
        $with = function (int|false|null $days, bool $console): AppConfig {
            $app = App::make('shop')->console($console)->region('us-east-1');
            if ($days !== null) {
                $app->logRetention($days);
            }
            return ConfigResolver::resolve($app->environment('production')->toArray(), 'production');
        };

        $mocks = new AwsMocks();
        $mocks->handler->append(...array_fill(0, 6, new Result()));
        $steps = [];
        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->applyLogRetention($with(30, true), function (string $key, array $params) use (&$steps): void { $steps[] = [$key, $params]; });
        self::assertSame(['CreateLogGroup', 'PutRetentionPolicy', 'CreateLogGroup', 'PutRetentionPolicy', 'CreateLogGroup', 'PutRetentionPolicy'], $mocks->calls);
        self::assertSame('/aws/lambda/shop-production-console', $mocks->params[4]['logGroupName']);
        self::assertSame([['deploy.log_retention', ['days' => 30, 'groups' => 'all']]], $steps);

        // Console off: only the web groups. false: policies are removed.
        $mocks = new AwsMocks();
        $mocks->handler->append(...array_fill(0, 4, new Result()));
        $steps = [];
        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->applyLogRetention($with(false, false), function (string $key, array $params) use (&$steps): void { $steps[] = [$key, $params]; });
        self::assertSame(['CreateLogGroup', 'DeleteRetentionPolicy', 'CreateLogGroup', 'DeleteRetentionPolicy'], $mocks->calls);
        self::assertSame([['deploy.log_retention_never', ['groups' => 'web']]], $steps);

        // One day: its own key, so the line is not "1 days".
        $mocks = new AwsMocks();
        $mocks->handler->append(...array_fill(0, 4, new Result()));
        $steps = [];
        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->applyLogRetention($with(1, false), function (string $key, array $params) use (&$steps): void { $steps[] = [$key, $params]; });
        self::assertSame([['deploy.log_retention_one_day', ['groups' => 'web']]], $steps);

        // Not managed: no AWS call at all.
        $mocks = new AwsMocks();
        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->applyLogRetention($with(null, true), fn () => null);
        self::assertSame([], $mocks->calls);
    }

    public function test_log_retention_covers_the_queue_workers(): void
    {
        $with = function (bool $console): AppConfig {
            $app = App::make('shop')->console($console)->region('us-east-1')->logRetention(14)->queue('emails')->queue('reports');
            return ConfigResolver::resolve($app->environment('production')->toArray(), 'production');
        };

        // Console off: web plus each worker's group, never the console's.
        $mocks = new AwsMocks();
        $mocks->handler->append(...array_fill(0, 8, new Result()));
        $steps = [];
        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->applyLogRetention($with(false), function (string $key, array $params) use (&$steps): void { $steps[] = [$key, $params]; });
        $groups = array_values(array_unique(array_column($mocks->params, 'logGroupName')));
        self::assertSame(['/aws/lambda/web/shop-production', '/aws/lambda/web/shop-production.local', '/aws/lambda/shop-production-queue-emails', '/aws/lambda/shop-production-queue-reports'], $groups);
        self::assertSame([['deploy.log_retention', ['days' => 14, 'groups' => 'web_queues']]], $steps);

        // Console on: web, console and workers.
        $mocks = new AwsMocks();
        $mocks->handler->append(...array_fill(0, 10, new Result()));
        $steps = [];
        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->applyLogRetention($with(true), function (string $key, array $params) use (&$steps): void { $steps[] = [$key, $params]; });
        self::assertContains('/aws/lambda/shop-production-console', array_column($mocks->params, 'logGroupName'));
        self::assertContains('/aws/lambda/shop-production-queue-reports', array_column($mocks->params, 'logGroupName'));
        self::assertSame([['deploy.log_retention', ['days' => 14, 'groups' => 'all_queues']]], $steps);

        $translator = new \Multiapi\Arco\Cli\Translator('en', \Multiapi\Arco\Arco::packageRoot() . '/lang');
        self::assertSame('web, console and queue worker', $translator->trans('deploy.log_groups.all_queues'));
        self::assertSame('web and queue worker', $translator->trans('deploy.log_groups.web_queues'));
    }

    public function test_describe_includes_the_queue_urls_and_worker_versions(): void
    {
        $config = ConfigResolver::resolve(App::make('shop')->region('us-east-1')
            ->queue('emails')
            ->queue('legacy', existing: 'arn:aws:sqs:us-east-1:222222222222:legacy-jobs', dlq: false)
            ->environment('production')->toArray(), 'production');
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['functionName' => 'shop-production', 'state' => 'Active']),
            new Result(['domainName' => 'abc.on.aws', 'state' => 'Active', 'revisionWeights' => []]),
            new Result(['Account' => '111111111111']),                                           // GetCallerIdentity
            new Result(['FunctionVersion' => '4']),                                              // GetAlias emails
            AwsMocks::error(LambdaException::class, 'GetAlias', 'ResourceNotFoundException', 404), // legacy: not deployed
        );

        $status = (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->describe($config);

        self::assertSame([
            'emails' => ['url' => 'https://sqs.us-east-1.amazonaws.com/111111111111/shop-production-emails', 'version' => '4', 'error' => null],
            'legacy' => ['url' => 'https://sqs.us-east-1.amazonaws.com/222222222222/legacy-jobs', 'version' => null, 'error' => null],
        ], $status?->queues);
        self::assertSame('shop-production-queue-emails', $mocks->params[3]['FunctionName']);
    }

    public function test_describe_reads_the_account_for_a_created_storage_only(): void
    {
        $resolve = fn (App $app) => ConfigResolver::resolve($app->environment('production')->toArray(), 'production');
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['functionName' => 'shop-production', 'state' => 'Active']),
            new Result(['domainName' => 'abc.on.aws', 'state' => 'Active', 'revisionWeights' => []]),
            new Result(['Account' => '111111111111']),
            new Result(['functionName' => 'shop-production', 'state' => 'Active']),
            new Result(['domainName' => 'abc.on.aws', 'state' => 'Active', 'revisionWeights' => []]),
        );
        $deployer = new AwsDeployer($mocks->clients(), AwsMocks::noSleep());

        self::assertSame('111111111111', $deployer->describe($resolve(App::make('shop')->region('us-east-1')->storage('uploads')))?->account);
        self::assertNull($deployer->describe($resolve(App::make('shop')->region('us-east-1')->storage('legacy', existing: 'old-bucket')->cache()))?->account);
        self::assertSame(['GetCallerIdentity'], array_values(array_filter($mocks->calls, fn (string $c) => $c === 'GetCallerIdentity')), 'no account lookup for existing buckets and tables');
        self::assertSame(5, count($mocks->calls));
    }

    public function test_sync_scheduler_off_deletes_a_left_rule_only(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['Name' => SchedulerRule::name($this->config())]),
            new Result(['FailedEntryCount' => 0]), new Result([]), new Result([]),
            self::noRule(),
            AwsMocks::error(EventBridgeException::class, 'DescribeRule', 'AccessDeniedException', 400),
            self::lambdaMissing('GetPolicy'),
        );
        $deployer = new AwsDeployer($mocks->clients(), AwsMocks::noSleep());
        $steps = [];
        $onStep = function (string $key) use (&$steps): void { $steps[] = $key; };

        $deployer->syncScheduler($this->config(), $onStep);
        $deployer->syncScheduler($this->config(), $onStep); // no rule: nothing to say
        $deployer->syncScheduler($this->config(), $onStep); // no events: access and no invoke statement on the alias: nothing either

        self::assertSame(['DescribeRule', 'RemoveTargets', 'DeleteRule', 'RemovePermission', 'DescribeRule', 'DescribeRule', 'GetPolicy'], $mocks->calls);
        self::assertSame(['FunctionName' => 'shop-production-console', 'Qualifier' => 'live'], array_intersect_key($mocks->params[6], ['FunctionName' => 1, 'Qualifier' => 1]));
        self::assertSame(['deploy.scheduler_removed'], $steps);
    }

    public function test_sync_scheduler_off_warns_when_the_rule_cannot_be_read_but_left_its_statement(): void
    {
        $denied = fn () => AwsMocks::error(EventBridgeException::class, 'DescribeRule', 'AccessDeniedException', 400, 'not authorized to perform: events:DescribeRule');
        $policy = fn (string $sid) => new Result(['Policy' => json_encode(['Version' => '2012-10-17', 'Statement' => [['Sid' => $sid, 'Effect' => 'Allow', 'Principal' => ['Service' => 'events.amazonaws.com'], 'Action' => 'lambda:InvokeFunction']]])]);
        $mocks = new AwsMocks();
        $mocks->handler->append($denied(), $policy('arco-scheduler'), $denied(), $policy('someone-else'));
        $deployer = new AwsDeployer($mocks->clients(), AwsMocks::noSleep());
        $steps = [];
        $onStep = function (string $key, array $params) use (&$steps): void { $steps[] = [$key, $params]; };

        $deployer->syncScheduler($this->config(), $onStep);
        $deployer->syncScheduler($this->config(), $onStep); // another statement only: no evidence of an Arco rule

        self::assertSame(['DescribeRule', 'GetPolicy', 'DescribeRule', 'GetPolicy'], $mocks->calls);
        self::assertSame([['deploy.scheduler_unreadable', [
            'rule' => 'arco-shop-production-scheduler',
            'function' => 'shop-production-console',
            'error' => 'not authorized to perform: events:DescribeRule',
        ]]], $steps);
        self::assertContains('deploy.scheduler_unreadable', \Multiapi\Arco\Cli\StepPrinter::WARNINGS);
    }

    public function test_remove_console_warns_when_the_rule_cannot_be_read_and_still_deletes_the_function(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            AwsMocks::error(EventBridgeException::class, 'DescribeRule', 'AccessDeniedException', 400, 'not authorized to perform: events:DescribeRule'),
            new Result([]),                                                                     // DeleteFunction
        );
        $steps = [];

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->removeConsole($this->config(), function (string $key, array $params) use (&$steps): void { $steps[] = [$key, $params]; });

        self::assertSame(['DescribeRule', 'DeleteFunction'], $mocks->calls);
        self::assertSame([
            ['deploy.scheduler_unreadable', ['rule' => 'arco-shop-production-scheduler', 'function' => 'shop-production-console', 'error' => 'not authorized to perform: events:DescribeRule']],
            ['deploy.console_removed', ['function' => 'shop-production-console']],
        ], $steps);
    }

    public function test_console_exists(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(new Result(['FunctionName' => 'shop-production-console']), self::lambdaMissing('GetFunctionConfiguration'));
        $deployer = new AwsDeployer($mocks->clients(), AwsMocks::noSleep());

        self::assertTrue($deployer->consoleExists($this->config()));
        self::assertFalse($deployer->consoleExists($this->config()));
        self::assertSame(['GetFunctionConfiguration', 'GetFunctionConfiguration'], $mocks->calls);
        self::assertSame('shop-production-console', $mocks->params[0]['FunctionName']);
    }

    private function queueConfig(bool $withQueue = true): AppConfig
    {
        $app = App::make('shop')->region('us-east-1');
        if ($withQueue) {
            $app = $app->queue('emails', timeout: 120);
        }
        return ConfigResolver::resolve($app->environment('production')->toArray(), 'production');
    }

    private static function stackMissing(): CloudFormationException
    {
        return AwsMocks::error(CloudFormationException::class, 'DescribeStacks', 'ValidationError', 400, 'Stack with id arco-shop-production-resources does not exist');
    }

    private static function noQueue(string $operation = 'GetQueueUrl'): SqsException
    {
        return AwsMocks::error(SqsException::class, $operation, 'AWS.SimpleQueueService.NonExistentQueue', 400);
    }

    private static function resourcesStack(string $status = 'UPDATE_COMPLETE', string $app = 'shop'): Result
    {
        return new Result(['Stacks' => [[
            'StackName' => 'arco-shop-production-resources',
            'StackStatus' => $status,
            'Tags' => [['Key' => 'arco:app', 'Value' => $app], ['Key' => 'arco:environment', 'Value' => 'production']],
        ]]]);
    }

    /** @param array<string, string> $logical physical queue name by logical id */
    private static function stackResources(array $logical): Result
    {
        return new Result(['StackResources' => array_map(fn (string $id, string $name) => [
            'LogicalResourceId' => $id,
            'PhysicalResourceId' => "https://sqs.us-east-1.amazonaws.com/111111111111/$name",
            'ResourceType' => 'AWS::SQS::Queue',
            'ResourceStatus' => 'CREATE_COMPLETE',
        ], array_keys($logical), $logical)]);
    }

    /**
     * @return array{0: list<string>, 1: list<array{0: string, 1: array<string, string|int>}>}
     */
    private function deployResources(AwsMocks $mocks, AppConfig $config): array
    {
        $steps = [];
        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->deployResources($config, function (string $key, array $params) use (&$steps): void { $steps[] = [$key, $params]; });
        return [$mocks->calls, $steps];
    }

    public function test_deploy_resources_creates_the_stack_with_the_queues(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::stackMissing(),
            self::noQueue(), self::noQueue(),                    // queue and DLQ names are free
            self::stackMissing(),                                // Stacks::deploy
            new Result([]),                                      // CreateStack
            self::resourcesStack('CREATE_COMPLETE'),
        );

        [$calls, $steps] = $this->deployResources($mocks, $this->queueConfig());

        self::assertSame(['DescribeStacks', 'GetQueueUrl', 'GetQueueUrl', 'DescribeStacks', 'CreateStack', 'DescribeStacks'], $calls);
        self::assertSame('shop-production-emails', $mocks->params[1]['QueueName']);
        self::assertSame('shop-production-emails-dlq', $mocks->params[2]['QueueName']);
        self::assertSame('arco-shop-production-resources', $mocks->params[4]['StackName']);
        self::assertSame([['Key' => 'arco:app', 'Value' => 'shop'], ['Key' => 'arco:environment', 'Value' => 'production']], $mocks->params[4]['Tags']);
        $template = json_decode((string) $mocks->params[4]['TemplateBody'], true);
        self::assertIsArray($template);
        self::assertSame(['QueueEmails', 'QueueEmailsDlq'], array_keys($template['Resources']));
        self::assertSame([
            ['deploy.step_resources', ['stack' => 'arco-shop-production-resources']],
            ['deploy.queue_created', ['queue' => 'emails', 'name' => 'shop-production-emails']],
        ], $steps);
    }

    public function test_deploy_resources_stops_before_any_change_when_a_queue_name_is_retained_in_aws(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::stackMissing(),
            new Result(['QueueUrl' => 'https://sqs.us-east-1.amazonaws.com/111111111111/shop-production-emails']),
        );

        try {
            $this->deployResources($mocks, $this->queueConfig());
            self::fail('expected deploy.queue_retained');
        } catch (DeployException $e) {
            self::assertSame('deploy.queue_retained', $e->key);
            self::assertSame([
                'queue' => 'emails',
                'existing' => "queue('emails', existing: 'arn:aws:sqs:us-east-1:111111111111:shop-production-emails')",
            ], $e->params);
        }
        self::assertSame(['DescribeStacks', 'GetQueueUrl'], $mocks->calls, 'no CreateStack or UpdateStack');
    }

    public function test_deploy_resources_stops_when_only_the_dlq_name_is_retained(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::stackMissing(),
            self::noQueue(),
            new Result(['QueueUrl' => 'https://sqs.us-east-1.amazonaws.com/111111111111/shop-production-emails-dlq']),
        );

        try {
            $this->deployResources($mocks, $this->queueConfig());
            self::fail('expected deploy.queue_dlq_retained');
        } catch (DeployException $e) {
            self::assertSame('deploy.queue_dlq_retained', $e->key);
            self::assertSame(['queue' => 'emails', 'dlq' => 'shop-production-emails-dlq'], $e->params);
        }
        self::assertSame(['DescribeStacks', 'GetQueueUrl', 'GetQueueUrl'], $mocks->calls);
    }

    public function test_deploy_resources_retains_a_removed_queue_and_names_its_messages(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::resourcesStack(),
            self::stackResources(['QueueEmails' => 'shop-production-emails', 'QueueEmailsDlq' => 'shop-production-emails-dlq', 'QueueOld' => 'shop-production-old']),
            self::resourcesStack(),                              // Stacks::deploy
            new Result([]),                                      // UpdateStack
            self::resourcesStack(),
            new Result(['Attributes' => ['ApproximateNumberOfMessages' => '12']]),
        );

        [$calls, $steps] = $this->deployResources($mocks, $this->queueConfig());

        self::assertSame(['DescribeStacks', 'DescribeStackResources', 'DescribeStacks', 'UpdateStack', 'DescribeStacks', 'GetQueueAttributes'], $calls, 'queues the stack owns need no name check');
        $template = json_decode((string) $mocks->params[3]['TemplateBody'], true);
        self::assertIsArray($template);
        self::assertSame(['QueueEmails', 'QueueEmailsDlq'], array_keys($template['Resources']));
        self::assertSame('https://sqs.us-east-1.amazonaws.com/111111111111/shop-production-old', $mocks->params[5]['QueueUrl']);
        self::assertSame([
            ['deploy.step_resources', ['stack' => 'arco-shop-production-resources']],
            ['deploy.queue_updated', ['queue' => 'emails', 'name' => 'shop-production-emails']],
            ['deploy.queue_kept', ['queue' => 'shop-production-old', 'url' => 'https://sqs.us-east-1.amazonaws.com/111111111111/shop-production-old', 'messages' => 12]],
        ], $steps);
        self::assertContains('deploy.queue_kept', \Multiapi\Arco\Cli\StepPrinter::WARNINGS);
    }

    public function test_a_created_queue_switched_to_existing_itself_is_not_offered_for_deletion(): void
    {
        $config = ConfigResolver::resolve(
            App::make('shop')->region('us-east-1')
                ->queue('emails', existing: 'shop-production-emails', dlq: false)
                ->environment('production', fn (Environment $e) => $e->account('111111111111'))
                ->toArray(),
            'production',
        );
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::resourcesStack(),
            self::stackResources(['QueueEmails' => 'shop-production-emails', 'QueueEmailsDlq' => 'shop-production-emails-dlq']),
            self::resourcesStack(),                              // Stacks::delete
            new Result([]),                                      // DeleteStack
            self::stackMissing(),
        );

        [$calls, $steps] = $this->deployResources($mocks, $config);

        self::assertSame(['DescribeStacks', 'DescribeStackResources', 'DescribeStacks', 'DeleteStack', 'DescribeStacks'], $calls);
        self::assertSame([
            ['deploy.step_resources', ['stack' => 'arco-shop-production-resources']],
            ['deploy.queue_now_existing', ['queue' => 'shop-production-emails', 'as' => 'emails']],
            ['deploy.queue_dlq_now_existing', ['queue' => 'shop-production-emails-dlq', 'as' => 'emails']],
        ], $steps, 'no advice to delete a queue that is still read, nor its dead-letter queue');
        self::assertNotContains('deploy.queue_now_existing', \Multiapi\Arco\Cli\StepPrinter::WARNINGS);
    }

    public function test_deploy_resources_deletes_the_stack_when_the_last_queue_is_removed_and_keeps_the_queue(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::resourcesStack(),
            self::stackResources(['QueueEmails' => 'shop-production-emails']),
            self::resourcesStack(),                              // Stacks::delete
            new Result([]),                                      // DeleteStack
            self::stackMissing(),
            self::noQueue('GetQueueAttributes'),                 // deleted by hand meanwhile: count unknown
        );

        [$calls, $steps] = $this->deployResources($mocks, $this->queueConfig(false));

        self::assertSame(['DescribeStacks', 'DescribeStackResources', 'DescribeStacks', 'DeleteStack', 'DescribeStacks', 'GetQueueAttributes'], $calls);
        self::assertSame([
            ['deploy.step_resources', ['stack' => 'arco-shop-production-resources']],
            ['deploy.queue_kept', ['queue' => 'shop-production-emails', 'url' => 'https://sqs.us-east-1.amazonaws.com/111111111111/shop-production-emails', 'messages' => '?']],
        ], $steps);
    }

    public function test_deploy_resources_without_queues_or_stack_changes_nothing(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(self::stackMissing());

        [$calls, $steps] = $this->deployResources($mocks, $this->queueConfig(false));

        self::assertSame(['DescribeStacks'], $calls, 'one read, no CloudFormation change');
        self::assertSame([], $steps);
    }

    public function test_deploy_resources_without_queues_tolerates_missing_cloudformation_permissions(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(AwsMocks::error(CloudFormationException::class, 'DescribeStacks', 'AccessDenied', 403));

        [$calls, $steps] = $this->deployResources($mocks, $this->queueConfig(false));

        self::assertSame(['DescribeStacks'], $calls);
        self::assertSame([], $steps);
    }

    public function test_deploy_resources_refuses_a_stack_arco_did_not_create(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(self::resourcesStack('CREATE_COMPLETE', 'other'));

        try {
            $this->deployResources($mocks, $this->queueConfig());
            self::fail('expected stack.not_ours');
        } catch (DeployException $e) {
            self::assertSame('stack.not_ours', $e->key);
        }
        self::assertSame(['DescribeStacks'], $mocks->calls);
    }

    public function test_deploy_resources_stops_before_any_change_when_a_queue_would_be_replaced(): void
    {
        $config = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->queue('emails', fifo: true)->environment('production')->toArray(), 'production');
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::resourcesStack(),
            self::stackResources(['QueueEmails' => 'shop-production-emails', 'QueueEmailsDlq' => 'shop-production-emails-dlq']),
        );

        try {
            $this->deployResources($mocks, $config);
            self::fail('expected deploy.queue_replaced');
        } catch (DeployException $e) {
            self::assertSame('deploy.queue_replaced', $e->key);
            self::assertSame(['queue' => 'emails', 'from' => 'shop-production-emails', 'to' => 'shop-production-emails.fifo'], $e->params);
        }
        self::assertSame(['DescribeStacks', 'DescribeStackResources'], $mocks->calls, 'no CreateStack or UpdateStack');
    }

    public function test_deploy_resources_stops_when_only_the_dlq_would_be_replaced(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::resourcesStack(),
            self::stackResources(['QueueEmails' => 'shop-production-emails', 'QueueEmailsDlq' => 'shop-production-emails-dlq.fifo']),
        );

        try {
            $this->deployResources($mocks, $this->queueConfig());
            self::fail('expected deploy.queue_replaced');
        } catch (DeployException $e) {
            self::assertSame(['queue' => 'emails', 'from' => 'shop-production-emails-dlq.fifo', 'to' => 'shop-production-emails-dlq'], $e->params);
        }
        self::assertNotContains('UpdateStack', $mocks->calls);
    }

    public function test_deploy_resources_with_only_existing_queues_and_no_stack_changes_nothing(): void
    {
        $config = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->queue('legacy', existing: 'legacy', dlq: false)->environment('production')->toArray(), 'production');
        $mocks = new AwsMocks();
        $mocks->handler->append(self::stackMissing());

        [$calls, $steps] = $this->deployResources($mocks, $config);

        self::assertSame(['DescribeStacks'], $calls);
        self::assertSame([], $steps);
    }

    private function resourcesConfig(callable $build): AppConfig
    {
        $app = $build(App::make('shop')->region('us-east-1'));
        return ConfigResolver::resolve($app->environment('production', fn (Environment $e) => $e->account('111111111111'))->toArray(), 'production');
    }

    /** @param array<string, array{0: string, 1: string}> $resources type and physical id by logical id */
    private static function ownedResources(array $resources): Result
    {
        return new Result(['StackResources' => array_map(fn (string $id, array $resource) => [
            'LogicalResourceId' => $id,
            'PhysicalResourceId' => $resource[1],
            'ResourceType' => $resource[0],
            'ResourceStatus' => 'CREATE_COMPLETE',
        ], array_keys($resources), $resources)]);
    }

    private static function noBucket(): S3Exception
    {
        return AwsMocks::error(S3Exception::class, 'HeadBucket', 'NotFound', 404);
    }

    private static function noTable(): DynamoDbException
    {
        return AwsMocks::error(DynamoDbException::class, 'DescribeTable', 'ResourceNotFoundException', 400);
    }

    public function test_deploy_resources_creates_buckets_and_tables(): void
    {
        $config = $this->resourcesConfig(fn (App $app) => $app->storage('uploads', public: ['public/', 'avatars/'])->cache()->failedJobsTable());
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::stackMissing(),
            self::noBucket(),                                    // the bucket name is free
            self::noTable(), self::noTable(),                    // both table names are free
            self::stackMissing(),                                // Stacks::deploy
            new Result([]),                                      // CreateStack
            self::resourcesStack('CREATE_COMPLETE'),
        );

        [$calls, $steps] = $this->deployResources($mocks, $config);

        self::assertSame(['DescribeStacks', 'HeadBucket', 'DescribeTable', 'DescribeTable', 'DescribeStacks', 'CreateStack', 'DescribeStacks'], $calls);
        self::assertSame('shop-production-uploads-111111111111', $mocks->params[1]['Bucket']);
        self::assertSame('shop-production-cache', $mocks->params[2]['TableName']);
        self::assertSame('shop-production-failed-jobs', $mocks->params[3]['TableName']);
        $template = json_decode((string) $mocks->params[5]['TemplateBody'], true);
        self::assertIsArray($template);
        self::assertSame(['StorageUploads', 'StorageUploadsPolicy', 'TableCache', 'TableFailedXjobs'], array_keys($template['Resources']));
        self::assertSame([
            ['deploy.step_resources', ['stack' => 'arco-shop-production-resources']],
            ['deploy.storage_created', ['storage' => 'uploads', 'bucket' => 'shop-production-uploads-111111111111']],
            ['deploy.storage_public', ['storage' => 'uploads', 'prefixes' => 'public/, avatars/', 'url' => 'https://shop-production-uploads-111111111111.s3.us-east-1.amazonaws.com']],
            ['deploy.table_created', ['kind' => 'cache', 'name' => 'shop-production-cache']],
            ['deploy.table_created', ['kind' => 'failed-jobs', 'name' => 'shop-production-failed-jobs']],
        ], $steps);
    }

    public function test_deploy_resources_stops_before_any_change_when_a_bucket_name_is_retained(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(self::stackMissing(), new Result([]));    // HeadBucket 200: a bucket of this account

        try {
            $this->deployResources($mocks, $this->resourcesConfig(fn (App $app) => $app->storage('uploads')));
            self::fail('expected deploy.storage_retained');
        } catch (DeployException $e) {
            self::assertSame('deploy.storage_retained', $e->key);
            self::assertSame(['storage' => 'uploads', 'existing' => "storage('uploads', existing: 'shop-production-uploads-111111111111')"], $e->params);
        }
        self::assertSame(['DescribeStacks', 'HeadBucket'], $mocks->calls, 'no CreateStack or UpdateStack');
    }

    public function test_deploy_resources_stops_before_any_change_when_another_account_has_the_bucket_name(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(self::stackMissing(), AwsMocks::error(S3Exception::class, 'HeadBucket', 'Forbidden', 403));

        try {
            $this->deployResources($mocks, $this->resourcesConfig(fn (App $app) => $app->storage('uploads')));
            self::fail('expected deploy.bucket_taken');
        } catch (DeployException $e) {
            self::assertSame('deploy.bucket_taken', $e->key);
            self::assertSame(['bucket' => 'shop-production-uploads-111111111111'], $e->params);
        }
        self::assertSame(['DescribeStacks', 'HeadBucket'], $mocks->calls, 'stopped before CloudFormation');
    }

    public function test_deploy_resources_stops_before_any_change_when_a_table_name_is_retained(): void
    {
        foreach ([
            [fn (App $app) => $app->cache(), 'cache', "cache(existing: 'shop-production-cache')"],
            [fn (App $app) => $app->failedJobsTable(), 'failed-jobs', "failedJobsTable(existing: 'shop-production-failed-jobs')"],
        ] as [$build, $kind, $existing]) {
            $mocks = new AwsMocks();
            $mocks->handler->append(self::stackMissing(), new Result(['Table' => ['TableName' => 'x']]));

            try {
                $this->deployResources($mocks, $this->resourcesConfig($build));
                self::fail('expected deploy.table_retained');
            } catch (DeployException $e) {
                self::assertSame('deploy.table_retained', $e->key);
                self::assertSame(['kind' => $kind, 'table' => "shop-production-$kind", 'existing' => $existing], $e->params);
            }
            self::assertSame(['DescribeStacks', 'DescribeTable'], $mocks->calls, 'no CreateStack or UpdateStack');
        }
    }

    public function test_a_profile_that_may_not_look_up_tables_stops_before_any_change_naming_the_permission(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(self::stackMissing(), AwsMocks::error(DynamoDbException::class, 'DescribeTable', 'AccessDeniedException', 400));

        try {
            $this->deployResources($mocks, $this->resourcesConfig(fn (App $app) => $app->cache()));
            self::fail('expected deploy.lookup_denied');
        } catch (DeployException $e) {
            self::assertSame('deploy.lookup_denied', $e->key);
            self::assertSame(['permission' => 'dynamodb:DescribeTable', 'name' => 'shop-production-cache'], $e->params);
        }
        self::assertSame(['DescribeStacks', 'DescribeTable'], $mocks->calls);
    }

    public function test_a_bucket_name_answering_for_another_region_stops_before_any_change(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(self::stackMissing(), AwsMocks::error(S3Exception::class, 'HeadBucket', 'AuthorizationHeaderMalformed', 400));

        try {
            $this->deployResources($mocks, $this->resourcesConfig(fn (App $app) => $app->storage('uploads')));
            self::fail('expected deploy.bucket_taken');
        } catch (DeployException $e) {
            self::assertSame('deploy.bucket_taken', $e->key);
        }
        self::assertSame(['DescribeStacks', 'HeadBucket'], $mocks->calls);
    }

    public function test_deploy_resources_keeps_a_removed_bucket_and_updates_the_stack(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::resourcesStack(),
            self::ownedResources([
                'StorageUploads' => ['AWS::S3::Bucket', 'shop-production-uploads-111111111111'],
                'StorageOld' => ['AWS::S3::Bucket', 'shop-production-old-111111111111'],
                'StorageOldPolicy' => ['AWS::S3::BucketPolicy', 'shop-production-old-111111111111'],
            ]),
            self::resourcesStack(),                              // Stacks::deploy
            new Result([]),                                      // UpdateStack
            self::resourcesStack(),
            new Result(['KeyCount' => 1]),                       // ListObjectsV2: files left
        );

        [$calls, $steps] = $this->deployResources($mocks, $this->resourcesConfig(fn (App $app) => $app->storage('uploads')));

        self::assertSame(['DescribeStacks', 'DescribeStackResources', 'DescribeStacks', 'UpdateStack', 'DescribeStacks', 'ListObjectsV2'], $calls, 'buckets the stack owns need no name check');
        self::assertSame('shop-production-old-111111111111', $mocks->params[5]['Bucket']);
        self::assertSame([
            ['deploy.step_resources', ['stack' => 'arco-shop-production-resources']],
            ['deploy.storage_updated', ['storage' => 'uploads', 'bucket' => 'shop-production-uploads-111111111111']],
            ['deploy.storage_kept', ['bucket' => 'shop-production-old-111111111111', 'objects' => 'some']],
        ], $steps);
        self::assertContains('deploy.storage_kept', \Multiapi\Arco\Cli\StepPrinter::WARNINGS);
    }

    public function test_deploy_resources_deletes_the_stack_when_the_last_resource_is_removed_and_keeps_every_one(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::resourcesStack(),
            new Result(['StackResources' => [
                ['LogicalResourceId' => 'QueueEmails', 'PhysicalResourceId' => 'https://sqs.us-east-1.amazonaws.com/111111111111/shop-production-emails', 'ResourceType' => 'AWS::SQS::Queue', 'ResourceStatus' => 'CREATE_COMPLETE'],
                ['LogicalResourceId' => 'StorageUploads', 'PhysicalResourceId' => 'shop-production-uploads-111111111111', 'ResourceType' => 'AWS::S3::Bucket', 'ResourceStatus' => 'UPDATE_COMPLETE'],
                ['LogicalResourceId' => 'TableCache', 'PhysicalResourceId' => 'shop-production-cache', 'ResourceType' => 'AWS::DynamoDB::Table', 'ResourceStatus' => 'CREATE_COMPLETE'],
                ['LogicalResourceId' => 'TableFailedXjobs', 'PhysicalResourceId' => 'shop-production-failed-jobs', 'ResourceType' => 'AWS::DynamoDB::Table', 'ResourceStatus' => 'CREATE_COMPLETE'],
            ]]),
            self::resourcesStack(),                              // Stacks::delete
            new Result([]),                                      // DeleteStack
            self::stackMissing(),
            new Result(['Attributes' => ['ApproximateNumberOfMessages' => '3']]),
            new Result(['KeyCount' => 0]),
            new Result(['Table' => ['ItemCount' => 120]]),
            self::noTable(),                                     // deleted by hand meanwhile: count unknown
        );

        [$calls, $steps] = $this->deployResources($mocks, $this->queueConfig(false));

        self::assertSame(['DescribeStacks', 'DescribeStackResources', 'DescribeStacks', 'DeleteStack', 'DescribeStacks', 'GetQueueAttributes', 'ListObjectsV2', 'DescribeTable', 'DescribeTable'], $calls);
        self::assertSame([
            ['deploy.step_resources', ['stack' => 'arco-shop-production-resources']],
            ['deploy.queue_kept', ['queue' => 'shop-production-emails', 'url' => 'https://sqs.us-east-1.amazonaws.com/111111111111/shop-production-emails', 'messages' => 3]],
            ['deploy.storage_kept', ['bucket' => 'shop-production-uploads-111111111111', 'objects' => 'none']],
            ['deploy.table_kept', ['table' => 'shop-production-cache', 'items' => 120]],
            ['deploy.table_kept_unknown', ['table' => 'shop-production-failed-jobs']],
        ], $steps);
        self::assertContains('deploy.table_kept', \Multiapi\Arco\Cli\StepPrinter::WARNINGS);
        self::assertContains('deploy.table_kept_unknown', \Multiapi\Arco\Cli\StepPrinter::WARNINGS);
    }

    public function test_a_created_bucket_or_table_switched_to_existing_itself_is_not_offered_for_deletion(): void
    {
        $config = $this->resourcesConfig(fn (App $app) => $app->storage('uploads', existing: 'shop-production-uploads-111111111111')->cache(existing: 'shop-production-cache'));
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::resourcesStack(),
            self::ownedResources([
                'StorageUploads' => ['AWS::S3::Bucket', 'shop-production-uploads-111111111111'],
                'TableCache' => ['AWS::DynamoDB::Table', 'shop-production-cache'],
            ]),
            self::resourcesStack(),                              // Stacks::delete
            new Result([]),                                      // DeleteStack
            self::stackMissing(),
        );

        [$calls, $steps] = $this->deployResources($mocks, $config);

        self::assertSame(['DescribeStacks', 'DescribeStackResources', 'DescribeStacks', 'DeleteStack', 'DescribeStacks'], $calls);
        self::assertSame([
            ['deploy.step_resources', ['stack' => 'arco-shop-production-resources']],
            ['deploy.storage_now_existing', ['bucket' => 'shop-production-uploads-111111111111', 'as' => 'uploads']],
            ['deploy.table_now_existing', ['table' => 'shop-production-cache', 'as' => 'cache']],
        ], $steps);
    }

    public function test_deploy_resources_stops_before_any_change_when_a_bucket_or_table_would_be_replaced(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::resourcesStack(),
            self::ownedResources(['TableCache' => ['AWS::DynamoDB::Table', 'shop-staging-cache']]),
        );

        try {
            $this->deployResources($mocks, $this->resourcesConfig(fn (App $app) => $app->cache()));
            self::fail('expected deploy.resource_replaced');
        } catch (DeployException $e) {
            self::assertSame('deploy.resource_replaced', $e->key);
            self::assertSame(['resource' => 'cache', 'from' => 'shop-staging-cache', 'to' => 'shop-production-cache'], $e->params);
        }
        self::assertSame(['DescribeStacks', 'DescribeStackResources'], $mocks->calls);
    }

    public function test_an_account_that_blocks_public_bucket_policies_is_a_clear_error(): void
    {
        $config = $this->resourcesConfig(fn (App $app) => $app->storage('media', public: ['public/']));
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::stackMissing(),
            self::noBucket(),
            self::stackMissing(),                                // Stacks::deploy
            new Result([]),                                      // CreateStack
            self::resourcesStack('ROLLBACK_COMPLETE'),
            new Result(['StackEvents' => [
                ['LogicalResourceId' => 'arco-shop-production-resources', 'ResourceStatus' => 'ROLLBACK_COMPLETE'],
                ['LogicalResourceId' => 'StorageMediaPolicy', 'ResourceStatus' => 'CREATE_FAILED', 'ResourceStatusReason' => 'Resource handler returned message: "User: arn:aws:iam::111111111111:user/deployer is not authorized to perform: s3:PutBucketPolicy on resource: "arn:aws:s3:::shop-production-media-111111111111" because public policies are blocked by the BlockPublicPolicy block public access setting. (Service: S3, Status Code: 403)"'],
                ['LogicalResourceId' => 'arco-shop-production-resources', 'ResourceStatus' => 'CREATE_IN_PROGRESS'],
            ]]),
        );

        try {
            $this->deployResources($mocks, $config);
            self::fail('expected deploy.public_blocked');
        } catch (DeployException $e) {
            self::assertSame('deploy.public_blocked', $e->key);
            self::assertSame(['bucket' => 'shop-production-media-111111111111'], $e->params);
        }
    }

    public function test_a_plain_access_denial_on_the_public_policy_names_both_causes(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::stackMissing(),
            self::noBucket(),
            self::stackMissing(),
            new Result([]),
            self::resourcesStack('ROLLBACK_COMPLETE'),
            new Result(['StackEvents' => [
                ['LogicalResourceId' => 'StorageMediaPolicy', 'ResourceStatus' => 'CREATE_FAILED', 'ResourceStatusReason' => 'API: s3:PutBucketPolicy Access Denied'],
                ['LogicalResourceId' => 'arco-shop-production-resources', 'ResourceStatus' => 'CREATE_IN_PROGRESS'],
            ]]),
        );

        try {
            $this->deployResources($mocks, $this->resourcesConfig(fn (App $app) => $app->storage('media', public: ['public/'])));
            self::fail('expected deploy.public_policy_denied');
        } catch (DeployException $e) {
            self::assertSame('deploy.public_policy_denied', $e->key);
            self::assertSame(['bucket' => 'shop-production-media-111111111111', 'reason' => 'StorageMediaPolicy: API: s3:PutBucketPolicy Access Denied'], $e->params);
        }
    }

    public function test_other_stack_failures_are_reported_as_they_are(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::stackMissing(),
            self::noBucket(),
            self::stackMissing(),
            new Result([]),
            self::resourcesStack('ROLLBACK_COMPLETE'),
            new Result(['StackEvents' => [
                ['LogicalResourceId' => 'StorageMedia', 'ResourceStatus' => 'CREATE_FAILED', 'ResourceStatusReason' => 'Rate exceeded'],
                ['LogicalResourceId' => 'arco-shop-production-resources', 'ResourceStatus' => 'CREATE_IN_PROGRESS'],
            ]]),
        );

        try {
            $this->deployResources($mocks, $this->resourcesConfig(fn (App $app) => $app->storage('media', public: ['public/'])));
            self::fail('expected stack.failed');
        } catch (DeployException $e) {
            self::assertSame('stack.failed', $e->key);
            self::assertSame('StorageMedia: Rate exceeded', $e->params['reason']);
        }
    }

    public function test_a_foreign_stack_with_the_name_does_not_fail_an_app_without_resources(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(self::resourcesStack('CREATE_COMPLETE', 'other'));

        [$calls, $steps] = $this->deployResources($mocks, $this->queueConfig(false));

        self::assertSame(['DescribeStacks'], $calls, 'not ours and nothing to deploy: left alone');
        self::assertSame([], $steps);
    }

    public function test_remove_lists_the_kept_buckets_and_tables(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            AwsMocks::error(CloudFormationException::class, 'DescribeStacks', 'AccessDenied', 403), // domains stack
            self::noRule(),
            new Result(['ResourceTagMappingList' => []]),                                       // GetResources: no workers
            self::resourcesStack(),
            self::ownedResources([
                'StorageUploads' => ['AWS::S3::Bucket', 'shop-production-uploads-111111111111'],
                'StorageUploadsPolicy' => ['AWS::S3::BucketPolicy', 'shop-production-uploads-111111111111'],
                'TableFailedXjobs' => ['AWS::DynamoDB::Table', 'shop-production-failed-jobs'],
            ]),
            self::resourcesStack(),                                                             // Stacks::delete
            new Result([]),                                                                     // DeleteStack
            self::stackMissing(),
            AwsMocks::error(S3Exception::class, 'ListObjectsV2', 'AccessDenied', 403),           // unknown
            new Result(['Table' => ['ItemCount' => 0]]),
            self::lambdaMissing('DeleteFunction'),                                              // console never deployed
            new Result([]),                                                                     // DeleteWebFunction
            AwsMocks::error(LambdaWebException::class, 'GetWebFunction', 'ResourceNotFoundException', 404),
            new Result([]), new Result([]),                                                     // DeleteRolePolicy, DeleteRole
        );
        $steps = [];

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->remove($this->config(), function (string $key, array $params = []) use (&$steps): void { $steps[] = [$key, $params]; });

        self::assertSame([
            'DescribeStacks', 'DescribeRule', 'GetResources',
            'DescribeStacks', 'DescribeStackResources', 'DescribeStacks', 'DeleteStack', 'DescribeStacks', 'ListObjectsV2', 'DescribeTable',
            'DeleteFunction', 'DeleteWebFunction', 'GetWebFunction', 'DeleteRolePolicy', 'DeleteRole',
        ], $mocks->calls);
        self::assertSame([
            ['remove.step_resources', ['stack' => 'arco-shop-production-resources']],
            ['remove.storage_kept', ['bucket' => 'shop-production-uploads-111111111111', 'objects' => 'unknown']],
            ['remove.table_kept', ['table' => 'shop-production-failed-jobs', 'items' => 0]],
            ['remove.step_console', ['function' => 'shop-production-console']],
            ['remove.step_function', ['function' => 'shop-production']],
        ], $steps);
        self::assertContains('remove.storage_kept', \Multiapi\Arco\Cli\StepPrinter::WARNINGS);
        self::assertContains('remove.table_kept', \Multiapi\Arco\Cli\StepPrinter::WARNINGS);
    }

    private function queuesConfig(bool $second = true): AppConfig
    {
        return ConfigResolver::resolve(
            App::make('shop')->region('us-east-1')
                ->queue('emails', timeout: 120, memory: 512, concurrency: 5, batchSize: 10, tries: 4)
                ->queue('reports', existing: 'arn:aws:sqs:us-east-1:222222222222:legacy-reports', dlq: false)
                ->environment('production', fn (Environment $e) => $e->account('111111111111'))
                ->toArray(),
            'production',
        );
    }

    private static function ready(): Result
    {
        return new Result(['State' => 'Active', 'LastUpdateStatus' => 'Successful']);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed> without the SDK's transport entries
     */
    private static function p(array $params): array
    {
        unset($params['@http'], $params['@context']);
        return $params;
    }

    private static function workers(string ...$queues): Result
    {
        return new Result(['ResourceTagMappingList' => [
            ...array_map(fn (string $queue) => ['ResourceARN' => self::arn("shop-production-queue-$queue")], $queues),
            ['ResourceARN' => self::arn('shop-production-console')],
        ]]);
    }

    private static function arn(string $function): string
    {
        return "arn:aws:lambda:us-east-1:111111111111:function:$function";
    }

    /** @return array<string, list<array<string, mixed>>> */
    private static function byOperation(AwsMocks $mocks): array
    {
        $by = [];
        foreach ($mocks->calls as $i => $operation) {
            $by[$operation][] = $mocks->params[$i];
        }
        return $by;
    }

    public function test_prepare_deploys_one_worker_per_queue_from_the_console_zip_even_with_the_console_off(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['Account' => '111111111111']),
            new Result([]),                                   // HeadBucket
            new Result([]), new Result([]), new Result([]), new Result([]),
            new Result(['VersionId' => 'v1']),                // HeadObject (web zip)
            new Result(['Role' => ['Arn' => 'arn:role']]),    // GetRole
            new Result([]),                                   // PutRolePolicy
            new Result(['functionName' => 'shop-production', 'state' => 'Active']),
            new Result(['domainName' => 'abc.on.aws', 'state' => 'Active', 'revisionWeights' => [['revisionId' => 'rev-1', 'weight' => 100]]]),
            new Result(['revisionId' => 'rev-2', 'state' => 'Pending']),
            new Result(['state' => 'Active', 'stateReason' => 'ok']),
            new Result(['VersionId' => 'cv1']),               // HeadObject (console zip)
            new Result(['ContentLength' => 3, 'VersionId' => 'bv1']), new Result([]), // binary: HeadObject, CopyObject
            // emails: new worker
            self::lambdaMissing('GetAlias'), self::lambdaMissing('GetFunctionConfiguration'), new Result(['State' => 'Pending']), self::ready(), new Result(['Version' => '1']),
            // reports: redeploy of an existing worker
            new Result(['FunctionVersion' => '8']), self::ready(), self::ready(), new Result([]), self::ready(), new Result([]), self::ready(), new Result(['Version' => '9']),
        );
        $steps = [];

        $prepared = (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->prepare($this->queuesConfig(), $this->package(), $this->console(), ['APP_ENV' => 'production'], function (string $key, array $params = []) use (&$steps): void { $steps[] = [$key, $params]; });

        self::assertSame(['emails' => ['version' => '1', 'previous' => null], 'reports' => ['version' => '9', 'previous' => '8']], $prepared->workerVersions);
        self::assertNull($prepared->consoleVersion, 'the console is off: only the workers use the console zip');
        self::assertSame([
            'GetCallerIdentity', 'HeadBucket', 'PutPublicAccessBlock', 'PutBucketPolicy', 'PutBucketVersioning', 'PutBucketLifecycleConfiguration', 'HeadObject', 'GetRole', 'PutRolePolicy', 'GetWebFunction', 'GetWebFunctionEndpoint',
            'CreateWebFunctionRevision', 'GetWebFunctionRevision', 'HeadObject', 'HeadObject', 'CopyObject',
            'GetAlias', 'GetFunctionConfiguration', 'CreateFunction', 'GetFunctionConfiguration', 'PublishVersion',
            'GetAlias', 'GetFunctionConfiguration', 'GetFunctionConfiguration', 'UpdateFunctionCode', 'GetFunctionConfiguration', 'UpdateFunctionConfiguration', 'GetFunctionConfiguration', 'PublishVersion',
        ], $mocks->calls);
        $by = self::byOperation($mocks);
        $consoleKey = 'shop/production/console-' . str_repeat('c', 64) . '.zip';
        $create = $by['CreateFunction'][0];
        self::assertSame('shop-production-queue-emails', $create['FunctionName']);
        self::assertSame(['S3Bucket' => 'arco-111111111111-us-east-1', 'S3Key' => $consoleKey, 'S3ObjectVersion' => 'cv1'], $create['Code']);
        self::assertSame('arco.worker', $create['Handler']);
        self::assertSame(120, $create['Timeout']);
        self::assertSame(512, $create['MemorySize']);
        self::assertSame(['Size' => 1024], $create['EphemeralStorage']);
        self::assertSame(['arco:app' => 'shop', 'arco:environment' => 'production'], $create['Tags']);
        $variables = $create['Environment']['Variables'];
        self::assertSame('production', $variables['APP_ENV']);
        self::assertSame('emails', $variables['ARCO_WORKER_QUEUE']);
        self::assertSame('4', $variables['ARCO_QUEUE_TRIES']);
        self::assertSame('6', $variables['ARCO_QUEUE_MAX_RECEIVES'], 'an Arco queue with a dead-letter queue: tries + 2');
        self::assertSame('runtime/1.13.0-r1/frankenphp', $variables['ARCO_BINARY_KEY']);
        $update = $by['UpdateFunctionConfiguration'][0];
        self::assertSame('shop-production-queue-reports', $update['FunctionName']);
        self::assertSame('reports', $update['Environment']['Variables']['ARCO_WORKER_QUEUE']);
        self::assertSame('3', $update['Environment']['Variables']['ARCO_QUEUE_TRIES']);
        self::assertArrayNotHasKey('ARCO_QUEUE_MAX_RECEIVES', $update['Environment']['Variables'], 'an existing queue: its maxReceiveCount is not ours to know');
        self::assertSame(60, $update['Timeout']);
        self::assertSame(['deploy.step_bucket', 'deploy.step_upload', 'deploy.step_role', 'deploy.step_revision'], array_slice(array_column($steps, 0), 0, 4));
        self::assertContains('deploy.step_worker', array_column($steps, 0));
        self::assertNotContains('deploy.step_console', array_column($steps, 0));
    }

    public function test_prepare_lets_the_role_use_every_bucket_and_table(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['Account' => '111111111111']),
            new Result([]),                                   // HeadBucket
            new Result([]), new Result([]), new Result([]), new Result([]),
            new Result(['VersionId' => 'v1']),                // HeadObject (web zip)
            new Result(['Role' => ['Arn' => 'arn:role']]),    // GetRole
            new Result([]),                                   // PutRolePolicy
            new Result(['functionName' => 'shop-production', 'state' => 'Active']),
            new Result(['domainName' => 'abc.on.aws', 'state' => 'Active', 'revisionWeights' => [['revisionId' => 'rev-1', 'weight' => 100]]]),
            new Result(['revisionId' => 'rev-2', 'state' => 'Pending']),
            new Result(['state' => 'Active', 'stateReason' => 'ok']),
        );
        $config = $this->resourcesConfig(fn (App $app) => $app->storage('uploads')->storage('media', existing: 'legacy.bucket')->cache()->failedJobsTable(existing: 'Legacy_Failed'));

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->prepare($config, $this->package(), null, [], fn () => null);

        $policy = json_decode(self::byOperation($mocks)['PutRolePolicy'][0]['PolicyDocument'], true);
        $bySid = array_column($policy['Statement'], null, 'Sid');
        self::assertSame(['arn:aws:s3:::shop-production-uploads-111111111111', 'arn:aws:s3:::legacy.bucket'], $bySid['ArcoStorageList']['Resource']);
        self::assertSame(['arn:aws:s3:::shop-production-uploads-111111111111/*', 'arn:aws:s3:::legacy.bucket/*'], $bySid['ArcoStorage']['Resource']);
        self::assertSame([
            'arn:aws:dynamodb:us-east-1:111111111111:table/shop-production-cache',
            'arn:aws:dynamodb:us-east-1:111111111111:table/Legacy_Failed',
        ], $bySid['ArcoTables']['Resource']);
    }

    public function test_a_queue_without_a_dead_letter_queue_gets_no_max_receives(): void
    {
        $queue = new \Multiapi\Arco\Config\QueueConfig('mail', tries: 5, dlq: false);
        self::assertArrayNotHasKey('ARCO_QUEUE_MAX_RECEIVES', \Multiapi\Arco\Deploy\WorkerFunctions::environment($queue, []));
        self::assertSame('5', \Multiapi\Arco\Deploy\WorkerFunctions::environment($queue, [])['ARCO_QUEUE_TRIES']);
    }

    private function appendFirstSync(AwsMocks $mocks, bool $otherConsumer = false): void
    {
        $mocks->handler->append(
            // emails (Arco queue): alias, mapping
            self::lambdaMissing('UpdateAlias'), new Result([]),
            new Result(['AliasArn' => self::arn('shop-production-queue-emails') . ':live']),
            new Result(['EventSourceMappings' => []]),
            new Result(['UUID' => 'u1']),
            // reports (existing queue): alias, no mapping of ours, other consumers looked up
            new Result([]),
            new Result(['AliasArn' => self::arn('shop-production-queue-reports') . ':live']),
            new Result(['EventSourceMappings' => []]),
            new Result(['EventSourceMappings' => $otherConsumer ? [['FunctionArn' => self::arn('legacy-worker') . ':prod'], ['FunctionArn' => self::arn('legacy-worker')]] : []]),
            new Result(['UUID' => 'u2']),
            // workers of removed queues
            self::workers('emails', 'reports'),
        );
    }

    public function test_sync_workers_points_the_aliases_then_creates_the_mappings(): void
    {
        $mocks = new AwsMocks();
        $this->appendFirstSync($mocks);
        $steps = [];
        $config = $this->queuesConfig();

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->syncWorkers(
            $config,
            ['emails' => ['version' => '1', 'previous' => null], 'reports' => ['version' => '9', 'previous' => '8']],
            function (string $key, array $params = []) use (&$steps): void { $steps[] = [$key, $params]; },
        );

        self::assertSame([
            'UpdateAlias', 'CreateAlias', 'GetAlias', 'ListEventSourceMappings', 'CreateEventSourceMapping',
            'UpdateAlias', 'GetAlias', 'ListEventSourceMappings', 'ListEventSourceMappings', 'CreateEventSourceMapping',
            'GetResources',
        ], $mocks->calls);
        self::assertSame(['lambda:function'], $mocks->params[10]['ResourceTypeFilters']);
        self::assertSame([['Key' => 'arco:app', 'Values' => ['shop']], ['Key' => 'arco:environment', 'Values' => ['production']]], $mocks->params[10]['TagFilters']);
        self::assertNotContains('PutFunctionEventInvokeConfig', $mocks->calls, 'workers keep Lambda\'s retries: SQS redelivery brings a failed job back');
        self::assertSame(['FunctionName' => 'shop-production-queue-emails', 'Name' => 'live', 'FunctionVersion' => '1'], self::p($mocks->params[0]));
        $alias = self::arn('shop-production-queue-emails') . ':live';
        self::assertSame(['FunctionName' => $alias], array_intersect_key($mocks->params[3], ['FunctionName' => 1, 'EventSourceArn' => 1]), 'every mapping of the alias');
        self::assertSame([
            'FunctionName' => $alias,
            'EventSourceArn' => 'arn:aws:sqs:us-east-1:111111111111:shop-production-emails',
            'BatchSize' => 10,
            'FunctionResponseTypes' => ['ReportBatchItemFailures'],
            'Enabled' => true,
            'MaximumBatchingWindowInSeconds' => 0,
            'ScalingConfig' => ['MaximumConcurrency' => 5],
        ], self::p($mocks->params[4]));
        // reports: no concurrency, so no ScalingConfig; the queue is another account's: its ARN comes from `existing`
        self::assertSame('arn:aws:sqs:us-east-1:222222222222:legacy-reports', $mocks->params[9]['EventSourceArn']);
        self::assertArrayNotHasKey('ScalingConfig', $mocks->params[9]);
        self::assertSame(1, $mocks->params[9]['BatchSize']);
        self::assertSame([
            ['deploy.worker_ready', ['queue' => 'emails', 'function' => 'shop-production-queue-emails', 'version' => '1']],
            ['deploy.worker_mapping', ['queue' => 'emails', 'function' => 'shop-production-queue-emails', 'batch' => 10]],
            ['deploy.worker_ready', ['queue' => 'reports', 'function' => 'shop-production-queue-reports', 'version' => '9']],
            ['deploy.worker_mapping', ['queue' => 'reports', 'function' => 'shop-production-queue-reports', 'batch' => 1]],
        ], $steps, 'no consumer warning when nobody else reads the existing queue');
        self::assertCount(0, $mocks->handler);
    }

    public function test_another_consumer_of_an_existing_queue_is_a_warning_and_the_mapping_is_still_created(): void
    {
        $mocks = new AwsMocks();
        $this->appendFirstSync($mocks, otherConsumer: true);
        $steps = [];

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->syncWorkers(
            $this->queuesConfig(),
            ['emails' => ['version' => '1', 'previous' => null], 'reports' => ['version' => '9', 'previous' => '8']],
            function (string $key, array $params = []) use (&$steps): void { $steps[] = [$key, $params]; },
        );

        self::assertContains(['deploy.queue_other_consumer', ['queue' => 'reports', 'functions' => 'legacy-worker']], $steps);
        self::assertSame(2, count(array_filter($mocks->calls, fn (string $call) => $call === 'CreateEventSourceMapping')));
        self::assertContains('deploy.queue_other_consumer', \Multiapi\Arco\Cli\StepPrinter::WARNINGS);
    }

    public function test_a_redeploy_updates_the_mapping_in_place_and_clears_a_removed_concurrency(): void
    {
        $config = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->queue('emails', batchSize: 20)->environment('production', fn (Environment $e) => $e->account('111111111111'))->toArray(), 'production');
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result([]),                                                                     // UpdateAlias
            new Result(['AliasArn' => self::arn('shop-production-queue-emails') . ':live']),
            new Result(['EventSourceMappings' => [['UUID' => 'u-existing', 'FunctionArn' => self::arn('shop-production-queue-emails') . ':live', 'EventSourceArn' => 'arn:aws:sqs:us-east-1:111111111111:shop-production-emails']]]),
            new Result([]),                                                                     // UpdateEventSourceMapping
            self::workers('emails'),
        );

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->syncWorkers($config, ['emails' => ['version' => '5', 'previous' => '4']], fn () => null);

        self::assertSame(['UpdateAlias', 'GetAlias', 'ListEventSourceMappings', 'UpdateEventSourceMapping', 'GetResources'], $mocks->calls);
        self::assertSame([
            'UUID' => 'u-existing',
            'BatchSize' => 20,
            'FunctionResponseTypes' => ['ReportBatchItemFailures'],
            'Enabled' => true,
            'MaximumBatchingWindowInSeconds' => 1,
            'ScalingConfig' => [],
        ], self::p($mocks->params[3]));
    }

    public function test_a_queue_removed_from_arco_php_loses_its_mapping_then_its_worker_and_other_apps_are_untouched(): void
    {
        $config = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->environment('production')->toArray(), 'production');
        $mocks = new AwsMocks();
        $mocks->handler->append(
            // the tag filter already holds back other apps and environments; the prefix holds back the console and the web function
            new Result(['ResourceTagMappingList' => [
                ['ResourceARN' => self::arn('shop-production-queue-old')],
                ['ResourceARN' => self::arn('shop-production-console')],
                ['ResourceARN' => self::arn('shop-production-queue-')],
            ], 'PaginationToken' => 'p2']),
            new Result(['ResourceTagMappingList' => []]),
            new Result(['EventSourceMappings' => [['UUID' => 'u-old']]]),
            new Result([]),                                                                     // DeleteEventSourceMapping
            new Result([]),                                                                     // DeleteFunction
        );
        $steps = [];

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->syncWorkers($config, [], function (string $key, array $params = []) use (&$steps): void { $steps[] = [$key, $params]; });

        self::assertSame(['GetResources', 'GetResources', 'ListEventSourceMappings', 'DeleteEventSourceMapping', 'DeleteFunction'], $mocks->calls);
        self::assertSame('p2', $mocks->params[1]['PaginationToken'], 'the lookup is paginated');
        self::assertSame('shop-production-queue-old:live', $mocks->params[2]['FunctionName']);
        self::assertSame(['UUID' => 'u-old'], self::p($mocks->params[3]));
        self::assertSame('shop-production-queue-old', $mocks->params[4]['FunctionName']);
        self::assertSame([['deploy.worker_removed', ['queue' => 'old', 'function' => 'shop-production-queue-old']]], $steps);
    }

    public function test_without_queues_any_error_of_the_worker_lookup_stays_silent(): void
    {
        $config = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->environment('production')->toArray(), 'production');
        foreach ([
            AwsMocks::error(ResourceGroupsTaggingAPIException::class, 'GetResources', 'AccessDeniedException', 400),
            AwsMocks::error(ResourceGroupsTaggingAPIException::class, 'GetResources', 'InvalidParameterException', 400),
        ] as $error) {
            $mocks = new AwsMocks();
            $mocks->handler->append($error);
            $steps = [];

            (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->syncWorkers($config, [], function (string $key) use (&$steps): void { $steps[] = $key; });

            self::assertSame([], $steps);
            self::assertSame(['GetResources'], $mocks->calls);
        }
    }

    public function test_with_queues_a_failed_worker_lookup_is_its_own_warning(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result([]), new Result(['AliasArn' => self::arn('shop-production-queue-emails') . ':live']), new Result(['EventSourceMappings' => []]), new Result(['UUID' => 'u1']),
            new Result([]), new Result(['AliasArn' => self::arn('shop-production-queue-reports') . ':live']), new Result(['EventSourceMappings' => []]), new Result(['EventSourceMappings' => []]), new Result(['UUID' => 'u2']),
            AwsMocks::error(ResourceGroupsTaggingAPIException::class, 'GetResources', 'InvalidParameterException', 400, 'Rate exceeded'),
        );
        $steps = [];

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->syncWorkers(
            $this->queuesConfig(),
            ['emails' => ['version' => '1', 'previous' => null], 'reports' => ['version' => '9', 'previous' => '8']],
            function (string $key, array $params = []) use (&$steps): void { $steps[] = [$key, $params]; },
        );

        self::assertContains(['deploy.worker_scan_failed', ['error' => 'Rate exceeded']], $steps);
        self::assertNotContains('deploy.worker_failed', array_column($steps, 0));
        self::assertContains('deploy.worker_scan_failed', \Multiapi\Arco\Cli\StepPrinter::WARNINGS);
    }

    public function test_a_failing_mapping_is_a_warning_and_the_other_queues_still_get_theirs(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result([]),                                                                     // UpdateAlias (emails)
            new Result(['AliasArn' => self::arn('shop-production-queue-emails') . ':live']),
            new Result(['EventSourceMappings' => []]),
            AwsMocks::error(LambdaException::class, 'CreateEventSourceMapping', 'AccessDeniedException', 403, 'not authorized to perform: lambda:CreateEventSourceMapping'),
            new Result([]),                                                                     // UpdateAlias (reports)
            new Result(['AliasArn' => self::arn('shop-production-queue-reports') . ':live']),
            new Result(['EventSourceMappings' => []]), new Result(['EventSourceMappings' => []]),
            new Result(['UUID' => 'u2']),
            new Result(['ResourceTagMappingList' => []]),
        );
        $steps = [];

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->syncWorkers(
            $this->queuesConfig(),
            ['emails' => ['version' => '1', 'previous' => null], 'reports' => ['version' => '9', 'previous' => '8']],
            function (string $key, array $params = []) use (&$steps): void { $steps[] = [$key, $params]; },
        );

        $failed = array_values(array_filter($steps, fn (array $step) => $step[0] === 'deploy.worker_failed'));
        self::assertCount(1, $failed);
        self::assertSame('emails', $failed[0][1]['queue']);
        self::assertStringContainsString('lambda:CreateEventSourceMapping', $failed[0][1]['error']);
        self::assertContains('deploy.worker_mapping', array_column($steps, 0), 'the other queue still got its mapping');
    }

    public function test_rollback_points_the_worker_aliases_back_without_touching_the_mappings(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['state' => 'Active', 'domainName' => 'x', 'revisionWeights' => [['revisionId' => 'rev-2', 'weight' => 100]]]),
            new Result([]),
            new Result(['state' => 'Active', 'updateStatus' => 'Successful', 'domainName' => 'x', 'revisionWeights' => [['revisionId' => 'rev-1', 'weight' => 100]]]),
            new Result([]),                                   // UpdateAlias (emails)
            new Result([]),                                   // UpdateAlias (reports)
        );

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->rollback($this->queuesConfig(), 'rev-1', null, ['emails' => '3', 'reports' => '8']);

        self::assertSame(['GetWebFunctionEndpoint', 'UpdateWebFunctionEndpoint', 'GetWebFunctionEndpoint', 'UpdateAlias', 'UpdateAlias'], $mocks->calls);
        $keys = ['FunctionName' => 1, 'Name' => 1, 'FunctionVersion' => 1];
        self::assertSame(['FunctionName' => 'shop-production-queue-emails', 'Name' => 'live', 'FunctionVersion' => '3'], array_intersect_key($mocks->params[3], $keys));
        self::assertSame(['FunctionName' => 'shop-production-queue-reports', 'Name' => 'live', 'FunctionVersion' => '8'], array_intersect_key($mocks->params[4], $keys));
    }

    public function test_prune_keeps_four_versions_per_worker_plus_live_previous_and_new(): void
    {
        $versions = fn (array $numbers) => ['Versions' => array_map(fn (string $v) => ['Version' => $v], $numbers)];
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['FunctionVersion' => '9']), new Result($versions(['$LATEST', '1', '2', '3', '4', '5', '6', '7', '8', '9'])),
            new Result([]), new Result([]), new Result([]), new Result([]),                      // DeleteFunction 2, 3, 4, 5
            new Result(['Contents' => []]), new Result(['Contents' => []]),
            new Result(['ResourceTagMappingList' => []]), new Result(['Contents' => []]),
        );
        $prepared = new PreparedDeploy('rev-2', 'rev-1', 'b', 'shop/production/a.zip', null, null, 'shop/production/console-c.zip', null, ['emails' => ['version' => '9', 'previous' => '1']]);
        $config = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->queue('emails')->environment('production')->toArray(), 'production');

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->prune($config, $prepared);

        $deleted = [];
        foreach ($mocks->calls as $i => $call) {
            if ($call === 'DeleteFunction') {
                self::assertSame('shop-production-queue-emails', $mocks->params[$i]['FunctionName']);
                $deleted[] = $mocks->params[$i]['Qualifier'];
            }
        }
        self::assertSame(['2', '3', '4', '5'], $deleted, 'keeps 9, 8, 7, 6 (the newest four), 1 (the previous deploy) and $LATEST');
    }

    public function test_remove_deletes_workers_and_mappings_then_the_resources_stack_before_the_console_and_lists_the_kept_queues(): void
    {
        $config = $this->queuesConfig();
        $mocks = new AwsMocks();
        $mocks->handler->append(
            AwsMocks::error(CloudFormationException::class, 'DescribeStacks', 'ValidationError', 400, 'Stack with id arco-shop-production does not exist'), // domains stack
            self::noRule(),
            self::workers('emails', 'gone'),
            // emails
            new Result(['EventSourceMappings' => [['UUID' => 'u1']]]), new Result([]), new Result([]),
            // reports (in arco.php, never deployed)
            self::lambdaMissing('ListEventSourceMappings'), self::lambdaMissing('DeleteFunction'),
            // gone (left from a removed queue)
            new Result(['EventSourceMappings' => []]), new Result([]),
            // resources stack
            new Result(['Stacks' => [['StackName' => 'arco-shop-production-resources', 'StackStatus' => 'CREATE_COMPLETE', 'Tags' => [['Key' => 'arco:app', 'Value' => 'shop'], ['Key' => 'arco:environment', 'Value' => 'production']]]]]),
            new Result(['StackResources' => [['LogicalResourceId' => 'QueueEmails', 'PhysicalResourceId' => 'https://sqs.us-east-1.amazonaws.com/111111111111/shop-production-emails', 'ResourceType' => 'AWS::SQS::Queue', 'ResourceStatus' => 'CREATE_COMPLETE']]]),
            new Result(['Stacks' => [['StackName' => 'arco-shop-production-resources', 'StackStatus' => 'CREATE_COMPLETE']]]),
            new Result([]),                                                                     // DeleteStack
            AwsMocks::error(CloudFormationException::class, 'DescribeStacks', 'ValidationError', 400, 'Stack with id arco-shop-production-resources does not exist'),
            new Result(['Attributes' => ['ApproximateNumberOfMessages' => '12']]),
            new Result([]),                                                                     // DeleteFunction (console)
            new Result([]),
            AwsMocks::error(LambdaWebException::class, 'GetWebFunction', 'ResourceNotFoundException', 404),
            new Result([]), new Result([]),
        );
        $steps = [];

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->remove($config, function (string $key, array $params = []) use (&$steps): void { $steps[] = [$key, $params]; });

        self::assertSame([
            'DescribeStacks', 'DescribeRule',
            'GetResources',
            'ListEventSourceMappings', 'DeleteEventSourceMapping', 'DeleteFunction',
            'ListEventSourceMappings', 'DeleteFunction',
            'ListEventSourceMappings', 'DeleteFunction',
            'DescribeStacks', 'DescribeStackResources', 'DescribeStacks', 'DeleteStack', 'DescribeStacks', 'GetQueueAttributes',
            'DeleteFunction', 'DeleteWebFunction', 'GetWebFunction', 'DeleteRolePolicy', 'DeleteRole',
        ], $mocks->calls);
        self::assertSame('shop-production-console', $mocks->params[16]['FunctionName'], 'the console goes after the workers and the stack');
        self::assertSame([
            ['remove.step_worker', ['queue' => 'emails', 'function' => 'shop-production-queue-emails']],
            ['remove.step_worker', ['queue' => 'reports', 'function' => 'shop-production-queue-reports']],
            ['remove.step_worker', ['queue' => 'gone', 'function' => 'shop-production-queue-gone']],
            ['remove.step_resources', ['stack' => 'arco-shop-production-resources']],
            ['remove.queue_kept', ['queue' => 'shop-production-emails', 'url' => 'https://sqs.us-east-1.amazonaws.com/111111111111/shop-production-emails', 'messages' => 12]],
            ['remove.step_console', ['function' => 'shop-production-console']],
            ['remove.step_function', ['function' => 'shop-production']],
        ], $steps);
        self::assertContains('remove.queue_kept', \Multiapi\Arco\Cli\StepPrinter::WARNINGS);
    }

    public function test_remove_warns_when_the_worker_lookup_fails_and_still_deletes_the_workers_in_arco_php(): void
    {
        $config = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->queue('emails')->environment('production')->toArray(), 'production');
        $mocks = new AwsMocks();
        $mocks->handler->append(
            AwsMocks::error(CloudFormationException::class, 'DescribeStacks', 'AccessDenied', 403),
            self::noRule(),
            AwsMocks::error(ResourceGroupsTaggingAPIException::class, 'GetResources', 'InvalidParameterException', 400, 'Rate exceeded'),
            new Result(['EventSourceMappings' => []]), new Result([]),                          // emails: mappings, DeleteFunction
            AwsMocks::error(CloudFormationException::class, 'DescribeStacks', 'AccessDenied', 403),
            new Result([]), new Result([]),
            AwsMocks::error(LambdaWebException::class, 'GetWebFunction', 'ResourceNotFoundException', 404),
            new Result([]), new Result([]),
        );
        $steps = [];

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->remove($config, function (string $key, array $params = []) use (&$steps): void { $steps[] = [$key, $params]; });

        self::assertContains(['remove.worker_scan_failed', ['error' => 'Rate exceeded']], $steps);
        self::assertContains('remove.step_worker', array_column($steps, 0));
        self::assertContains('remove.worker_scan_failed', \Multiapi\Arco\Cli\StepPrinter::WARNINGS);
    }
}
