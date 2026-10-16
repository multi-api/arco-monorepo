<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy;

use Aws\CloudFormation\Exception\CloudFormationException;
use Aws\Iam\Exception\IamException;
use Aws\Lambda\Exception\LambdaException;
use Aws\LambdaWeb\Exception\LambdaWebException;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Config\Environment;
use Multiapi\Arco\Deploy\AwsDeployer;
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
        );
        $steps = [];
        $record = function (string $key) use (&$steps): void { $steps[] = $key; };
        $deployer = new AwsDeployer($mocks->clients(), AwsMocks::noSleep());
        $console = $this->console();

        $prepared = $deployer->prepare($this->config(), $this->package(), $console, ['APP_ENV' => 'production'], $record);
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
            'GetAlias', 'ListVersionsByFunction', 'ListObjectsV2', 'ListObjectsV2',
        ], $mocks->calls);
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

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->prepare($this->config(), $this->package(), $console, ['APP_ENV' => 'production']);

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

        $result = $deployer->release($this->config(), $deployer->prepare($this->config(), $this->package(), $this->console(), []));

        self::assertSame('rev-2', $result->revisionId);
        self::assertSame('rev-1', $result->previousRevisionId);
        self::assertSame('7', $result->consoleVersion);
        self::assertSame('6', $result->previousConsoleVersion);
        self::assertSame([
            'GetCallerIdentity', 'HeadBucket', 'PutPublicAccessBlock', 'PutBucketPolicy', 'PutBucketVersioning', 'PutBucketLifecycleConfiguration', 'HeadObject', 'GetRole', 'PutRolePolicy', 'GetWebFunction', 'GetWebFunctionEndpoint',
            'CreateWebFunctionRevision', 'GetWebFunctionRevision', 'HeadObject', 'HeadObject',
            'GetAlias', 'GetFunctionConfiguration', 'GetFunctionConfiguration', 'UpdateFunctionCode', 'GetFunctionConfiguration', 'UpdateFunctionConfiguration', 'GetFunctionConfiguration', 'PublishVersion',
            'GetWebFunctionEndpoint', 'UpdateWebFunctionEndpoint', 'GetWebFunctionEndpoint',
        ], $mocks->calls, 'release neither moves live nor prunes');
        self::assertNotContains('PutObject', $mocks->calls, 'web zip, console zip and binary are uploaded once');
        self::assertSame('', $mocks->params[20]['KMSKeyArn'], 'no kmsKey in the config clears a key set by an earlier deploy');
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
        );
        $prepared = new PreparedDeploy('rev-2', 'rev-1', 'b', 'shop/production/a.zip', '11', '3', 'shop/production/console-c.zip');
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
        $old = array_map(fn (int $i) => ['Key' => "shop/production/$i.zip", 'LastModified' => new \DateTimeImmutable("2026-01-01 +$i hours")], range(1, 21));
        yield 'ListVersionsByFunction' => [[
            new Result(['FunctionVersion' => '2']), $denied('ListVersionsByFunction'),
            new Result(['Contents' => []]), new Result(['Contents' => []]),
        ], ['deploy.prune_versions_failed']];
        yield 'DeleteFunction' => [[
            new Result(['FunctionVersion' => '7']), new Result(['Versions' => array_map(fn (int $v) => ['Version' => (string) $v], range(1, 7))]), $denied('DeleteFunction'),
            new Result(['Contents' => []]), new Result(['Contents' => []]),
        ], ['deploy.prune_versions_failed']];
        yield 'S3 DeleteObjects' => [[
            new Result(['FunctionVersion' => '2']), new Result(['Versions' => [['Version' => '1'], ['Version' => '2']]]),
            new Result(['Contents' => $old]), AwsMocks::error(S3Exception::class, 'DeleteObjects', 'AccessDenied', 403),
        ], ['deploy.prune_packages_failed']];
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
            self::lambdaMissing('DeleteFunction'),                                              // console never deployed
            new Result([]),                                                                     // DeleteWebFunction
            AwsMocks::error(LambdaWebException::class, 'GetWebFunction', 'ResourceNotFoundException', 404),
            new Result([]), new Result([]),                                                     // DeleteRolePolicy, DeleteRole
        );
        $steps = [];

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->remove($this->config(), function (string $key) use (&$steps): void { $steps[] = $key; });

        self::assertSame(['DescribeStacks', 'DeleteFunction', 'DeleteWebFunction', 'GetWebFunction', 'DeleteRolePolicy', 'DeleteRole'], $mocks->calls);
        self::assertSame('shop-production-console', $mocks->params[1]['FunctionName']);
        self::assertSame(['remove.step_console', 'remove.step_function'], $steps);
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
            new Result([]),                                                                     // DeleteFunction (console)
            new Result([]),
            AwsMocks::error(LambdaWebException::class, 'GetWebFunction', 'ResourceNotFoundException', 404),
            new Result([]), new Result([]),
        );
        $steps = [];

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->remove($config, function (string $key) use (&$steps): void { $steps[] = $key; });

        self::assertSame(['DescribeStacks', 'DescribeStacks', 'DeleteStack', 'DescribeStacks', 'ListCertificates', 'DeleteFunction', 'DeleteWebFunction', 'GetWebFunction', 'DeleteRolePolicy', 'DeleteRole'], $mocks->calls);
        self::assertSame(['remove.step_distribution', 'remove.step_console', 'remove.step_function'], $steps);
    }

    public function test_describe_includes_the_console_version_and_the_distribution(): void
    {
        $config = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->environment('production', fn (Environment $e) => $e->domain('a.example.com'))->toArray(), 'production');
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
}
