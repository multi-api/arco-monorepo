<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy;

use Aws\CloudFormation\Exception\CloudFormationException;
use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\EventBridge\Exception\EventBridgeException;
use Aws\Iam\Exception\IamException;
use Aws\Lambda\Exception\LambdaException;
use Aws\LambdaWeb\Exception\LambdaWebException;
use Aws\ResourceGroups\Exception\ResourceGroupsException;
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
use Multiapi\Arco\Deploy\Domains\CloudFrontAccess;
use Multiapi\Arco\Deploy\ResourcesStack;
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

    /** @return array<string, string> the tags of shop/production, or of another owner */
    private static function tags(string $app = 'shop', string $environment = 'production'): array
    {
        return ['arco:app' => $app, 'arco:environment' => $environment];
    }

    /** @param array<string, string> $tags @return list<array{Key: string, Value: string}> */
    private static function tagList(array $tags): array
    {
        return array_map(fn (string $k, string $v) => ['Key' => $k, 'Value' => $v], array_keys($tags), array_values($tags));
    }

    /**
     * The ownership reads of an existing classic function (GetFunctionConfiguration, ListTags).
     *
     * @param array<string, string>|null $tags null: shop/production's
     * @return list<Result>
     */
    private static function lambdaTags(string $name, ?array $tags = null, string $description = ''): array
    {
        return [
            new Result(['FunctionName' => $name, 'FunctionArn' => "arn:aws:lambda:us-east-1:111111111111:function:$name", 'Description' => $description]),
            new Result(['Tags' => $tags ?? self::tags()]),
        ];
    }

    /**
     * The ownership reads of an existing web function (GetWebFunction, ListTags).
     *
     * @param array<string, string>|null $tags
     * @return list<Result>
     */
    private static function webTags(?array $tags = null): array
    {
        return [
            new Result(['functionName' => 'shop-production', 'functionArn' => 'arn:aws:lambda:us-east-1:111111111111:web-function:shop-production', 'state' => 'Active']),
            new Result(['tags' => $tags ?? self::tags()]),
        ];
    }

    /** @return list<Result> the live revision rev-1, which pins no network connector (read after the ownership checks) */
    private static function liveRevision(): array
    {
        return [
            new Result(['domainName' => 'abc.on.aws', 'state' => 'Active', 'revisionWeights' => [['revisionId' => 'rev-1', 'weight' => 100]]]),
            new Result(['state' => 'Active', 'serviceConfig' => ['executionRoleArn' => 'arn:role']]),
        ];
    }

    private const LIVE_REVISION = ['GetWebFunctionEndpoint', 'GetWebFunctionRevision'];

    /** @param array<string, string>|null $tags */
    private static function roleTags(?array $tags = null, ?string $description = 'Execution role managed by Arco'): Result
    {
        return new Result(['Role' => ['Arn' => 'arn:aws:iam::111111111111:role/arco-shop-production', 'Tags' => self::tagList($tags ?? self::tags()), 'Description' => $description]]);
    }

    /**
     * The ownership reads of an existing scheduler rule (DescribeRule, ListTagsForResource).
     *
     * @param array<string, string>|null $tags
     * @return list<Result>
     */
    private static function ruleTags(?array $tags = null): array
    {
        return [
            new Result(['Name' => 'arco-shop-production-scheduler', 'Arn' => 'arn:aws:events:us-east-1:111111111111:rule/arco-shop-production-scheduler', 'Description' => 'Arco: schedule:run every 1 min on shop-production-console:live']),
            new Result(['Tags' => self::tagList($tags ?? self::tags())]),
        ];
    }

    private static function noRole(): IamException
    {
        return AwsMocks::error(IamException::class, 'GetRole', 'NoSuchEntity', 404);
    }

    private static function noWeb(): LambdaWebException
    {
        return AwsMocks::error(LambdaWebException::class, 'GetWebFunction', 'ResourceNotFoundException', 404);
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

    private const BOUNDARY = 'arn:aws:iam::123456789012:policy/arco-boundary';

    private function boundaryConfig(): AppConfig
    {
        return ConfigResolver::resolve(App::make('shop')->region('us-east-1')->permissionsBoundary(self::BOUNDARY)->environment('production')->toArray(), 'production');
    }

    /** @return list<Result> GetCallerIdentity, HeadBucket, the bucket hardening PUTs, HeadObject (the web zip is already uploaded) */
    private static function untilTheRole(): array
    {
        return [new Result(['Account' => '111111111111']), new Result([]), new Result([]), new Result([]), new Result([]), new Result([]), new Result(['VersionId' => 'v1'])];
    }

    private const UNTIL_THE_ROLE = ['GetCallerIdentity', 'HeadBucket', 'PutPublicAccessBlock', 'PutBucketPolicy', 'PutBucketVersioning', 'PutBucketLifecycleConfiguration', 'HeadObject'];

    /** @return iterable<string, array{list<mixed>, list<string>, string, string}> */
    public static function deniedRoles(): iterable
    {
        yield 'CreateRole' => [[self::noRole(), AwsMocks::error(IamException::class, 'CreateRole', 'AccessDenied', 403)], ['GetRole', 'CreateRole'], 'iam:CreateRole', 'AccessDenied'];
        yield 'PutRolePermissionsBoundary' => [
            [new Result(['Role' => ['Arn' => 'arn:role', 'Tags' => [['Key' => 'arco:region', 'Value' => 'us-east-1']]]]), AwsMocks::error(IamException::class, 'PutRolePermissionsBoundary', 'AccessDenied', 403)],
            ['GetRole', 'PutRolePermissionsBoundary'], 'iam:PutRolePermissionsBoundary', 'AccessDenied',
        ];
        yield 'PutRolePolicy' => [
            [new Result(['Role' => ['Arn' => 'arn:role', 'Tags' => [['Key' => 'arco:region', 'Value' => 'us-east-1']], 'PermissionsBoundary' => ['PermissionsBoundaryArn' => self::BOUNDARY]]]), AwsMocks::error(IamException::class, 'PutRolePolicy', 'AccessDeniedException', 403)],
            ['GetRole', 'PutRolePolicy'], 'iam:PutRolePolicy', 'AccessDeniedException',
        ];
    }

    /**
     * @param list<mixed> $iam
     * @param list<string> $iamCalls
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('deniedRoles')]
    public function test_a_role_the_account_guard_denies_stops_the_deploy_before_any_lambda_call(array $iam, array $iamCalls, string $action, string $code): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(...[...self::untilTheRole(), ...$iam]);
        $deployer = new AwsDeployer($mocks->clients(), AwsMocks::noSleep());

        try {
            $deployer->prepare($this->boundaryConfig(), $this->package(), $this->console(), []);
            self::fail('expected deploy.boundary_denied');
        } catch (DeployException $e) {
            self::assertSame('deploy.boundary_denied', $e->key);
            self::assertSame(['role' => 'arco-shop-production', 'boundary' => self::BOUNDARY, 'action' => $action, 'error' => $code], $e->params);
        }
        self::assertSame([...self::UNTIL_THE_ROLE, ...$iamCalls], $mocks->calls, 'the role step comes before every function step: no Lambda call after the denial');
        self::assertCount(0, $mocks->handler);
    }

    public function test_a_redeploy_puts_the_boundary_on_the_role_and_streams_it_in_the_step_params(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(...[
            ...self::untilTheRole(),
            new Result(['Role' => ['Arn' => 'arn:role', 'Tags' => [['Key' => 'arco:region', 'Value' => 'us-east-1']]]]), // GetRole: no boundary yet
            new Result([]),                                   // PutRolePermissionsBoundary
            new Result([]),                                   // PutRolePolicy
            new Result(['functionName' => 'shop-production', 'state' => 'Active']),
            new Result(['domainName' => 'abc.on.aws', 'state' => 'Active', 'revisionWeights' => [['revisionId' => 'rev-1', 'weight' => 100]]]),
            new Result(['revisionId' => 'rev-2', 'state' => 'Pending']),
            new Result(['state' => 'Active', 'stateReason' => 'ok']),
        ]);
        $steps = [];
        $deployer = new AwsDeployer($mocks->clients(), AwsMocks::noSleep());

        $deployer->prepare($this->boundaryConfig(), $this->package(), null, [], function (string $key, array $params) use (&$steps): void { $steps[] = [$key, $params]; });

        self::assertSame([...self::UNTIL_THE_ROLE, 'GetRole', 'PutRolePermissionsBoundary', 'PutRolePolicy', 'GetWebFunction', 'GetWebFunctionEndpoint', 'CreateWebFunctionRevision', 'GetWebFunctionRevision'], $mocks->calls);
        self::assertSame(self::BOUNDARY, $mocks->params[8]['PermissionsBoundary']);
        self::assertSame([
            ['deploy.step_role', ['role' => 'arco-shop-production', 'boundary' => self::BOUNDARY]],
            ['deploy.boundary_set', ['role' => 'arco-shop-production', 'boundary' => self::BOUNDARY]],
        ], array_values(array_filter($steps, fn (array $s) => str_contains($s[0], 'role') || str_contains($s[0], 'boundary'))));
    }

    public function test_without_a_boundary_the_role_step_and_its_calls_are_as_before(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(...[
            ...self::untilTheRole(),
            new Result(['Role' => ['Arn' => 'arn:role', 'Tags' => [['Key' => 'arco:region', 'Value' => 'us-east-1']]]]),
            new Result([]),                                   // PutRolePolicy
            new Result(['functionName' => 'shop-production', 'state' => 'Active']),
            new Result(['domainName' => 'abc.on.aws', 'state' => 'Active', 'revisionWeights' => [['revisionId' => 'rev-1', 'weight' => 100]]]),
            new Result(['revisionId' => 'rev-2', 'state' => 'Pending']),
            new Result(['state' => 'Active', 'stateReason' => 'ok']),
        ]);
        $steps = [];
        $deployer = new AwsDeployer($mocks->clients(), AwsMocks::noSleep());

        $deployer->prepare($this->config(), $this->package(), null, [], function (string $key, array $params) use (&$steps): void { $steps[] = [$key, $params]; });

        self::assertSame([...self::UNTIL_THE_ROLE, 'GetRole', 'PutRolePolicy', 'GetWebFunction', 'GetWebFunctionEndpoint', 'CreateWebFunctionRevision', 'GetWebFunctionRevision'], $mocks->calls);
        self::assertSame([], array_values(array_filter(array_column($steps, 0), fn (string $key) => str_contains($key, 'boundary'))), 'no boundary step or note without a boundary');
        self::assertContains(['deploy.step_role', ['role' => 'arco-shop-production']], $steps, 'no boundary param without a boundary');
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
            new Result(['Role' => ['Arn' => 'arn:role', 'Tags' => [['Key' => 'arco:region', 'Value' => 'us-east-1']]]]), // GetRole
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
            new Result(['Role' => ['Arn' => 'arn:role', 'Tags' => [['Key' => 'arco:region', 'Value' => 'us-east-1']]]]),
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

    private const WEB_ARN = 'arn:aws:lambda:us-east-1:111111111111:web-function/shop-production';
    private const CERTIFICATE = 'arn:aws:acm:us-east-1:111111111111:certificate/0a1b2c3d-1111-2222-3333-444455556666';

    private function domainConfig(): AppConfig
    {
        return ConfigResolver::resolve(App::make('shop')->region('us-east-1')->environment('production', fn (Environment $e) => $e
            ->domain('arco.acmeapp.io')->dns(false)->certificate(self::CERTIFICATE))->toArray(), 'production');
    }

    /** @param array<string, mixed> $extra */
    private static function webEndpoint(string $authType, string $revision, array $extra = []): Result
    {
        return new Result([
            'functionArn' => self::WEB_ARN, 'endpointArn' => self::WEB_ARN . '/endpoint/default', 'authType' => $authType,
            'state' => 'Active', 'updateStatus' => 'Successful', 'domainName' => 'abc.lambda-web.us-east-1.on.aws',
            'revisionWeights' => [['revisionId' => $revision, 'weight' => 100]],
        ] + $extra);
    }

    private static function domainStack(string $status): Result
    {
        return new Result(['Stacks' => [[
            'StackName' => 'arco-shop-production', 'StackStatus' => $status,
            'Tags' => self::tagList(self::tags()),
            'Outputs' => [['OutputKey' => 'DistributionId', 'OutputValue' => 'E123'], ['OutputKey' => 'DistributionDomain', 'OutputValue' => 'd111.cloudfront.net'], ['OutputKey' => 'Aliases', 'OutputValue' => 'arco.acmeapp.io']],
        ]]]);
    }

    public function test_an_upgrade_with_a_domain_requires_signatures_only_after_the_distribution_signs_and_may_invoke(): void
    {
        $config = $this->domainConfig();
        $mocks = new AwsMocks();
        $mocks->handler->append(
            // release: traffic moves, the endpoint stays as it is
            self::webEndpoint('ApplicationManaged', 'rev-1'),
            new Result([]),                                                                  // UpdateWebFunctionEndpoint
            self::webEndpoint('ApplicationManaged', 'rev-2'),
            // attachDomains: the stack puts the origin access control on the origin
            self::domainStack('UPDATE_COMPLETE'),
            new Result(['Certificate' => ['CertificateArn' => self::CERTIFICATE, 'DomainName' => 'arco.acmeapp.io', 'SubjectAlternativeNames' => ['arco.acmeapp.io'], 'Status' => 'ISSUED', 'Type' => 'AMAZON_ISSUED', 'KeyAlgorithm' => 'RSA-2048']]),
            self::domainStack('UPDATE_COMPLETE'),
            new Result([]),                                                                  // UpdateStack
            self::domainStack('UPDATE_COMPLETE'),
            // then the policy, then the endpoint
            self::webEndpoint('ApplicationManaged', 'rev-2'),
            AwsMocks::error(LambdaWebException::class, 'GetResourcePolicy', 'ResourceNotFoundException', 404),
            new Result([]),                                                                  // PutResourcePolicy
            new Result(['Distribution' => ['Id' => 'E123', 'Status' => 'Deployed']]),
            self::webEndpoint('ApplicationManaged', 'rev-2'),
            new Result([]),                                                                  // UpdateWebFunctionEndpoint
            self::webEndpoint('IamAuth', 'rev-2'),
        );
        $steps = [];
        $step = function (string $key) use (&$steps): void { $steps[] = $key; };
        $site = new \GuzzleHttp\Handler\MockHandler([new \GuzzleHttp\Psr7\Response(200)]);
        $deployer = new AwsDeployer($mocks->clients(), AwsMocks::noSleep(), http: new \GuzzleHttp\Client(['handler' => \GuzzleHttp\HandlerStack::create($site)]));

        $result = $deployer->release($config, new PreparedDeploy('rev-2', 'rev-1', 'b', 'k', null, null), $step);
        $deployer->attachDomains($config, $result, [], $step, '/health');

        self::assertSame([
            'GetWebFunctionEndpoint', 'UpdateWebFunctionEndpoint', 'GetWebFunctionEndpoint',
            'DescribeStacks', 'DescribeCertificate', 'DescribeStacks', 'UpdateStack', 'DescribeStacks',
            'GetWebFunctionEndpoint', 'GetResourcePolicy', 'PutResourcePolicy', 'GetDistribution',
            'GetWebFunctionEndpoint', 'UpdateWebFunctionEndpoint', 'GetWebFunctionEndpoint',
        ], $mocks->calls);
        self::assertSame('ApplicationManaged', $mocks->params[1]['authType'], 'traffic moves without locking the endpoint before CloudFront signs');
        $template = json_decode((string) $mocks->params[6]['TemplateBody'], true);
        self::assertSame('always-amz-auth', $template['Resources']['OriginAccess']['Properties']['OriginAccessControlConfig']['SigningBehavior']);
        self::assertSame(['Fn::GetAtt' => ['OriginAccess', 'Id']], $template['Resources']['Distribution']['Properties']['DistributionConfig']['Origins'][0]['OriginAccessControlId']);
        $policy = json_decode((string) $mocks->params[10]['policy'], true);
        self::assertSame('arn:aws:cloudfront::111111111111:distribution/E123', $policy['Statement'][0]['Condition']['StringEquals']['aws:SourceArn']);
        self::assertSame('IamAuth', $mocks->params[13]['authType']);
        self::assertSame(['deploy.step_traffic', 'domains.step_certificate', 'domains.certificate_using', 'domains.step_distribution',
            'deploy.oac_policy', 'deploy.endpoint_protected', 'deploy.protection_check', 'domains.step_dns', 'domains.manual_records', 'domains.manual_record'], $steps);
        self::assertSame('https://d111.cloudfront.net/health', (string) $site->getLastRequest()?->getUri(), 'the preset\'s health path through the distribution, before DNS');
    }

    /** Runs $test with AWS_ENDPOINT_URL pointing at an emulator, as Floci deploys do. */
    private static function behindEmulator(\Closure $test): void
    {
        $saved = getenv('AWS_ENDPOINT_URL');
        putenv('AWS_ENDPOINT_URL=http://localhost:4566');
        try {
            $test();
        } finally {
            putenv($saved === false ? 'AWS_ENDPOINT_URL' : "AWS_ENDPOINT_URL=$saved");
        }
    }

    public function test_behind_an_emulator_endpoint_a_domain_is_served_without_the_cloudfront_protection(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::webEndpoint('ApplicationManaged', 'rev-1'), new Result([]), self::webEndpoint('ApplicationManaged', 'rev-2'),
            self::domainStack('UPDATE_COMPLETE'),
            new Result(['Certificate' => ['CertificateArn' => self::CERTIFICATE, 'DomainName' => 'arco.acmeapp.io', 'SubjectAlternativeNames' => ['arco.acmeapp.io'], 'Status' => 'ISSUED', 'Type' => 'AMAZON_ISSUED', 'KeyAlgorithm' => 'RSA-2048']]),
            self::domainStack('UPDATE_COMPLETE'), new Result([]), self::domainStack('UPDATE_COMPLETE'),
        );
        $steps = [];
        $step = function (string $key) use (&$steps): void { $steps[] = $key; };

        self::behindEmulator(function () use ($mocks, $step): void {
            $deployer = new AwsDeployer($mocks->clients(), AwsMocks::noSleep());
            $result = $deployer->release($this->domainConfig(), new PreparedDeploy('rev-2', 'rev-1', 'b', 'k', null, null), $step);
            $deployer->attachDomains($this->domainConfig(), $result, [], $step);
        });

        self::assertSame(['GetWebFunctionEndpoint', 'UpdateWebFunctionEndpoint', 'GetWebFunctionEndpoint',
            'DescribeStacks', 'DescribeCertificate', 'DescribeStacks', 'UpdateStack', 'DescribeStacks'], $mocks->calls, 'no policy, no auth switch');
        self::assertSame('ApplicationManaged', $mocks->params[1]['authType'], 'the configured auth');
        $template = json_decode((string) $mocks->params[6]['TemplateBody'], true);
        self::assertArrayNotHasKey('OriginAccess', $template['Resources']);
        self::assertArrayNotHasKey('OriginAccessControlId', $template['Resources']['Distribution']['Properties']['DistributionConfig']['Origins'][0]);
        self::assertSame(['deploy.step_traffic', 'deploy.endpoint_unprotected_emulator', 'domains.step_certificate', 'domains.certificate_using',
            'domains.step_distribution', 'domains.step_dns', 'domains.manual_records', 'domains.manual_record'], $steps);
        self::assertSame('note', \Multiapi\Arco\Cli\StepPrinter::kind('deploy.endpoint_unprotected_emulator', []));
    }

    public function test_with_a_domain_traffic_and_rollback_keep_the_endpoints_auth(): void
    {
        foreach (['ApplicationManaged', 'IamAuth'] as $auth) {
            $mocks = new AwsMocks();
            $mocks->handler->append(
                self::webEndpoint($auth, 'rev-1'), new Result([]), self::webEndpoint($auth, 'rev-2'),
                self::webEndpoint($auth, 'rev-2'), new Result([]), self::webEndpoint($auth, 'rev-1'),
            );
            $deployer = new AwsDeployer($mocks->clients(), AwsMocks::noSleep());

            $deployer->release($this->domainConfig(), new PreparedDeploy('rev-2', 'rev-1', 'b', 'k', null, null));
            $deployer->rollback($this->domainConfig(), 'rev-1');

            self::assertSame(['GetWebFunctionEndpoint', 'UpdateWebFunctionEndpoint', 'GetWebFunctionEndpoint', 'GetWebFunctionEndpoint', 'UpdateWebFunctionEndpoint', 'GetWebFunctionEndpoint'], $mocks->calls);
            self::assertSame([$auth, $auth], [$mocks->params[1]['authType'], $mocks->params[4]['authType']]);
        }
    }

    public function test_a_redeploy_with_a_locked_endpoint_only_checks_the_policy(): void
    {
        $config = $this->domainConfig();
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::domainStack('UPDATE_COMPLETE'),
            new Result(['Certificate' => ['CertificateArn' => self::CERTIFICATE, 'DomainName' => 'arco.acmeapp.io', 'SubjectAlternativeNames' => ['arco.acmeapp.io'], 'Status' => 'ISSUED', 'Type' => 'AMAZON_ISSUED', 'KeyAlgorithm' => 'RSA-2048']]),
            self::domainStack('UPDATE_COMPLETE'),
            AwsMocks::error(CloudFormationException::class, 'UpdateStack', 'ValidationError', 400, 'No updates are to be performed.'),
            self::webEndpoint('IamAuth', 'rev-2'),
            new Result(['policy' => json_encode(['Version' => '2012-10-17', 'Statement' => [CloudFrontAccess::statement(self::WEB_ARN . '/endpoint/default', 'arn:aws:cloudfront::111111111111:distribution/E123')]]), 'revisionId' => '0a1b2c3d-1111-2222-3333-444455556666']),
        );

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->attachDomains($config, new DeployResult('abc.lambda-web.us-east-1.on.aws', 'rev-2', 'rev-1', 'b', 'k'), []);

        self::assertSame(['DescribeStacks', 'DescribeCertificate', 'DescribeStacks', 'UpdateStack', 'GetWebFunctionEndpoint', 'GetResourcePolicy'], $mocks->calls);
    }

    public function test_when_domain_leaves_arco_php_the_statement_goes_before_the_endpoint_is_public_again(): void
    {
        $foreign = ['Sid' => 'PartnerReads', 'Effect' => 'Allow', 'Principal' => ['AWS' => '222222222222'], 'Action' => 'lambda:GetWebFunction', 'Resource' => self::WEB_ARN];
        $ours = CloudFrontAccess::statement(self::WEB_ARN . '/endpoint/default', 'arn:aws:cloudfront::111111111111:distribution/E123');
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::webEndpoint('IamAuth', 'rev-1'),
            self::domainStack('UPDATE_COMPLETE'),                                           // the stack is left
            new Result(['policy' => json_encode(['Version' => '2012-10-17', 'Statement' => [$ours, $foreign]]), 'revisionId' => '0a1b2c3d-1111-2222-3333-444455556666']),
            new Result([]),                                                                  // PutResourcePolicy
            new Result([]),                                                                  // UpdateWebFunctionEndpoint
            self::webEndpoint('ApplicationManaged', 'rev-2'),
        );
        $steps = [];

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->release($this->config(), new PreparedDeploy('rev-2', 'rev-1', 'b', 'k', null, null), function (string $key) use (&$steps): void { $steps[] = $key; });

        self::assertSame(['GetWebFunctionEndpoint', 'DescribeStacks', 'GetResourcePolicy', 'PutResourcePolicy', 'UpdateWebFunctionEndpoint', 'GetWebFunctionEndpoint'], $mocks->calls);
        self::assertSame([$foreign], json_decode((string) $mocks->params[3]['policy'], true)['Statement'], 'only Arco\'s statement goes');
        self::assertSame('ApplicationManaged', $mocks->params[4]['authType']);
        self::assertSame(['deploy.step_traffic', 'deploy.endpoint_unprotected'], $steps);
    }

    /**
     * auth('iam') → auth('public') of an environment that never had domain(): as in 0.11.3, no policy call (the
     * minimal permissions have none) and no domain() step. The one DescribeStacks finds no stack, hence no
     * distribution that could hold a grant.
     */
    public function test_iam_to_public_without_a_domain_makes_no_policy_call_and_no_domain_step(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(self::webEndpoint('IamAuth', 'rev-1'), self::noDomainStack(), new Result([]), self::webEndpoint('ApplicationManaged', 'rev-2'));
        $steps = [];

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->release($this->config(), new PreparedDeploy('rev-2', 'rev-1', 'b', 'k', null, null), function (string $key) use (&$steps): void { $steps[] = $key; });

        self::assertSame(['GetWebFunctionEndpoint', 'DescribeStacks', 'UpdateWebFunctionEndpoint', 'GetWebFunctionEndpoint'], $mocks->calls);
        self::assertSame('ApplicationManaged', $mocks->params[2]['authType']);
        self::assertSame(['deploy.step_traffic'], $steps);
    }

    private function iamConfig(): AppConfig
    {
        return ConfigResolver::resolve(App::make('shop')->region('us-east-1')->auth('iam')->environment('production')->toArray(), 'production');
    }

    private static function noDomainStack(): CloudFormationException
    {
        return AwsMocks::error(CloudFormationException::class, 'DescribeStacks', 'ValidationError', 400, 'Stack with id arco-shop-production does not exist');
    }

    public function test_without_a_domain_traffic_moves_exactly_as_before(): void
    {
        // auth('iam') adds one read: whether a domains stack is left whose distribution could still invoke the endpoint.
        foreach ([[$this->config(), 'ApplicationManaged', []], [$this->iamConfig(), 'IamAuth', ['DescribeStacks']]] as [$config, $auth, $check]) {
            $mocks = new AwsMocks();
            $mocks->handler->append(self::webEndpoint($auth, 'rev-1'), ...($check === [] ? [] : [self::noDomainStack()]));
            $mocks->handler->append(new Result([]), self::webEndpoint($auth, 'rev-2'));

            (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->release($config, new PreparedDeploy('rev-2', 'rev-1', 'b', 'k', null, null));

            self::assertSame(['GetWebFunctionEndpoint', ...$check, 'UpdateWebFunctionEndpoint', 'GetWebFunctionEndpoint'], $mocks->calls);
            self::assertSame(['functionName' => 'shop-production', 'endpointName' => 'default', 'authType' => $auth, 'autoDeploymentMode' => 'Disabled', 'revisionWeights' => [['revisionId' => 'rev-2', 'weight' => 100]]],
                array_diff_key($mocks->params[1 + count($check)], ['@http' => 1, '@context' => 1]));
        }
    }

    /**
     * auth('public') + domain() → auth('iam'), or auth('iam') + domain() → auth('iam'): the deploy leaves the
     * domains stack (only remove deletes it), so its distribution would keep serving the app through the
     * custom domain unless Arco's statement goes. Both transitions arrive at the same state: an IamAuth endpoint,
     * no domain() and a stack left behind.
     */
    public function test_when_domain_leaves_an_iam_environment_cloudfront_loses_access_and_the_endpoint_stays_iam(): void
    {
        $foreign = ['Sid' => 'PartnerReads', 'Effect' => 'Allow', 'Principal' => ['AWS' => '222222222222'], 'Action' => 'lambda:GetWebFunction', 'Resource' => self::WEB_ARN];
        $ours = CloudFrontAccess::statement(self::WEB_ARN . '/endpoint/default', 'arn:aws:cloudfront::111111111111:distribution/E123');
        foreach ([[[$ours, $foreign], 'PutResourcePolicy'], [[$ours], 'DeleteResourcePolicy']] as [$statements, $write]) {
            $mocks = new AwsMocks();
            $mocks->handler->append(
                self::webEndpoint('IamAuth', 'rev-1'),
                self::domainStack('UPDATE_COMPLETE'),                                       // the stack is left
                new Result(['policy' => json_encode(['Version' => '2012-10-17', 'Statement' => $statements]), 'revisionId' => '0a1b2c3d-1111-2222-3333-444455556666']),
                new Result([]),                                                              // the write
                new Result([]),                                                              // UpdateWebFunctionEndpoint
                self::webEndpoint('IamAuth', 'rev-2'),
            );
            $steps = [];

            (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->release($this->iamConfig(), new PreparedDeploy('rev-2', 'rev-1', 'b', 'k', null, null), function (string $key) use (&$steps): void { $steps[] = $key; });

            self::assertSame(['GetWebFunctionEndpoint', 'DescribeStacks', 'GetResourcePolicy', $write, 'UpdateWebFunctionEndpoint', 'GetWebFunctionEndpoint'], $mocks->calls);
            if ($write === 'PutResourcePolicy') {
                self::assertSame([$foreign], json_decode((string) $mocks->params[3]['policy'], true)['Statement'], 'only Arco\'s statement goes');
            }
            self::assertSame('IamAuth', $mocks->params[4]['authType']);
            self::assertSame(['deploy.step_traffic', 'deploy.oac_revoked'], $steps);
        }
    }

    public function test_an_iam_environment_without_a_domain_stack_never_reads_the_policy(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(self::webEndpoint('IamAuth', 'rev-1'), self::noDomainStack(), new Result([]), self::webEndpoint('IamAuth', 'rev-2'));
        $steps = [];

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->release($this->iamConfig(), new PreparedDeploy('rev-2', 'rev-1', 'b', 'k', null, null), function (string $key) use (&$steps): void { $steps[] = $key; });

        self::assertSame(['GetWebFunctionEndpoint', 'DescribeStacks', 'UpdateWebFunctionEndpoint', 'GetWebFunctionEndpoint'], $mocks->calls);
        self::assertSame(['deploy.step_traffic'], $steps);
    }

    public function test_a_refused_domain_stack_read_is_a_warning_and_the_policy_is_not_read(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::webEndpoint('IamAuth', 'rev-1'),
            AwsMocks::error(CloudFormationException::class, 'DescribeStacks', 'AccessDenied', 403, 'not authorized to perform: cloudformation:DescribeStacks'),
            new Result([]),
            self::webEndpoint('IamAuth', 'rev-2'),
        );
        $steps = [];

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->release($this->iamConfig(), new PreparedDeploy('rev-2', 'rev-1', 'b', 'k', null, null), function (string $key, array $params) use (&$steps): void { $steps[] = [$key, $params]; });

        self::assertSame(['GetWebFunctionEndpoint', 'DescribeStacks', 'UpdateWebFunctionEndpoint', 'GetWebFunctionEndpoint'], $mocks->calls);
        self::assertSame([
            ['deploy.step_traffic', ['revision' => 'rev-2']],
            ['deploy.domain_stack_unreadable', ['stack' => 'arco-shop-production', 'function' => 'shop-production', 'error' => 'not authorized to perform: cloudformation:DescribeStacks']],
        ], $steps);
        self::assertSame('warning', \Multiapi\Arco\Cli\StepPrinter::kind('deploy.domain_stack_unreadable', []));
    }

    public function test_a_left_stack_without_arcos_statement_prints_no_revocation(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::webEndpoint('IamAuth', 'rev-1'),
            self::domainStack('UPDATE_COMPLETE'),
            AwsMocks::error(LambdaWebException::class, 'GetResourcePolicy', 'ResourceNotFoundException', 404),
            new Result([]),
            self::webEndpoint('IamAuth', 'rev-2'),
        );
        $steps = [];

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->release($this->iamConfig(), new PreparedDeploy('rev-2', 'rev-1', 'b', 'k', null, null), function (string $key) use (&$steps): void { $steps[] = $key; });

        self::assertSame(['GetWebFunctionEndpoint', 'DescribeStacks', 'GetResourcePolicy', 'UpdateWebFunctionEndpoint', 'GetWebFunctionEndpoint'], $mocks->calls);
        self::assertSame(['deploy.step_traffic'], $steps, 'nothing was revoked');
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
        $mocks->handler->append(...[
            new Result(['ResourceTagMappingList' => []]),                                       // GetResources: no workers
            // ownership: no events: access (no rule), no console, the web function and role are shop/production's
            AwsMocks::error(EventBridgeException::class, 'DescribeRule', 'AccessDeniedException', 400),
            self::lambdaMissing('GetFunctionConfiguration'),
            ...self::webTags(),
            self::roleTags(), ...self::liveRevision(),
            AwsMocks::error(CloudFormationException::class, 'DescribeStacks', 'AccessDenied', 403),
            AwsMocks::error(EventBridgeException::class, 'DescribeRule', 'AccessDeniedException', 400), // no events: access, no rule
            AwsMocks::error(CloudFormationException::class, 'DescribeStacks', 'AccessDenied', 403), // no resources stack
            self::lambdaMissing('DeleteFunction'),                                              // console never deployed
            new Result([]),                                                                     // DeleteWebFunction
            AwsMocks::error(LambdaWebException::class, 'GetWebFunction', 'ResourceNotFoundException', 404),
            new Result([]), new Result([]),                                                     // DeleteRolePolicy, DeleteRole
        ]);
        $steps = [];

        $mocks->handler->append(self::noGroup());                                  // GetGroupQuery: no resource group
        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->remove($this->config(), function (string $key) use (&$steps): void { $steps[] = $key; });

        self::assertSame(['GetResources', 'DescribeRule', 'GetFunctionConfiguration', 'GetWebFunction', 'ListTags', 'GetRole', ...self::LIVE_REVISION,
            'DescribeStacks', 'DescribeRule', 'DescribeStacks', 'DeleteFunction', 'DeleteWebFunction', 'GetWebFunction', 'DeleteRolePolicy', 'DeleteRole', 'GetGroupQuery'], $mocks->calls);
        self::assertSame('shop-production-console', $mocks->params[11]['FunctionName']);
        self::assertSame(['remove.scheduler_unreadable', 'remove.step_console', 'remove.step_function'], $steps, 'an unreadable rule is a warning, and the remove goes on');
        self::assertContains('remove.scheduler_unreadable', \Multiapi\Arco\Cli\StepPrinter::WARNINGS);
    }

    public function test_remove_with_domains_removes_the_distribution_before_the_function(): void
    {
        $config = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->environment('production', fn (Environment $e) => $e->domain('a.example.com')->dns(false))->toArray(), 'production');
        $missing = fn () => AwsMocks::error(CloudFormationException::class, 'DescribeStacks', 'ValidationError', 400, 'Stack with id arco-shop-production does not exist');
        $mocks = new AwsMocks();
        $mocks->handler->append(...[
            new Result(['ResourceTagMappingList' => []]),                                       // GetResources: no workers
            self::noRule(), ...self::lambdaTags('shop-production-console', null, 'Arco console function (Arco 0.8.1)'), ...self::webTags(), self::roleTags(), ...self::liveRevision(),
            new Result(['Stacks' => [['StackName' => 'arco-shop-production', 'StackStatus' => 'CREATE_COMPLETE', 'Tags' => [['Key' => 'arco:app', 'Value' => 'shop'], ['Key' => 'arco:environment', 'Value' => 'production']], 'Outputs' => [['OutputKey' => 'DistributionDomain', 'OutputValue' => 'd1.cloudfront.net']]]]]),
            new Result(['Stacks' => [['StackName' => 'arco-shop-production', 'StackStatus' => 'CREATE_COMPLETE']]]),
            new Result([]),                                                                     // DeleteStack
            $missing(),
            new Result(['CertificateSummaryList' => []]),
            self::noRule(),
            AwsMocks::error(CloudFormationException::class, 'DescribeStacks', 'ValidationError', 400, 'Stack with id arco-shop-production-resources does not exist'),
            new Result([]),                                                                     // DeleteFunction (console)
            new Result([]),
            AwsMocks::error(LambdaWebException::class, 'GetWebFunction', 'ResourceNotFoundException', 404),
            new Result([]), new Result([]),
        ]);
        $steps = [];

        $mocks->handler->append(self::noGroup());                                  // GetGroupQuery: no resource group
        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->remove($config, function (string $key) use (&$steps): void { $steps[] = $key; });

        self::assertSame(['GetResources', 'DescribeRule', 'GetFunctionConfiguration', 'ListTags', 'GetWebFunction', 'ListTags', 'GetRole', ...self::LIVE_REVISION,
            'DescribeStacks', 'DescribeStacks', 'DeleteStack', 'DescribeStacks', 'ListCertificates', 'DescribeRule', 'DescribeStacks', 'DeleteFunction', 'DeleteWebFunction', 'GetWebFunction', 'DeleteRolePolicy', 'DeleteRole', 'GetGroupQuery'], $mocks->calls);
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
        $mocks->handler->append(...[
            new Result(['ResourceTagMappingList' => []]),                                       // GetResources: no workers
            self::noRule(), self::lambdaMissing('GetFunctionConfiguration'), ...self::webTags(), self::roleTags(), ...self::liveRevision(),
            $stack('CREATE_COMPLETE'),                                                          // stack exists?
            $stack('CREATE_COMPLETE'),                                                          // CustomDomains::remove
            $stack('CREATE_COMPLETE'), new Result([]),                                          // Stacks::delete
            AwsMocks::error(CloudFormationException::class, 'DescribeStacks', 'ValidationError', 400, 'Stack with id arco-shop-production does not exist'),
            new Result(['CertificateSummaryList' => []]),
            self::noRule(),
            AwsMocks::error(CloudFormationException::class, 'DescribeStacks', 'ValidationError', 400, 'Stack with id arco-shop-production-resources does not exist'),
            new Result([]),                                                                     // DeleteFunction (console)
            new Result([]),
            AwsMocks::error(LambdaWebException::class, 'GetWebFunction', 'ResourceNotFoundException', 404),
            new Result([]), new Result([]),
        ]);
        $dns = new AwsMocks();
        $dns->handler->append(
            new Result(['HostedZones' => [['Id' => '/hostedzone/Z1', 'Name' => 'acmeapp.io.', 'Config' => ['PrivateZone' => false]]], 'IsTruncated' => false]),
            new Result(['ResourceRecordSets' => [$alias]]),
            new Result(['ChangeInfo' => ['Id' => '/change/R', 'Status' => 'INSYNC']]),
            new Result(['ChangeInfo' => ['Id' => '/change/R', 'Status' => 'INSYNC']]),
        );

        $mocks->handler->append(self::noGroup());                                  // GetGroupQuery: no resource group
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
        $mocks->handler->append(...[
            ...self::lambdaTags('shop-production-console'),                                     // ownership of the console
            new Result(['Name' => 'arco-shop-production-scheduler']),                          // DescribeRule: a rule is left
            ...self::ruleTags(),                                                                // ownership of the rule
            new Result(['FailedEntryCount' => 0]), new Result([]), new Result([]),              // RemoveTargets, DeleteRule, RemovePermission
            new Result([]),                                                                     // DeleteFunction
            self::noRule(),
            self::lambdaMissing('DeleteFunction'),
        ]);
        $deployer = new AwsDeployer($mocks->clients(), AwsMocks::noSleep());
        $steps = [];
        $onStep = function (string $key, array $params) use (&$steps): void { $steps[] = [$key, $params]; };

        $deployer->removeConsole($this->config(), $onStep);
        $deployer->removeConsole($this->config(), $onStep); // already gone: ResourceNotFoundException is swallowed

        self::assertSame(['GetFunctionConfiguration', 'ListTags', 'DescribeRule', 'DescribeRule', 'ListTagsForResource', 'RemoveTargets', 'DeleteRule', 'RemovePermission', 'DeleteFunction', 'DescribeRule', 'DeleteFunction'], $mocks->calls, 'the rule goes before the function it invokes; ownership is read once');
        self::assertSame('shop-production-console', $mocks->params[8]['FunctionName']);
        self::assertSame([
            ['deploy.scheduler_removed', []],
            ['deploy.console_removed', ['function' => 'shop-production-console']],
            ['deploy.console_removed', ['function' => 'shop-production-console']],
        ], $steps);
    }

    public function test_remove_deletes_scheduler_rule_before_console(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(...[
            new Result(['ResourceTagMappingList' => []]),                                       // GetResources: no workers
            ...self::ruleTags(), ...self::lambdaTags('shop-production-console'), ...self::webTags(), self::roleTags(), ...self::liveRevision(),
            AwsMocks::error(CloudFormationException::class, 'DescribeStacks', 'AccessDenied', 403),
            new Result(['Name' => 'arco-shop-production-scheduler']),                          // DescribeRule
            new Result(['FailedEntryCount' => 0]), new Result([]), new Result([]),              // RemoveTargets, DeleteRule, RemovePermission
            AwsMocks::error(CloudFormationException::class, 'DescribeStacks', 'AccessDenied', 403), // no resources stack
            new Result([]),                                                                     // DeleteFunction (console)
            new Result([]),                                                                     // DeleteWebFunction
            AwsMocks::error(LambdaWebException::class, 'GetWebFunction', 'ResourceNotFoundException', 404),
            new Result([]), new Result([]),                                                     // DeleteRolePolicy, DeleteRole
        ]);
        $steps = [];

        $mocks->handler->append(self::noGroup());                                  // GetGroupQuery: no resource group
        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->remove($this->config(), function (string $key) use (&$steps): void { $steps[] = $key; });

        self::assertSame(['GetResources', 'DescribeRule', 'ListTagsForResource', 'GetFunctionConfiguration', 'ListTags', 'GetWebFunction', 'ListTags', 'GetRole', ...self::LIVE_REVISION,
            'DescribeStacks', 'DescribeRule', 'RemoveTargets', 'DeleteRule', 'RemovePermission', 'DescribeStacks', 'DeleteFunction', 'DeleteWebFunction', 'GetWebFunction', 'DeleteRolePolicy', 'DeleteRole', 'GetGroupQuery'], $mocks->calls);
        self::assertSame('arco-shop-production-scheduler', $mocks->params[13]['Name']);
        self::assertSame('shop-production-console', $mocks->params[16]['FunctionName']);
        self::assertSame(['remove.step_scheduler', 'remove.step_console', 'remove.step_function'], $steps);
    }

    public function test_sync_scheduler_points_the_rule_at_the_live_alias(): void
    {
        $config = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->scheduler(5)->environment('production')->toArray(), 'production');
        $alias = 'arn:aws:lambda:us-east-1:123456789012:function:shop-production-console:live';
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['AliasArn' => $alias, 'FunctionVersion' => '7']),
            AwsMocks::error(EventBridgeException::class, 'DescribeRule', 'ResourceNotFoundException', 400), // ownership: no rule yet
            AwsMocks::error(EventBridgeException::class, 'DescribeRule', 'ResourceNotFoundException', 400),
            new Result(['RuleArn' => 'arn:aws:events:us-east-1:123456789012:rule/arco-shop-production-scheduler']),
            new Result(['FailedEntryCount' => 0]),
            new Result(['Statement' => '{}']),
        );
        $steps = [];

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->syncScheduler($config, function (string $key, array $params) use (&$steps): void { $steps[] = [$key, $params]; });

        self::assertSame(['GetAlias', 'DescribeRule', 'DescribeRule', 'PutRule', 'PutTargets', 'AddPermission'], $mocks->calls);
        self::assertSame(['FunctionName' => 'shop-production-console', 'Name' => 'live'], array_intersect_key($mocks->params[0], ['FunctionName' => 1, 'Name' => 1]));
        self::assertSame([['Key' => 'arco:app', 'Value' => 'shop'], ['Key' => 'arco:environment', 'Value' => 'production']], $mocks->params[3]['Tags']);
        self::assertSame('ENABLED', $mocks->params[3]['State'], 'a new rule starts enabled');
        self::assertSame($alias, $mocks->params[4]['Targets'][0]['Arn']);
        self::assertSame([['deploy.scheduler_set', ['every' => 5]]], $steps);
    }

    public function test_sync_scheduler_keeps_a_paused_rule_paused(): void
    {
        $config = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->scheduler(5)->environment('production')->toArray(), 'production');
        $mocks = new AwsMocks();
        $mocks->handler->append(...[
            new Result(['AliasArn' => 'arn:alias', 'FunctionVersion' => '7']),
            ...self::ruleTags(),
            new Result(['Name' => 'arco-shop-production-scheduler', 'State' => 'DISABLED']),
            new Result(['RuleArn' => 'arn:rule']),
            new Result(['FailedEntryCount' => 0]),
            new Result([]),
        ]);
        $steps = [];

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->syncScheduler($config, function (string $key, array $params) use (&$steps): void { $steps[] = [$key, $params]; });

        self::assertSame('DISABLED', $mocks->params[4]['State']);
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
        $mocks->handler->append(...array_fill(0, 9, new Result()));
        $steps = [];
        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->applyLogRetention($with(30, true), function (string $key, array $params) use (&$steps): void { $steps[] = [$key, $params]; });
        self::assertSame(['DescribeLogGroups', 'CreateLogGroup', 'PutRetentionPolicy', 'DescribeLogGroups', 'CreateLogGroup', 'PutRetentionPolicy', 'DescribeLogGroups', 'CreateLogGroup', 'PutRetentionPolicy'], $mocks->calls);
        self::assertSame('/aws/lambda/shop-production-console', $mocks->params[7]['logGroupName']);
        self::assertSame([['deploy.log_retention', ['days' => 30, 'groups' => 'all']]], $steps);

        // Console off: only the web groups. false: policies are removed.
        $mocks = new AwsMocks();
        $mocks->handler->append(self::retention('/aws/lambda/web/shop-production', 30), new Result(), new Result(), self::retention('/aws/lambda/web/shop-production.local', 30), new Result(), new Result());
        $steps = [];
        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->applyLogRetention($with(false, false), function (string $key, array $params) use (&$steps): void { $steps[] = [$key, $params]; });
        self::assertSame(['DescribeLogGroups', 'CreateLogGroup', 'DeleteRetentionPolicy', 'DescribeLogGroups', 'CreateLogGroup', 'DeleteRetentionPolicy'], $mocks->calls);
        self::assertSame([['deploy.log_retention_never', ['groups' => 'web']]], $steps);

        // One day: its own key, so the line is not "1 days".
        $mocks = new AwsMocks();
        $mocks->handler->append(...array_fill(0, 6, new Result()));
        $steps = [];
        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->applyLogRetention($with(1, false), function (string $key, array $params) use (&$steps): void { $steps[] = [$key, $params]; });
        self::assertSame([['deploy.log_retention_one_day', ['groups' => 'web']]], $steps);

        // No setting: the 30-day default on the web, console and queue worker groups, announced once.
        $mocks = new AwsMocks();
        $mocks->handler->append(...array_fill(0, 9, new Result()));
        $steps = [];
        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->applyLogRetention($with(null, true), function (string $key, array $params) use (&$steps): void { $steps[] = [$key, $params]; });
        self::assertSame(['DescribeLogGroups', 'CreateLogGroup', 'PutRetentionPolicy', 'DescribeLogGroups', 'CreateLogGroup', 'PutRetentionPolicy', 'DescribeLogGroups', 'CreateLogGroup', 'PutRetentionPolicy'], $mocks->calls);
        self::assertSame([30, 30, 30], array_column(array_filter($mocks->params, static fn (array $p): bool => isset($p['retentionInDays'])), 'retentionInDays'));
        self::assertSame([['deploy.log_retention_default', ['groups' => 'all']]], $steps);
    }

    public function test_the_default_retention_covers_the_queue_workers_and_is_announced_once(): void
    {
        $config = ConfigResolver::resolve(App::make('shop')->console(false)->region('us-east-1')->queue('emails')->environment('production')->toArray(), 'production');
        $mocks = new AwsMocks();
        $mocks->handler->append(...array_fill(0, 9, new Result()));
        $steps = [];

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->applyLogRetention($config, function (string $key, array $params) use (&$steps): void { $steps[] = [$key, $params]; });

        self::assertContains('/aws/lambda/shop-production-queue-emails', array_column($mocks->params, 'logGroupName'));
        self::assertSame([['deploy.log_retention_default', ['groups' => 'web_queues']]], $steps);
    }

    /** A DescribeLogGroups answer: the group with its retention (null: never expires). */
    private static function retention(string $group, ?int $days): Result
    {
        return new Result(['logGroups' => [['logGroupName' => $group] + ($days === null ? [] : ['retentionInDays' => $days])]]);
    }

    public function test_the_default_is_announced_only_when_it_changes_a_group(): void
    {
        $config = ConfigResolver::resolve(App::make('shop')->console(false)->region('us-east-1')->environment('production')->toArray(), 'production');
        $exists = fn () => AwsMocks::error(\Aws\CloudWatchLogs\Exception\CloudWatchLogsException::class, 'CreateLogGroup', 'ResourceAlreadyExistsException', 400);

        // The second deploy: both groups already keep 30 days. No PutRetentionPolicy, no line.
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::retention('/aws/lambda/web/shop-production', 30), $exists(), new Result(['Account' => '111111111111']), new Result(),
            self::retention('/aws/lambda/web/shop-production.local', 30), $exists(), new Result(),
        );
        $steps = [];
        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->applyLogRetention($config, function (string $key, array $params) use (&$steps): void { $steps[] = [$key, $params]; });
        self::assertNotContains('PutRetentionPolicy', $mocks->calls);
        self::assertSame([], $steps);
        self::assertCount(0, $mocks->handler);

        // An old group that kept logs forever: it gets 30 days, and the line is printed once.
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::retention('/aws/lambda/web/shop-production', null), $exists(), new Result(['Account' => '111111111111']), new Result(), new Result(),
            self::retention('/aws/lambda/web/shop-production.local', 30), $exists(), new Result(),
        );
        $steps = [];
        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->applyLogRetention($config, function (string $key, array $params) use (&$steps): void { $steps[] = [$key, $params]; });
        self::assertSame(1, count(array_keys($mocks->calls, 'PutRetentionPolicy', true)));
        self::assertSame([['deploy.log_retention_default', ['groups' => 'web']]], $steps);

        // Without logs:DescribeLogGroups: as before 0.9, every group gets the call and the line is printed.
        $denied = fn () => AwsMocks::error(\Aws\CloudWatchLogs\Exception\CloudWatchLogsException::class, 'DescribeLogGroups', 'AccessDeniedException', 400);
        $mocks = new AwsMocks();
        $mocks->handler->append($denied(), new Result(), new Result(), $denied(), new Result(), new Result());
        $steps = [];
        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->applyLogRetention($config, function (string $key, array $params) use (&$steps): void { $steps[] = [$key, $params]; });
        self::assertSame(2, count(array_keys($mocks->calls, 'PutRetentionPolicy', true)));
        self::assertSame([['deploy.log_retention_default', ['groups' => 'web']]], $steps);
    }

    public function test_an_explicit_retention_or_false_never_prints_the_default_line(): void
    {
        foreach ([14, false] as $setting) {
            $config = ConfigResolver::resolve(App::make('shop')->console(false)->region('us-east-1')->logRetention($setting)->environment('production')->toArray(), 'production');
            $mocks = new AwsMocks();
            $mocks->handler->append(...($setting === false
                ? [self::retention('/aws/lambda/web/shop-production', 30), new Result(), new Result(), self::retention('/aws/lambda/web/shop-production.local', 30), new Result(), new Result()]
                : array_fill(0, 6, new Result())));
            $steps = [];

            (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->applyLogRetention($config, function (string $key, array $params) use (&$steps): void { $steps[] = [$key, $params]; });

            self::assertNotContains('deploy.log_retention_default', array_column($steps, 0));
            self::assertSame($setting === false ? ['DescribeLogGroups', 'CreateLogGroup', 'DeleteRetentionPolicy', 'DescribeLogGroups', 'CreateLogGroup', 'DeleteRetentionPolicy'] : ['DescribeLogGroups', 'CreateLogGroup', 'PutRetentionPolicy', 'DescribeLogGroups', 'CreateLogGroup', 'PutRetentionPolicy'], $mocks->calls);
        }
    }

    public function test_log_retention_covers_the_queue_workers(): void
    {
        $with = function (bool $console): AppConfig {
            $app = App::make('shop')->console($console)->region('us-east-1')->logRetention(14)->queue('emails')->queue('reports');
            return ConfigResolver::resolve($app->environment('production')->toArray(), 'production');
        };

        // Console off: web plus each worker's group, never the console's.
        $mocks = new AwsMocks();
        $mocks->handler->append(...array_fill(0, 12, new Result()));
        $steps = [];
        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->applyLogRetention($with(false), function (string $key, array $params) use (&$steps): void { $steps[] = [$key, $params]; });
        $groups = array_values(array_unique(array_column($mocks->params, 'logGroupName')));
        self::assertSame(['/aws/lambda/web/shop-production', '/aws/lambda/web/shop-production.local', '/aws/lambda/shop-production-queue-emails', '/aws/lambda/shop-production-queue-reports'], $groups);
        self::assertSame([['deploy.log_retention', ['days' => 14, 'groups' => 'web_queues']]], $steps);

        // Console on: web, console and workers.
        $mocks = new AwsMocks();
        $mocks->handler->append(...array_fill(0, 15, new Result()));
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
        $mocks->handler->append(...[
            new Result(['Name' => SchedulerRule::name($this->config())]),
            ...self::ruleTags(),
            new Result(['FailedEntryCount' => 0]), new Result([]), new Result([]),
            self::noRule(),
            AwsMocks::error(EventBridgeException::class, 'DescribeRule', 'AccessDeniedException', 400),
            self::lambdaMissing('GetPolicy'),
        ]);
        $deployer = new AwsDeployer($mocks->clients(), AwsMocks::noSleep());
        $steps = [];
        $onStep = function (string $key) use (&$steps): void { $steps[] = $key; };

        $deployer->syncScheduler($this->config(), $onStep);
        $deployer->syncScheduler($this->config(), $onStep); // no rule: nothing to say
        $deployer->syncScheduler($this->config(), $onStep); // no events: access and no invoke statement on the alias: nothing either

        self::assertSame(['DescribeRule', 'DescribeRule', 'ListTagsForResource', 'RemoveTargets', 'DeleteRule', 'RemovePermission', 'DescribeRule', 'DescribeRule', 'GetPolicy'], $mocks->calls);
        self::assertSame(['FunctionName' => 'shop-production-console', 'Qualifier' => 'live'], array_intersect_key($mocks->params[8], ['FunctionName' => 1, 'Qualifier' => 1]));
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
        $mocks->handler->append(...[
            ...self::lambdaTags('shop-production-console'),
            AwsMocks::error(EventBridgeException::class, 'DescribeRule', 'AccessDeniedException', 400, 'not authorized to perform: events:DescribeRule'),
            new Result([]),                                                                     // DeleteFunction
        ]);
        $steps = [];

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->removeConsole($this->config(), function (string $key, array $params) use (&$steps): void { $steps[] = [$key, $params]; });

        self::assertSame(['GetFunctionConfiguration', 'ListTags', 'DescribeRule', 'DeleteFunction'], $mocks->calls);
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
            self::runningTemplate($this->queueConfig()),         // GetTemplate: what the stack runs now
            self::resourcesStack(),                              // Stacks::deploy
            new Result([]),                                      // UpdateStack
            self::resourcesStack(),
            new Result(['Attributes' => ['ApproximateNumberOfMessages' => '12']]),
        );

        [$calls, $steps] = $this->deployResources($mocks, $this->queueConfig());

        self::assertSame(['DescribeStacks', 'DescribeStackResources', 'GetTemplate', 'DescribeStacks', 'UpdateStack', 'DescribeStacks', 'GetQueueAttributes'], $calls, 'queues the stack owns need no name check');
        $template = json_decode((string) $mocks->params[4]['TemplateBody'], true);
        self::assertIsArray($template);
        self::assertSame(['QueueEmails', 'QueueEmailsDlq'], array_keys($template['Resources']));
        self::assertSame('https://sqs.us-east-1.amazonaws.com/111111111111/shop-production-old', $mocks->params[6]['QueueUrl']);
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

    /** GetTemplate's answer: the template the stack runs, as ResourcesStack would have deployed it for $config. */
    private static function runningTemplate(AppConfig $config): Result
    {
        return new Result(['TemplateBody' => ResourcesStack::body(ResourcesStack::template($config))]);
    }

    /**
     * Deploys $config over a stack that runs $before (null: GetTemplate is denied) and returns the keys of the
     * per-resource steps.
     *
     * @param array<string, array{0: string, 1: string}> $owned
     * @return list<string>
     */
    private function updateKeys(AppConfig $config, ?AppConfig $before, array $owned): array
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::resourcesStack(),
            self::ownedResources($owned),
            $before === null ? AwsMocks::error(\Aws\CloudFormation\Exception\CloudFormationException::class, 'GetTemplate', 'AccessDenied', 403) : self::runningTemplate($before),
            self::resourcesStack(),
            new Result([]),
            self::resourcesStack(),
        );
        [, $steps] = $this->deployResources($mocks, $config);
        return array_values(array_filter(array_column($steps, 0), static fn (string $key): bool => ! in_array($key, ['deploy.step_resources', 'deploy.storage_public'], true)));
    }

    public function test_deploy_reports_a_bucket_whose_lifecycle_cors_or_policy_changed_as_updated(): void
    {
        $owned = ['StorageUploads' => ['AWS::S3::Bucket', 'shop-production-uploads-111111111111']];
        $plain = $this->resourcesConfig(fn (App $app) => $app->storage('uploads'));

        self::assertSame(['deploy.storage_updated'], $this->updateKeys($plain, $plain, $owned), 'nothing changed');
        self::assertSame(['deploy.storage_changed'], $this->updateKeys(
            $this->resourcesConfig(fn (App $app) => $app->storage('uploads', cors: ['https://a.test'])), $plain, $owned,
        ), 'CORS added');
        self::assertSame(['deploy.storage_changed'], $this->updateKeys(
            $this->resourcesConfig(fn (App $app) => $app->storage('uploads', expire: ['tmp/' => 7])), $plain, $owned,
        ), 'lifecycle rule added');

        // The stack of 0.6, before the abort-uploads rule: the template the stack runs has no such rule.
        $old = ResourcesStack::template($plain);
        unset($old['Resources']['StorageUploads']['Properties']['LifecycleConfiguration']);
        $mocks = new AwsMocks();
        $mocks->handler->append(self::resourcesStack(), self::ownedResources($owned), new Result(['TemplateBody' => json_encode($old)]), self::resourcesStack(), new Result([]), self::resourcesStack());
        [, $steps] = $this->deployResources($mocks, $plain);
        self::assertSame('deploy.storage_changed', $steps[1][0]);
    }

    public function test_deploy_reports_a_changed_bucket_policy_for_its_bucket_and_a_changed_table_or_queue(): void
    {
        $owned = ['StorageUploads' => ['AWS::S3::Bucket', 'shop-production-uploads-111111111111'], 'TableCache' => ['AWS::DynamoDB::Table', 'shop-production-cache'], 'QueueEmails' => ['AWS::SQS::Queue', 'https://sqs.us-east-1.amazonaws.com/111111111111/shop-production-emails'], 'QueueEmailsDlq' => ['AWS::SQS::Queue', 'https://sqs.us-east-1.amazonaws.com/111111111111/shop-production-emails-dlq']];
        $build = static fn (array $public, int $tries): callable => static fn (App $app) => $app->storage('uploads', public: $public)->cache()->queue('emails', tries: $tries);
        $before = $this->resourcesConfig($build(['public/'], 3));

        self::assertSame(['deploy.queue_updated', 'deploy.storage_updated', 'deploy.table_updated'], $this->updateKeys($before, $before, $owned));
        // Another public prefix changes only the bucket policy; more tries change the queue's redrive policy.
        self::assertSame(['deploy.queue_updated', 'deploy.storage_changed', 'deploy.table_updated'], $this->updateKeys($this->resourcesConfig($build(['public/', 'avatars/'], 3)), $before, $owned));
        self::assertSame(['deploy.queue_changed', 'deploy.storage_updated', 'deploy.table_updated'], $this->updateKeys($this->resourcesConfig($build(['public/'], 5)), $before, $owned));
    }

    public function test_deploy_reports_updated_when_the_running_template_cannot_be_read(): void
    {
        $plain = $this->resourcesConfig(fn (App $app) => $app->storage('uploads'));

        self::assertSame(['deploy.storage_changed'], $this->updateKeys($plain, null, ['StorageUploads' => ['AWS::S3::Bucket', 'shop-production-uploads-111111111111']]));
    }

    public function test_any_failed_template_read_reports_updated_and_the_deploy_goes_on(): void
    {
        // Not only a denial: any failed GetTemplate means "unknown", never a failed deploy (retryable errors are retried by the SDK first).
        $plain = $this->resourcesConfig(fn (App $app) => $app->storage('uploads'));
        foreach ([['ValidationError', 400], ['InvalidOperation', 400], ['TokenAlreadyExistsException', 400]] as [$code, $status]) {
            $mocks = new AwsMocks();
            $mocks->handler->append(
                self::resourcesStack(),
                self::ownedResources(['StorageUploads' => ['AWS::S3::Bucket', 'shop-production-uploads-111111111111']]),
                AwsMocks::error(CloudFormationException::class, 'GetTemplate', $code, $status, 'Rate exceeded'),
                self::resourcesStack(),
                new Result([]),
                self::resourcesStack(),
            );
            [$calls, $steps] = $this->deployResources($mocks, $plain);
            self::assertContains('UpdateStack', $calls, $code);
            self::assertContains('deploy.storage_changed', array_column($steps, 0), $code);
        }
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
            self::runningTemplate($this->resourcesConfig(fn (App $app) => $app->storage('uploads'))),
            self::resourcesStack(),                              // Stacks::deploy
            new Result([]),                                      // UpdateStack
            self::resourcesStack(),
            new Result(['KeyCount' => 1]),                       // ListObjectsV2: files left
        );

        [$calls, $steps] = $this->deployResources($mocks, $this->resourcesConfig(fn (App $app) => $app->storage('uploads')));

        self::assertSame(['DescribeStacks', 'DescribeStackResources', 'GetTemplate', 'DescribeStacks', 'UpdateStack', 'DescribeStacks', 'ListObjectsV2'], $calls, 'buckets the stack owns need no name check');
        self::assertSame('shop-production-old-111111111111', $mocks->params[6]['Bucket']);
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
        $mocks->handler->append(...[
            new Result(['ResourceTagMappingList' => []]),                                       // GetResources: no workers
            self::noRule(), self::lambdaMissing('GetFunctionConfiguration'), ...self::webTags(), self::roleTags(), ...self::liveRevision(),
            AwsMocks::error(CloudFormationException::class, 'DescribeStacks', 'AccessDenied', 403), // domains stack
            self::noRule(),
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
        ]);
        $steps = [];

        $mocks->handler->append(self::noGroup());                                  // GetGroupQuery: no resource group
        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->remove($this->config(), function (string $key, array $params = []) use (&$steps): void { $steps[] = [$key, $params]; });

        // remove reads the live revision too (does it pin a connector?); nothing else is new.
        self::assertSame([
            'GetResources', 'DescribeRule', 'GetFunctionConfiguration', 'GetWebFunction', 'ListTags', 'GetRole', ...self::LIVE_REVISION,
            'DescribeStacks', 'DescribeRule',
            'DescribeStacks', 'DescribeStackResources', 'DescribeStacks', 'DeleteStack', 'DescribeStacks', 'ListObjectsV2', 'DescribeTable',
            'DeleteFunction', 'DeleteWebFunction', 'GetWebFunction', 'DeleteRolePolicy', 'DeleteRole', 'GetGroupQuery',
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
                ->queue('emails', timeout: 120, memory: 512, maxInstances: 5, batchSize: 10, tries: 4)
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
            new Result(['Role' => ['Arn' => 'arn:role', 'Tags' => [['Key' => 'arco:region', 'Value' => 'us-east-1']]]]), // GetRole
            new Result([]),                                   // PutRolePolicy
            new Result(['functionName' => 'shop-production', 'state' => 'Active']),
            new Result(['domainName' => 'abc.on.aws', 'state' => 'Active', 'revisionWeights' => [['revisionId' => 'rev-1', 'weight' => 100]]]),
            new Result(['revisionId' => 'rev-2', 'state' => 'Pending']),
            new Result(['state' => 'Active', 'stateReason' => 'ok']),
            new Result(['VersionId' => 'cv1']),               // HeadObject (console zip)
            new Result(['ContentLength' => 3, 'VersionId' => 'bv1']), new Result([]), // binary: HeadObject, CopyObject
            self::lambdaMissing('GetAlias'), new Result(['FunctionVersion' => '8']),     // live versions: emails (new), reports
            // Both workers are updated together, phase by phase: emails is new, reports is redeployed.
            self::lambdaMissing('GetFunctionConfiguration'), self::ready(),            // emails missing, reports exists
            self::ready(),                                                              // reports idle before its code update
            new Result(['State' => 'Pending']), new Result([]),                         // CreateFunction emails, UpdateFunctionCode reports
            self::ready(), self::ready(),                                                // both ready
            new Result([]), self::ready(),                                              // UpdateFunctionConfiguration reports, ready
            new Result(['Version' => '1']), new Result(['Version' => '9']),             // PublishVersion emails, reports
        );
        $steps = [];

        $prepared = (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->prepare($this->queuesConfig(), $this->package(), $this->console(), ['APP_ENV' => 'production'], function (string $key, array $params = []) use (&$steps): void { $steps[] = [$key, $params]; });

        self::assertSame(['emails' => ['version' => '1', 'previous' => null], 'reports' => ['version' => '9', 'previous' => '8']], $prepared->workerVersions);
        self::assertNull($prepared->consoleVersion, 'the console is off: only the workers use the console zip');
        self::assertSame([
            'GetCallerIdentity', 'HeadBucket', 'PutPublicAccessBlock', 'PutBucketPolicy', 'PutBucketVersioning', 'PutBucketLifecycleConfiguration', 'HeadObject', 'GetRole', 'PutRolePolicy', 'GetWebFunction', 'GetWebFunctionEndpoint',
            'CreateWebFunctionRevision', 'GetWebFunctionRevision', 'HeadObject', 'HeadObject', 'CopyObject',
            'GetAlias', 'GetAlias', 'GetFunctionConfiguration', 'GetFunctionConfiguration', 'GetFunctionConfiguration',
            'CreateFunction', 'UpdateFunctionCode', 'GetFunctionConfiguration', 'GetFunctionConfiguration',
            'UpdateFunctionConfiguration', 'GetFunctionConfiguration', 'PublishVersion', 'PublishVersion',
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
            new Result(['Role' => ['Arn' => 'arn:role', 'Tags' => [['Key' => 'arco:region', 'Value' => 'us-east-1']]]]), // GetRole
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
        $mocks->handler->append(...[
            self::workers('emails', 'gone'),
            // ownership: no rule, the workers (reports never deployed), console, web function, role
            self::noRule(),
            ...self::lambdaTags('shop-production-queue-emails'), self::lambdaMissing('GetFunctionConfiguration'), ...self::lambdaTags('shop-production-queue-gone'),
            ...self::lambdaTags('shop-production-console'), ...self::webTags(), self::roleTags(), ...self::liveRevision(),
            AwsMocks::error(CloudFormationException::class, 'DescribeStacks', 'ValidationError', 400, 'Stack with id arco-shop-production does not exist'), // domains stack
            self::noRule(),
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
        ]);
        $steps = [];

        $mocks->handler->append(self::noGroup());                                  // GetGroupQuery: no resource group
        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->remove($config, function (string $key, array $params = []) use (&$steps): void { $steps[] = [$key, $params]; });

        self::assertSame([
            'GetResources',
            'DescribeRule', 'GetFunctionConfiguration', 'ListTags', 'GetFunctionConfiguration', 'GetFunctionConfiguration', 'ListTags',
            'GetFunctionConfiguration', 'ListTags', 'GetWebFunction', 'ListTags', 'GetRole', ...self::LIVE_REVISION,
            'DescribeStacks', 'DescribeRule',
            'ListEventSourceMappings', 'DeleteEventSourceMapping', 'DeleteFunction',
            'ListEventSourceMappings', 'DeleteFunction',
            'ListEventSourceMappings', 'DeleteFunction',
            'DescribeStacks', 'DescribeStackResources', 'DescribeStacks', 'DeleteStack', 'DescribeStacks', 'GetQueueAttributes',
            'DeleteFunction', 'DeleteWebFunction', 'GetWebFunction', 'DeleteRolePolicy', 'DeleteRole', 'GetGroupQuery',
        ], $mocks->calls);
        self::assertSame('shop-production-console', $mocks->params[29]['FunctionName'], 'the console goes after the workers and the stack');
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
        $mocks->handler->append(...[
            AwsMocks::error(ResourceGroupsTaggingAPIException::class, 'GetResources', 'InvalidParameterException', 400, 'Rate exceeded'),
            self::noRule(), ...self::lambdaTags('shop-production-queue-emails'), self::lambdaMissing('GetFunctionConfiguration'), ...self::webTags(), self::roleTags(), ...self::liveRevision(),
            AwsMocks::error(CloudFormationException::class, 'DescribeStacks', 'AccessDenied', 403),
            self::noRule(),
            new Result(['EventSourceMappings' => []]), new Result([]),                          // emails: mappings, DeleteFunction
            AwsMocks::error(CloudFormationException::class, 'DescribeStacks', 'AccessDenied', 403),
            new Result([]), new Result([]),
            AwsMocks::error(LambdaWebException::class, 'GetWebFunction', 'ResourceNotFoundException', 404),
            new Result([]), new Result([]),
        ]);
        $steps = [];

        $mocks->handler->append(self::noGroup());                                  // GetGroupQuery: no resource group
        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->remove($config, function (string $key, array $params = []) use (&$steps): void { $steps[] = [$key, $params]; });

        self::assertContains(['remove.worker_scan_failed', ['error' => 'Rate exceeded']], $steps);
        self::assertContains('remove.step_worker', array_column($steps, 0));
        self::assertContains('remove.worker_scan_failed', \Multiapi\Arco\Cli\StepPrinter::WARNINGS);
    }

    /** @return list<array{0: string, 1: array<string, string|int>}> */
    private static function ownershipSteps(AwsDeployer $deployer, AppConfig $config): array
    {
        $steps = [];
        $deployer->checkOwnership($config, function (string $key, array $params = []) use (&$steps): void { $steps[] = [$key, $params]; });
        return $steps;
    }

    private function fullConfig(): AppConfig
    {
        return ConfigResolver::resolve(App::make('shop')->region('us-east-1')->console()->scheduler(1)->queue('emails')->environment('production')->toArray(), 'production');
    }

    public function test_check_ownership_reads_every_name_addressed_resource_and_changes_nothing_when_all_are_owned(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(...[
            self::roleTags(), ...self::webTags(), ...self::lambdaTags('shop-production-console'), ...self::lambdaTags('shop-production-queue-emails'), ...self::ruleTags(),
            new Result(['Stacks' => [['StackName' => 'arco-shop-production-resources', 'StackStatus' => 'CREATE_COMPLETE', 'Tags' => self::tagList(self::tags())]]]),
            ...self::liveRevision(),
        ]);

        $steps = self::ownershipSteps(new AwsDeployer($mocks->clients(), AwsMocks::noSleep()), $this->fullConfig());

        self::assertSame(['GetRole', 'GetWebFunction', 'ListTags', 'GetFunctionConfiguration', 'ListTags', 'GetFunctionConfiguration', 'ListTags', 'DescribeRule', 'ListTagsForResource', 'DescribeStacks', ...self::LIVE_REVISION], $mocks->calls, 'then the live revision, for its network connector');
        self::assertSame('arn:aws:lambda:us-east-1:111111111111:web-function:shop-production', $mocks->params[2]['resource']);
        self::assertSame('arn:aws:lambda:us-east-1:111111111111:function:shop-production-queue-emails', $mocks->params[6]['Resource']);
        self::assertSame([], $steps);
    }

    public function test_a_first_deploy_finds_nothing_and_changes_nothing(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(self::noRole(), self::noWeb(), self::lambdaMissing('GetFunctionConfiguration'), self::noRule());

        $steps = self::ownershipSteps(new AwsDeployer($mocks->clients(), AwsMocks::noSleep()), $this->consoleConfig());

        self::assertSame(['GetRole', 'GetWebFunction', 'GetFunctionConfiguration', 'DescribeRule'], $mocks->calls);
        self::assertSame([], $steps);
    }

    public function test_deploy_over_a_web_function_tagged_for_another_app_is_refused_before_anything_changes(): void
    {
        // App "shop" + env "eu-prod" and app "shop-eu" + env "prod" share every name.
        $config = ConfigResolver::resolve(App::make('shop-eu')->region('us-east-1')->environment('prod')->toArray(), 'prod');
        $mocks = new AwsMocks();
        $mocks->handler->append(...[
            new Result(['Role' => ['Arn' => 'arn:role', 'Tags' => self::tagList(self::tags('shop', 'eu-prod')), 'Description' => 'Execution role managed by Arco']]),
            ...self::webTags(self::tags('shop', 'eu-prod')), self::lambdaMissing('GetFunctionConfiguration'), self::noRule(),
        ]);

        try {
            self::ownershipSteps(new AwsDeployer($mocks->clients(), AwsMocks::noSleep()), $config);
            self::fail('expected a refusal');
        } catch (DeployException $e) {
            self::assertSame('deploy.not_owned', $e->key);
            self::assertSame(['resource' => 'arco-shop-eu-prod', 'app' => 'shop', 'environment' => 'eu-prod'], $e->params, 'the first resource read names the owner');
        }
        self::assertSame(['GetRole', 'GetWebFunction', 'ListTags', 'GetFunctionConfiguration', 'DescribeRule'], $mocks->calls, 'every resource is read first; nothing is updated or tagged');
    }

    public function test_a_web_function_of_another_environment_is_refused_even_with_a_custom_role(): void
    {
        $config = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->role('arn:aws:iam::111111111111:role/mine')->environment('production')->toArray(), 'production');
        $mocks = new AwsMocks();
        $mocks->handler->append(...[...self::webTags(self::tags('shop', 'staging')), self::lambdaMissing('GetFunctionConfiguration'), self::noRule()]);

        $this->expectExceptionObject(DeployException::make('deploy.not_owned', ['resource' => 'shop-production', 'app' => 'shop', 'environment' => 'staging']));
        self::ownershipSteps(new AwsDeployer($mocks->clients(), AwsMocks::noSleep()), $config);
    }

    private static function noStack(string $name = 'arco-shop-production-resources'): CloudFormationException
    {
        return AwsMocks::error(CloudFormationException::class, 'DescribeStacks', 'ValidationError', 400, "Stack with id $name does not exist");
    }

    public function test_a_foreign_resources_stack_is_refused_before_force_tags_anything(): void
    {
        // --force takes over the role of another environment, but the resources stack of that name is not
        // ours either: the refusal comes before any TagRole / TagResource, not after.
        $config = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->console(false)->queue('emails')->environment('production')->toArray(), 'production');
        $mocks = new AwsMocks();
        $mocks->handler->append(...[
            self::roleTags(self::tags('shop', 'staging')), ...self::webTags(self::tags('shop', 'staging')),
            self::lambdaMissing('GetFunctionConfiguration'), self::lambdaMissing('GetFunctionConfiguration'), self::noRule(),
            new Result(['Stacks' => [['StackName' => 'arco-shop-production-resources', 'StackStatus' => 'CREATE_COMPLETE', 'Tags' => self::tagList(self::tags('shop', 'staging'))]]]),
        ]);

        try {
            self::ownershipSteps(new AwsDeployer($mocks->clients(), AwsMocks::noSleep(), force: true), $config);
            self::fail('expected stack.not_ours');
        } catch (DeployException $e) {
            self::assertSame('stack.not_ours', $e->key);
        }
        self::assertSame(['GetRole', 'GetWebFunction', 'ListTags', 'GetFunctionConfiguration', 'GetFunctionConfiguration', 'DescribeRule', 'DescribeStacks'], $mocks->calls);
        self::assertSame([], array_values(array_filter($mocks->calls, fn (string $call) => str_starts_with($call, 'Tag'))), 'no tag was added');
        self::assertCount(0, $mocks->handler);
    }

    public function test_a_foreign_domains_stack_is_refused_before_force_tags_anything(): void
    {
        $config = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->console(false)->environment('production', fn (\Multiapi\Arco\Config\Environment $env) => $env->domain('shop.example.com'))->toArray(), 'production');
        $mocks = new AwsMocks();
        $mocks->handler->append(...[
            self::roleTags(self::tags('shop', 'staging')), ...self::webTags(self::tags('shop', 'staging')),
            self::lambdaMissing('GetFunctionConfiguration'), self::noRule(),
            new Result(['Stacks' => [['StackName' => 'arco-shop-production', 'StackStatus' => 'CREATE_COMPLETE', 'Tags' => []]]]),
        ]);

        try {
            self::ownershipSteps(new AwsDeployer($mocks->clients(), AwsMocks::noSleep(), force: true), $config);
            self::fail('expected domains.stack_not_ours');
        } catch (DeployException $e) {
            self::assertSame('domains.stack_not_ours', $e->key);
        }
        self::assertSame([], array_values(array_filter($mocks->calls, fn (string $call) => str_starts_with($call, 'Tag'))), 'no tag was added');
    }

    public function test_remove_refuses_when_the_role_was_created_for_another_region(): void
    {
        // region() moved from us-east-1 to eu-west-1: the role and CloudFront still serve the function in
        // us-east-1, so remove deletes nothing.
        $config = ConfigResolver::resolve(App::make('shop')->region('eu-west-1')->environment('production')->toArray(), 'production');
        $mocks = new AwsMocks();
        $mocks->handler->append(...[
            new Result(['ResourceTagMappingList' => []]), self::noRule(),
            self::lambdaMissing('GetFunctionConfiguration'), self::noWeb(),
            self::roleTags([...self::tags(), 'arco:region' => 'us-east-1']),
        ]);

        try {
            (new AwsDeployer($mocks->clients('eu-west-1'), AwsMocks::noSleep()))->remove($config);
            self::fail('expected deploy.region_changed');
        } catch (DeployException $e) {
            self::assertSame('deploy.region_changed', $e->key);
            self::assertSame(['resource' => 'arco-shop-production', 'current' => 'us-east-1', 'region' => 'eu-west-1'], $e->params);
        }
        self::assertSame([], array_values(array_filter($mocks->calls, fn (string $call) => str_starts_with($call, 'Delete'))), 'nothing deleted');
        self::assertCount(0, $mocks->handler);
    }

    public function test_untagged_resources_an_older_arco_made_are_owned_and_get_the_tags(): void
    {
        // A 0.3-era environment whose tags were stripped: Arco's own descriptions are still there.
        $mocks = new AwsMocks();
        $mocks->handler->append(...[
            self::roleTags([]),
            ...self::webTags([]),
            new Result(['state' => 'Active', 'revisionWeights' => [['revisionId' => 'rev-old', 'weight' => 100]]]), // GetWebFunctionEndpoint
            new Result(['revisionId' => 'rev-old', 'description' => 'Deployed by Arco 0.3.0', 'buildConfig' => ['codeConfig' => ['s3Object' => ['bucket' => 'arco-111111111111-us-east-1', 'key' => 'shop/production/' . str_repeat('a', 64) . '.zip']]]]), // GetWebFunctionRevision
            ...self::lambdaTags('shop-production-console', [], 'Arco console function (Arco 0.3.0)'),
            ...self::lambdaTags('shop-production-queue-emails', ['team' => 'x'], 'Arco worker for queue emails (Arco 0.5.0)'),
            new Result(['Name' => 'arco-shop-production-scheduler', 'Arn' => 'arn:rule', 'Description' => 'Arco: schedule:run every 1 min on shop-production-console:live']),
            new Result(['Tags' => []]),
            self::noStack(),                                                                  // DescribeStacks: no resources stack yet
            ...self::liveRevision(),
            new Result([]), new Result([]), new Result([]), new Result([]), new Result([]), // TagRole, TagResource x4
        ]);

        $steps = self::ownershipSteps(new AwsDeployer($mocks->clients(), AwsMocks::noSleep()), $this->fullConfig());

        self::assertSame([
            'GetRole', 'GetWebFunction', 'ListTags', 'GetWebFunctionEndpoint', 'GetWebFunctionRevision',
            'GetFunctionConfiguration', 'ListTags', 'GetFunctionConfiguration', 'ListTags', 'DescribeRule', 'ListTagsForResource', 'DescribeStacks',
            ...self::LIVE_REVISION, 'TagRole', 'TagResource', 'TagResource', 'TagResource', 'TagResource',
        ], $mocks->calls);
        $tags = self::tags();
        self::assertSame(['RoleName' => 'arco-shop-production', 'Tags' => self::tagList($tags)], array_diff_key($mocks->params[14], ['@http' => 1, '@context' => 1]));
        self::assertSame(['resource' => 'arn:aws:lambda:us-east-1:111111111111:web-function:shop-production', 'tags' => $tags], array_diff_key($mocks->params[15], ['@http' => 1, '@context' => 1]));
        self::assertSame(['Resource' => 'arn:aws:lambda:us-east-1:111111111111:function:shop-production-console', 'Tags' => $tags], array_diff_key($mocks->params[16], ['@http' => 1, '@context' => 1]));
        self::assertSame(['Resource' => 'arn:aws:lambda:us-east-1:111111111111:function:shop-production-queue-emails', 'Tags' => $tags], array_diff_key($mocks->params[17], ['@http' => 1, '@context' => 1]));
        self::assertSame(['ResourceARN' => 'arn:rule', 'Tags' => self::tagList($tags)], array_diff_key($mocks->params[18], ['@http' => 1, '@context' => 1]));
        $added = fn (string $resource) => ['deploy.tags_added', ['resource' => $resource, 'app' => 'shop', 'environment' => 'production']];
        self::assertSame([$added('arco-shop-production'), $added('shop-production'), $added('shop-production-console'), $added('shop-production-queue-emails'), $added('arco-shop-production-scheduler')], $steps);
        self::assertNotContains('deploy.tags_added', \Multiapi\Arco\Cli\StepPrinter::WARNINGS, 'a note, not a warning');
    }

    public function test_an_untagged_resource_without_arco_markers_is_refused_and_force_takes_it_over(): void
    {
        $responses = fn () => [
            self::noRole(), ...self::webTags([]),
            new Result(['state' => 'Active', 'revisionWeights' => [['revisionId' => 'rev-1', 'weight' => 100]]]),
            new Result(['revisionId' => 'rev-1', 'description' => 'my own function']),
            self::lambdaMissing('GetFunctionConfiguration'), self::noRule(),
        ];
        $mocks = new AwsMocks();
        $mocks->handler->append(...$responses());
        try {
            self::ownershipSteps(new AwsDeployer($mocks->clients(), AwsMocks::noSleep()), $this->config());
            self::fail('expected a refusal');
        } catch (DeployException $e) {
            self::assertSame('deploy.untagged_resource', $e->key);
            self::assertSame(['resource' => 'shop-production'], $e->params);
        }
        self::assertNotContains('TagResource', $mocks->calls);

        $mocks = new AwsMocks();
        $mocks->handler->append(...[...$responses(), ...self::liveRevision(), new Result([])]);
        $steps = self::ownershipSteps(new AwsDeployer($mocks->clients(), AwsMocks::noSleep(), force: true), $this->config());

        self::assertSame([...self::LIVE_REVISION, 'TagResource'], array_slice($mocks->calls, -3), 'taken over: tagged for this environment');
        self::assertSame([['deploy.ownership_forced', ['resource' => 'shop-production', 'app' => 'shop', 'environment' => 'production']]], $steps);
        self::assertContains('deploy.ownership_forced', \Multiapi\Arco\Cli\StepPrinter::WARNINGS);
    }

    public function test_force_deploys_over_another_owners_resource_and_retags_it_with_a_warning(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(...[self::noRole(), ...self::webTags(self::tags('shop', 'staging')), self::lambdaMissing('GetFunctionConfiguration'), self::noRule(), ...self::liveRevision(), new Result([])]);

        $steps = self::ownershipSteps(new AwsDeployer($mocks->clients(), AwsMocks::noSleep(), force: true), $this->config());

        self::assertSame(['GetRole', 'GetWebFunction', 'ListTags', 'GetFunctionConfiguration', 'DescribeRule', ...self::LIVE_REVISION, 'TagResource'], $mocks->calls);
        self::assertSame(self::tags(), $mocks->params[7]['tags']);
        self::assertSame([['deploy.ownership_forced', ['resource' => 'shop-production', 'app' => 'shop', 'environment' => 'production']]], $steps);
    }

    public function test_tags_that_cannot_be_read_stop_the_deploy_naming_the_permission(): void
    {
        $denied = fn () => AwsMocks::error(LambdaWebException::class, 'ListTags', 'AccessDeniedException', 403, 'not authorized to perform: lambda:ListTags');
        $mocks = new AwsMocks();
        $mocks->handler->append(...[self::roleTags(), self::webTags()[0], $denied(), self::lambdaMissing('GetFunctionConfiguration'), self::noRule()]);
        try {
            self::ownershipSteps(new AwsDeployer($mocks->clients(), AwsMocks::noSleep()), $this->config());
            self::fail('expected a refusal');
        } catch (DeployException $e) {
            self::assertSame('deploy.tags_unreadable', $e->key);
            self::assertSame(['resource' => 'shop-production', 'action' => 'lambda:ListTags', 'error' => 'not authorized to perform: lambda:ListTags'], $e->params);
        }

        $mocks = new AwsMocks();
        $mocks->handler->append(...[self::roleTags(), self::webTags()[0], $denied(), self::lambdaMissing('GetFunctionConfiguration'), self::noRule(), ...self::liveRevision()]);
        $steps = self::ownershipSteps(new AwsDeployer($mocks->clients(), AwsMocks::noSleep(), force: true), $this->config());
        self::assertNotContains('TagResource', $mocks->calls);
        self::assertSame([['deploy.ownership_forced', ['resource' => 'shop-production', 'app' => 'shop', 'environment' => 'production']]], $steps);
    }

    public function test_a_failed_tagging_of_an_owned_resource_is_a_warning(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(...[
            self::roleTags([]), self::noWeb(), self::lambdaMissing('GetFunctionConfiguration'), self::noRule(),
            AwsMocks::error(IamException::class, 'TagRole', 'AccessDenied', 403, 'not authorized to perform: iam:TagRole'),
        ]);

        $steps = self::ownershipSteps(new AwsDeployer($mocks->clients(), AwsMocks::noSleep()), $this->config());

        self::assertSame([['deploy.tags_not_added', ['resource' => 'arco-shop-production', 'error' => 'not authorized to perform: iam:TagRole']]], $steps);
        self::assertContains('deploy.tags_not_added', \Multiapi\Arco\Cli\StepPrinter::WARNINGS);
    }

    public function test_remove_of_a_console_function_tagged_for_another_environment_deletes_nothing(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(...[
            new Result(['ResourceTagMappingList' => []]),                                       // GetResources: no workers
            self::noRule(),
            ...self::lambdaTags('shop-production-console', self::tags('shop-production', 'console')),
            ...self::webTags(), self::roleTags(),
        ]);

        try {
            (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->remove($this->config());
            self::fail('expected a refusal');
        } catch (DeployException $e) {
            self::assertSame('remove.not_owned', $e->key);
            self::assertSame(['resource' => 'shop-production-console', 'app' => 'shop-production', 'environment' => 'console'], $e->params);
        }
        self::assertSame(['GetResources', 'DescribeRule', 'GetFunctionConfiguration', 'ListTags', 'GetWebFunction', 'ListTags', 'GetRole'], $mocks->calls, 'nothing deleted, not even the domains or the scheduler rule');
    }

    public function test_remove_refuses_an_untagged_resource_arco_did_not_make_and_deletes_an_untagged_one_it_did(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(...[
            new Result(['ResourceTagMappingList' => []]), self::noRule(), self::lambdaMissing('GetFunctionConfiguration'), self::noWeb(),
            self::roleTags([], 'someone else\'s role'),
        ]);
        $this->expectExceptionObject(DeployException::make('remove.untagged_resource', ['resource' => 'arco-shop-production']));
        try {
            (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->remove($this->config());
        } finally {
            self::assertNotContains('DeleteRole', $mocks->calls);
        }
    }

    public function test_remove_deletes_an_untagged_console_function_from_an_older_arco(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(...[
            new Result(['ResourceTagMappingList' => []]), self::noRule(),
            ...self::lambdaTags('shop-production-console', [], 'Arco console function (Arco 0.3.0)'),
            self::noWeb(), self::noRole(),
            AwsMocks::error(CloudFormationException::class, 'DescribeStacks', 'AccessDenied', 403), // domains stack
            self::noRule(),
            AwsMocks::error(CloudFormationException::class, 'DescribeStacks', 'AccessDenied', 403), // resources stack
            new Result([]),                                                                     // DeleteFunction (console)
            AwsMocks::error(LambdaWebException::class, 'DeleteWebFunction', 'ResourceNotFoundException', 404),
            AwsMocks::error(IamException::class, 'DeleteRolePolicy', 'NoSuchEntity', 404),
            AwsMocks::error(IamException::class, 'DeleteRole', 'NoSuchEntity', 404),
        ]);

        $mocks->handler->append(self::noGroup());                                  // GetGroupQuery: no resource group
        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->remove($this->config());

        self::assertContains('DeleteFunction', $mocks->calls);
        self::assertNotContains('TagResource', $mocks->calls, 'remove never tags');
        self::assertCount(0, $mocks->handler);
    }

    public function test_remove_tags_that_cannot_be_read_delete_nothing(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(...[
            new Result(['ResourceTagMappingList' => []]),
            new Result(['Name' => 'arco-shop-production-scheduler', 'Arn' => 'arn:rule']),
            AwsMocks::error(EventBridgeException::class, 'ListTagsForResource', 'AccessDeniedException', 400, 'not authorized'),
            self::lambdaMissing('GetFunctionConfiguration'), self::noWeb(), self::noRole(),
        ]);

        $this->expectExceptionObject(DeployException::make('remove.tags_unreadable', ['resource' => 'arco-shop-production-scheduler', 'action' => 'events:ListTagsForResource', 'error' => 'not authorized']));
        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->remove($this->config());
    }

    public function test_scheduler_apply_refuses_a_rule_of_another_environment(): void
    {
        $config = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->scheduler(5)->environment('production')->toArray(), 'production');
        $mocks = new AwsMocks();
        $mocks->handler->append(...[new Result(['AliasArn' => 'arn:alias', 'FunctionVersion' => '7']), ...self::ruleTags(self::tags('shop', 'staging'))]);

        try {
            (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->syncScheduler($config, fn () => null);
            self::fail('expected a refusal');
        } catch (DeployException $e) {
            self::assertSame('deploy.not_owned', $e->key);
        }
        self::assertSame(['GetAlias', 'DescribeRule', 'ListTagsForResource'], $mocks->calls, 'no PutRule');
    }

    public function test_a_deploy_reads_the_rule_once_checkownership_verified_it(): void
    {
        $config = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->console()->scheduler(5)->environment('production')->toArray(), 'production');
        $mocks = new AwsMocks();
        $mocks->handler->append(...[
            self::roleTags(), ...self::webTags(), ...self::lambdaTags('shop-production-console'), ...self::ruleTags(), ...self::liveRevision(),
            new Result(['AliasArn' => 'arn:alias', 'FunctionVersion' => '7']),
            new Result(['Name' => 'arco-shop-production-scheduler', 'State' => 'ENABLED']),
            new Result(['RuleArn' => 'arn:rule']), new Result(['FailedEntryCount' => 0]), new Result([]),
        ]);
        $deployer = new AwsDeployer($mocks->clients(), AwsMocks::noSleep());

        $deployer->checkOwnership($config, fn () => null);
        $deployer->syncScheduler($config, fn () => null);

        self::assertSame(1, count(array_keys($mocks->calls, 'ListTagsForResource', true)));
        self::assertCount(0, $mocks->handler);
    }

    public function test_every_ownership_message_exists_in_english_and_spanish(): void
    {
        foreach (['en', 'es'] as $lang) {
            $translator = new \Multiapi\Arco\Cli\Translator($lang, \Multiapi\Arco\Arco::packageRoot() . '/lang');
            foreach (['deploy.not_owned', 'deploy.untagged_resource', 'deploy.tags_unreadable', 'deploy.tags_added', 'deploy.tags_not_added', 'deploy.ownership_forced', 'remove.not_owned', 'remove.untagged_resource', 'remove.tags_unreadable', 'ownership.not_owned', 'ownership.untagged_resource', 'ownership.tags_unreadable'] as $key) {
                $text = $translator->trans($key, ['resource' => 'shop-production', 'app' => 'shop', 'environment' => 'production', 'action' => 'lambda:ListTags', 'error' => 'denied']);
                self::assertNotSame($key, $text, "$lang: $key");
                self::assertStringContainsString('shop-production', $text);
            }
        }
    }

    public function test_an_untagged_web_function_deployed_by_arco_for_another_environment_is_not_adopted(): void
    {
        // App "shop-eu" + env "prod": its zips live under shop-eu/prod/, not shop/eu-prod/.
        $config = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->environment('eu-prod')->toArray(), 'eu-prod');
        $mocks = new AwsMocks();
        $mocks->handler->append(...[
            self::noRole(), ...self::webTags([]),
            new Result(['state' => 'Active', 'revisionWeights' => [['revisionId' => 'rev-x', 'weight' => 100]]]),
            new Result(['revisionId' => 'rev-x', 'description' => 'Deployed by Arco 0.8.1', 'buildConfig' => ['codeConfig' => ['s3Object' => ['bucket' => 'arco-111111111111-us-east-1', 'key' => 'shop-eu/prod/' . str_repeat('a', 64) . '.zip']]]]),
            self::lambdaMissing('GetFunctionConfiguration'), self::noRule(),
        ]);

        $this->expectExceptionObject(DeployException::make('deploy.untagged_resource', ['resource' => 'shop-eu-prod']));
        self::ownershipSteps(new AwsDeployer($mocks->clients(), AwsMocks::noSleep()), $config);
    }

    public function test_a_rule_eventbridge_refuses_to_describe_stops_a_deploy_that_puts_it(): void
    {
        $denied = fn () => AwsMocks::error(EventBridgeException::class, 'DescribeRule', 'AccessDeniedException', 400, 'not authorized to perform: events:DescribeRule');
        $config = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->console()->scheduler(5)->environment('production')->toArray(), 'production');
        $mocks = new AwsMocks();
        $mocks->handler->append(...[self::roleTags(), ...self::webTags(), ...self::lambdaTags('shop-production-console'), $denied()]);

        $this->expectExceptionObject(DeployException::make('deploy.tags_unreadable', ['resource' => 'arco-shop-production-scheduler', 'action' => 'events:DescribeRule', 'error' => 'not authorized to perform: events:DescribeRule']));
        self::ownershipSteps(new AwsDeployer($mocks->clients(), AwsMocks::noSleep()), $config);
    }

    public function test_without_a_scheduler_an_undescribable_rule_is_left_to_the_scheduler_step_and_not_marked_verified(): void
    {
        $denied = fn () => AwsMocks::error(EventBridgeException::class, 'DescribeRule', 'AccessDeniedException', 400, 'not authorized');
        $mocks = new AwsMocks();
        $mocks->handler->append(...[self::roleTags(), ...self::webTags(), ...self::lambdaTags('shop-production-console'), $denied(), ...self::liveRevision(),
            $denied(), self::lambdaMissing('GetPolicy'),                                         // syncScheduler (off): reads the rule again
        ]);
        $deployer = new AwsDeployer($mocks->clients(), AwsMocks::noSleep());

        self::assertSame([], self::ownershipSteps($deployer, $this->consoleConfig()));
        $deployer->syncScheduler($this->consoleConfig(), fn () => null);

        self::assertSame(['GetRole', 'GetWebFunction', 'ListTags', 'GetFunctionConfiguration', 'ListTags', 'DescribeRule', ...self::LIVE_REVISION, 'DescribeRule', 'GetPolicy'], $mocks->calls);
        self::assertNotContains('DeleteRule', $mocks->calls);
    }

    public function test_scheduler_apply_refuses_when_the_rule_cannot_be_described(): void
    {
        $config = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->scheduler(5)->environment('production')->toArray(), 'production');
        $mocks = new AwsMocks();
        $mocks->handler->append(...[new Result(['AliasArn' => 'arn:alias', 'FunctionVersion' => '7']), AwsMocks::error(EventBridgeException::class, 'DescribeRule', 'AccessDeniedException', 400, 'not authorized')]);

        try {
            (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->syncScheduler($config, fn () => null);
            self::fail('expected a refusal');
        } catch (DeployException $e) {
            self::assertSame('deploy.tags_unreadable', $e->key);
            self::assertSame('events:DescribeRule', $e->params['action']);
        }
        self::assertSame(['GetAlias', 'DescribeRule'], $mocks->calls, 'no PutRule');
    }

    public function test_command_and_tinker_refuse_a_console_function_of_another_environment(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(...[
            ...self::lambdaTags('shop-production-console', self::tags('shop-production', 'console')),
            ...self::lambdaTags('shop-production-console'),
            self::lambdaMissing('GetFunctionConfiguration'),
            ...self::lambdaTags('shop-production-console', [], 'my own function'),
        ]);
        $config = $this->consoleConfig();

        try {
            \Multiapi\Arco\Deploy\OwnershipGuard::checkConsoleUse($mocks->clients(), $config);
            self::fail('expected a refusal');
        } catch (DeployException $e) {
            self::assertSame('ownership.not_owned', $e->key);
            self::assertSame(['resource' => 'shop-production-console', 'app' => 'shop-production', 'environment' => 'console'], $e->params);
        }
        \Multiapi\Arco\Deploy\OwnershipGuard::checkConsoleUse($mocks->clients(), $config); // ours
        \Multiapi\Arco\Deploy\OwnershipGuard::checkConsoleUse($mocks->clients(), $config); // missing: the invoke reports it
        $this->expectExceptionObject(DeployException::make('ownership.untagged_resource', ['resource' => 'shop-production-console']));
        \Multiapi\Arco\Deploy\OwnershipGuard::checkConsoleUse($mocks->clients(), $config);
    }

    private static function noGroup(): ResourceGroupsException
    {
        return AwsMocks::error(ResourceGroupsException::class, 'GetGroupQuery', 'NotFoundException', 404);
    }

    public function test_sync_group_creates_the_resource_group_of_the_environment(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(self::noGroup(), new Result(['Group' => ['GroupArn' => 'arn:aws:resource-groups:us-east-1:111111111111:group/arco-shop-production']]));
        $steps = [];

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->syncGroup($this->config(), function (string $key, array $params) use (&$steps): void { $steps[] = [$key, $params]; });

        self::assertSame(['GetGroupQuery', 'CreateGroup'], $mocks->calls);
        self::assertSame([['deploy.group_ready', ['group' => 'arco-shop-production']]], $steps);
    }

    public function test_sync_group_prints_nothing_when_the_group_is_already_the_environments(): void
    {
        $query = '{"ResourceTypeFilters":["AWS::AllSupported"],"TagFilters":[{"Key":"arco:app","Values":["shop"]},{"Key":"arco:environment","Values":["production"]}]}';
        $mocks = new AwsMocks();
        $mocks->handler->append(new Result(['GroupQuery' => ['ResourceQuery' => ['Type' => 'TAG_FILTERS_1_0', 'Query' => $query]]]));
        $steps = [];

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->syncGroup($this->config(), function (string $key, array $params) use (&$steps): void { $steps[] = [$key, $params]; });

        self::assertSame(['GetGroupQuery'], $mocks->calls);
        self::assertSame([], $steps, 'deploy prints the change once: an unchanged group is not news');
    }

    /** @return iterable<string, array{0: \Throwable, 1: string, 2: array<string, string>}> */
    public static function groupFailures(): iterable
    {
        yield 'no resource-groups permission' => [
            AwsMocks::error(ResourceGroupsException::class, 'GetGroupQuery', 'ForbiddenException', 403, 'not authorized to perform: resource-groups:GetGroupQuery'),
            'deploy.group_denied', ['group' => 'arco-shop-production', 'error' => 'not authorized to perform: resource-groups:GetGroupQuery'],
        ];
        yield 'AccessDeniedException' => [
            AwsMocks::error(ResourceGroupsException::class, 'GetGroupQuery', 'AccessDeniedException', 400, 'denied'),
            'deploy.group_denied', ['group' => 'arco-shop-production', 'error' => 'denied'],
        ];
        yield 'another failure' => [
            AwsMocks::error(ResourceGroupsException::class, 'GetGroupQuery', 'BadRequestException', 400, 'oops'),
            'deploy.group_failed', ['group' => 'arco-shop-production', 'error' => 'oops'],
        ];
    }

    /**
     * @param array<string, string> $params
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('groupFailures')]
    public function test_a_group_that_cannot_be_ensured_is_a_note_never_a_failure(\Throwable $error, string $key, array $params): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append($error);
        $steps = [];

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->syncGroup($this->config(), function (string $key, array $params) use (&$steps): void { $steps[] = [$key, $params]; });

        self::assertSame([[$key, $params]], $steps);
        self::assertNotContains($key, \Multiapi\Arco\Cli\StepPrinter::WARNINGS, 'a note (bullet), not a warning block');
    }

    public function test_a_group_with_the_name_someone_else_made_is_left_alone_with_a_note(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['GroupQuery' => ['ResourceQuery' => ['Type' => 'TAG_FILTERS_1_0', 'Query' => '{"ResourceTypeFilters":["AWS::AllSupported"],"TagFilters":[{"Key":"team","Values":["x"]}]}']]]),
            new Result(['Group' => ['GroupArn' => 'arn:aws:resource-groups:us-east-1:111111111111:group/arco-shop-production']]),
            new Result(['Tags' => []]),
        );
        $steps = [];

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->syncGroup($this->config(), function (string $key, array $params) use (&$steps): void { $steps[] = [$key, $params]; });

        self::assertSame([['deploy.group_not_ours', ['group' => 'arco-shop-production']]], $steps);
        self::assertNotContains('UpdateGroupQuery', $mocks->calls);
    }

    public function test_remove_deletes_the_resource_group_after_everything_else(): void
    {
        $query = '{"ResourceTypeFilters":["AWS::AllSupported"],"TagFilters":[{"Key":"arco:app","Values":["shop"]},{"Key":"arco:environment","Values":["production"]}]}';
        $mocks = new AwsMocks();
        $mocks->handler->append(...[
            new Result(['ResourceTagMappingList' => []]), self::noRule(),
            ...self::lambdaTags('shop-production-console'), ...self::webTags(), self::roleTags(), ...self::liveRevision(),
            AwsMocks::error(CloudFormationException::class, 'DescribeStacks', 'AccessDenied', 403), // domains stack
            self::noRule(),
            AwsMocks::error(CloudFormationException::class, 'DescribeStacks', 'AccessDenied', 403), // resources stack
            new Result([]),                                                                     // DeleteFunction (console)
            new Result([]),                                                                     // DeleteWebFunction
            AwsMocks::error(LambdaWebException::class, 'GetWebFunction', 'ResourceNotFoundException', 404),
            new Result([]), new Result([]),                                                     // DeleteRolePolicy, DeleteRole
            new Result(['GroupQuery' => ['ResourceQuery' => ['Type' => 'TAG_FILTERS_1_0', 'Query' => $query]]]),
            new Result([]),                                                                     // DeleteGroup
        ]);
        $steps = [];

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->remove($this->config(), function (string $key) use (&$steps): void { $steps[] = $key; });

        self::assertSame(['DeleteRole', 'GetGroupQuery', 'DeleteGroup'], array_slice($mocks->calls, -3), 'the group goes last');
        self::assertSame('remove.step_group', end($steps));
        self::assertCount(0, $mocks->handler);
    }

    public function test_remove_notes_a_group_it_may_not_delete_or_that_is_not_ours_and_still_succeeds(): void
    {
        foreach ([
            [AwsMocks::error(ResourceGroupsException::class, 'GetGroupQuery', 'ForbiddenException', 403, 'denied'), 'remove.group_denied'],
            [new Result(['GroupQuery' => ['ResourceQuery' => ['Type' => 'TAG_FILTERS_1_0', 'Query' => '{"TagFilters":[{"Key":"team","Values":["x"]}]}']]]), 'remove.group_not_ours'],
            // Not an AWS error at all (a dropped connection): still a note, never a failed remove.
            [new \RuntimeException('connection reset by peer'), 'remove.group_failed'],
        ] as [$group, $key]) {
            $mocks = new AwsMocks();
            $mocks->handler->append(...[
                new Result(['ResourceTagMappingList' => []]), self::noRule(),
                self::lambdaMissing('GetFunctionConfiguration'), self::noWeb(), self::noRole(),
                AwsMocks::error(CloudFormationException::class, 'DescribeStacks', 'AccessDenied', 403),
                self::noRule(),
                AwsMocks::error(CloudFormationException::class, 'DescribeStacks', 'AccessDenied', 403),
                self::lambdaMissing('DeleteFunction'),
                AwsMocks::error(LambdaWebException::class, 'DeleteWebFunction', 'ResourceNotFoundException', 404),
                AwsMocks::error(IamException::class, 'DeleteRolePolicy', 'NoSuchEntity', 404),
                AwsMocks::error(IamException::class, 'DeleteRole', 'NoSuchEntity', 404),
                $group,
            ]);
            $steps = [];

            (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->remove($this->config(), function (string $step) use (&$steps): void { $steps[] = $step; });

            self::assertSame($key, end($steps));
            self::assertNotContains('DeleteGroup', $mocks->calls);
        }
    }

    public function test_log_groups_get_both_tags_and_a_tag_refusal_is_a_plain_note_not_a_warning(): void
    {
        $config = ConfigResolver::resolve(App::make('shop')->console(false)->region('us-east-1')->logRetention(30)->environment('production')->toArray(), 'production');
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(),                                                                        // DescribeLogGroups: no web group yet
            new Result(),                                                                        // CreateLogGroup (web, tagged)
            new Result(),
            self::retention('/aws/lambda/web/shop-production.local', null),
            AwsMocks::error(\Aws\CloudWatchLogs\Exception\CloudWatchLogsException::class, 'CreateLogGroup', 'ResourceAlreadyExistsException', 400),
            new Result(['Account' => '111111111111']),                                           // GetCallerIdentity, for the group ARN
            AwsMocks::error(\Aws\CloudWatchLogs\Exception\CloudWatchLogsException::class, 'TagResource', 'AccessDeniedException', 400, 'not authorized to perform: logs:TagResource'),
            new Result(),
        );
        $steps = [];

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->applyLogRetention($config, function (string $key, array $params) use (&$steps): void { $steps[] = [$key, $params]; });

        self::assertSame(['DescribeLogGroups', 'CreateLogGroup', 'PutRetentionPolicy', 'DescribeLogGroups', 'CreateLogGroup', 'GetCallerIdentity', 'TagResource', 'PutRetentionPolicy'], $mocks->calls);
        self::assertSame(['arco:app' => 'shop', 'arco:environment' => 'production'], $mocks->params[1]['tags']);
        self::assertSame('arn:aws:logs:us-east-1:111111111111:log-group:/aws/lambda/web/shop-production.local', $mocks->params[6]['resourceArn']);
        self::assertSame([
            ['deploy.log_tags_not_added', ['groups' => '/aws/lambda/web/shop-production.local', 'error' => 'not authorized to perform: logs:TagResource']],
            ['deploy.log_retention', ['days' => 30, 'groups' => 'web']],
        ], $steps);
        self::assertNotContains('deploy.log_tags_not_added', \Multiapi\Arco\Cli\StepPrinter::WARNINGS, 'a bullet, not a yellow block: it would show on every deploy for profiles without logs:TagResource');
    }

    public function test_resources_lists_the_environments_tagged_resources(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(new Result(['ResourceTagMappingList' => [
            ['ResourceARN' => 'arn:aws:sqs:us-east-1:111111111111:shop-production-emails', 'Tags' => [['Key' => 'arco:app', 'Value' => 'shop'], ['Key' => 'arco:environment', 'Value' => 'production']]],
        ]]), self::noRole());

        $resources = (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->resources($this->config());

        self::assertSame([['type' => 'sqs', 'name' => 'shop-production-emails', 'arn' => 'arn:aws:sqs:us-east-1:111111111111:shop-production-emails', 'retained' => true, 'relations' => []]], $resources);
        self::assertSame(['GetResources', 'GetRole'], $mocks->calls);
    }

    public function test_tag_audit_a_first_deploy_tags_the_role_and_the_web_function(): void
    {
        // The console function's tags are asserted in test_first_deploy; the scheduler rule, stacks,
        // certificates, log groups and secrets in their own tests.
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['Account' => '111111111111']), new Result([]), new Result([]), new Result([]), new Result([]), new Result([]),
            AwsMocks::error(S3Exception::class, 'HeadObject', 'NotFound', 404), new Result(['VersionId' => 'v1']),
            self::noRole(), new Result(['Role' => ['Arn' => 'arn:aws:iam::111111111111:role/arco-shop-production']]), new Result([]),
            self::noWeb(), new Result(['revision' => ['revisionId' => 'rev-1']]), new Result(['state' => 'Active']),
        );

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->prepare($this->config(), $this->package(), null, []);

        $byOperation = [];
        foreach ($mocks->calls as $i => $call) {
            $byOperation[$call] = $mocks->params[$i];
        }
        self::assertSame([['Key' => 'arco:app', 'Value' => 'shop'], ['Key' => 'arco:environment', 'Value' => 'production'], ['Key' => 'arco:region', 'Value' => 'us-east-1']], $byOperation['CreateRole']['Tags']);
        self::assertSame(['arco:app' => 'shop', 'arco:environment' => 'production'], $byOperation['CreateWebFunction']['tags']);
    }

    public function test_a_role_of_another_region_is_refused_in_the_ownership_check_even_with_force(): void
    {
        $config = ConfigResolver::resolve(App::make('shop')->region('eu-west-1')->environment('production')->toArray(), 'production');
        foreach ([false, true] as $force) {
            $mocks = new AwsMocks();
            $mocks->handler->append(self::roleTags(self::tags() + ['arco:region' => 'us-east-1']));

            try {
                self::ownershipSteps(new AwsDeployer($mocks->clients(), AwsMocks::noSleep(), force: $force), $config);
                self::fail('expected a refusal');
            } catch (DeployException $e) {
                self::assertSame('deploy.region_changed', $e->key);
                self::assertSame(['resource' => 'arco-shop-production', 'current' => 'us-east-1', 'region' => 'eu-west-1'], $e->params);
            }
            foreach (['HeadBucket', 'CreateBucket', 'PutObject', 'PutRolePolicy', 'TagRole', 'CreateRole'] as $change) {
                self::assertNotContains($change, $mocks->calls);
            }
            self::assertSame(['GetRole'], $mocks->calls, 'only the role is read');
        }
    }

    public function test_deploy_resources_creates_a_generic_table(): void
    {
        $config = $this->resourcesConfig(fn (App $app) => $app->table('state', ttl: 'expires_at'));
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::stackMissing(),
            self::noTable(),                                     // the table name is free
            self::stackMissing(),                                // Stacks::deploy
            new Result([]),                                      // CreateStack
            self::resourcesStack('CREATE_COMPLETE'),
        );

        [$calls, $steps] = $this->deployResources($mocks, $config);

        self::assertSame(['DescribeStacks', 'DescribeTable', 'DescribeStacks', 'CreateStack', 'DescribeStacks'], $calls);
        self::assertSame('shop-production-state', $mocks->params[1]['TableName']);
        $template = json_decode((string) $mocks->params[3]['TemplateBody'], true);
        self::assertIsArray($template);
        self::assertSame(['AppTableState'], array_keys($template['Resources']));
        self::assertSame([
            ['deploy.step_resources', ['stack' => 'arco-shop-production-resources']],
            ['deploy.generic_table_created', ['table' => 'state', 'name' => 'shop-production-state']],
        ], $steps);
    }

    public function test_deploy_resources_reports_an_unchanged_or_changed_generic_table(): void
    {
        $before = $this->resourcesConfig(fn (App $app) => $app->table('state'));
        self::assertSame(['deploy.generic_table_updated'], $this->updateKeys($before, $before, ['AppTableState' => ['AWS::DynamoDB::Table', 'shop-production-state']]));
        self::assertSame(['deploy.generic_table_changed'], $this->updateKeys($this->resourcesConfig(fn (App $app) => $app->table('state', ttl: 'expires_at')), $before, ['AppTableState' => ['AWS::DynamoDB::Table', 'shop-production-state']]));
    }

    public function test_an_existing_generic_table_is_not_in_the_stack(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(self::stackMissing());

        [$calls, $steps] = $this->deployResources($mocks, $this->resourcesConfig(fn (App $app) => $app->table('state', existing: 'legacy-state')));

        self::assertSame(['DescribeStacks'], $calls, 'no table lookup, no stack');
        self::assertSame([], $steps);
    }

    public function test_deploy_resources_stops_before_any_change_when_a_generic_table_name_is_retained(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(self::stackMissing(), new Result(['Table' => ['TableName' => 'shop-production-state']]));

        try {
            $this->deployResources($mocks, $this->resourcesConfig(fn (App $app) => $app->table('state')));
            self::fail('expected deploy.generic_table_retained');
        } catch (DeployException $e) {
            self::assertSame('deploy.generic_table_retained', $e->key);
            self::assertSame(['kind' => 'table', 'name' => 'state', 'table' => 'shop-production-state', 'existing' => "table('state', existing: 'shop-production-state')"], $e->params);
            $translator = new \Multiapi\Arco\Cli\Translator('en', \Multiapi\Arco\Arco::packageRoot() . '/lang');
            self::assertStringStartsWith('Table "state" cannot be created: its DynamoDB table shop-production-state already exists', $translator->trans($e->key, $e->params));
            $es = new \Multiapi\Arco\Cli\Translator('es', \Multiapi\Arco\Arco::packageRoot() . '/lang');
            self::assertStringStartsWith('No se puede crear la tabla "state": su tabla DynamoDB shop-production-state', $es->trans($e->key, $e->params));
        }
        self::assertSame(['DescribeStacks', 'DescribeTable'], $mocks->calls, 'no CreateStack or UpdateStack');
    }

    public function test_a_generic_table_removed_from_arco_php_is_kept_and_reported(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::resourcesStack(),
            self::ownedResources([
                'AppTableState' => ['AWS::DynamoDB::Table', 'shop-production-state'],
                'AppTableEvents' => ['AWS::DynamoDB::Table', 'shop-production-events'],
            ]),
            self::runningTemplate($this->resourcesConfig(fn (App $app) => $app->table('state')->table('events'))),
            self::resourcesStack(),                              // Stacks::deploy
            new Result([]),                                      // UpdateStack
            self::resourcesStack(),
            new Result(['Table' => ['ItemCount' => 7]]),         // DescribeTable of the removed table
        );

        [$calls, $steps] = $this->deployResources($mocks, $this->resourcesConfig(fn (App $app) => $app->table('state')));

        self::assertSame(['DescribeStacks', 'DescribeStackResources', 'GetTemplate', 'DescribeStacks', 'UpdateStack', 'DescribeStacks', 'DescribeTable'], $calls);
        $template = json_decode((string) $mocks->params[4]['TemplateBody'], true);
        self::assertSame(['AppTableState'], array_keys($template['Resources']), 'the removed table leaves the template; Retain keeps it in AWS');
        self::assertSame('shop-production-events', $mocks->params[6]['TableName']);
        self::assertSame([
            ['deploy.step_resources', ['stack' => 'arco-shop-production-resources']],
            ['deploy.generic_table_updated', ['table' => 'state', 'name' => 'shop-production-state']],
            ['deploy.table_kept', ['table' => 'shop-production-events', 'items' => 7]],
        ], $steps);
    }

    public function test_the_last_generic_table_removed_deletes_the_stack_and_keeps_the_table(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::resourcesStack(),
            self::ownedResources(['AppTableState' => ['AWS::DynamoDB::Table', 'shop-production-state']]),
            self::resourcesStack(),                              // Stacks::delete
            new Result([]),                                      // DeleteStack
            self::stackMissing(),
            new Result(['Table' => ['ItemCount' => 0]]),
        );

        [$calls, $steps] = $this->deployResources($mocks, $this->resourcesConfig(fn (App $app) => $app));

        self::assertSame(['DescribeStacks', 'DescribeStackResources', 'DescribeStacks', 'DeleteStack', 'DescribeStacks', 'DescribeTable'], $calls);
        self::assertSame([
            ['deploy.step_resources', ['stack' => 'arco-shop-production-resources']],
            ['deploy.table_kept', ['table' => 'shop-production-state', 'items' => 0]],
        ], $steps);
    }

    public function test_a_created_generic_table_switched_to_existing_itself_is_not_offered_for_deletion(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::resourcesStack(),
            self::ownedResources(['AppTableState' => ['AWS::DynamoDB::Table', 'shop-production-state']]),
            self::resourcesStack(),                              // Stacks::delete
            new Result([]),                                      // DeleteStack
            self::stackMissing(),
        );

        [$calls, $steps] = $this->deployResources($mocks, $this->resourcesConfig(fn (App $app) => $app->table('state', existing: 'shop-production-state')));

        self::assertSame(['DescribeStacks', 'DescribeStackResources', 'DescribeStacks', 'DeleteStack', 'DescribeStacks'], $calls);
        self::assertSame([
            ['deploy.step_resources', ['stack' => 'arco-shop-production-resources']],
            ['deploy.generic_table_now_existing', ['table' => 'shop-production-state', 'as' => 'state']],
        ], $steps);
    }

    public function test_deploy_resources_stops_before_any_change_when_a_generic_table_would_be_replaced(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::resourcesStack(),
            self::ownedResources(['AppTableState' => ['AWS::DynamoDB::Table', 'shop-staging-state']]),
        );

        try {
            $this->deployResources($mocks, $this->resourcesConfig(fn (App $app) => $app->table('state')));
            self::fail('expected deploy.resource_replaced');
        } catch (DeployException $e) {
            self::assertSame('deploy.resource_replaced', $e->key);
            self::assertSame(['resource' => 'table:state', 'from' => 'shop-staging-state', 'to' => 'shop-production-state'], $e->params);
        }
        self::assertSame(['DescribeStacks', 'DescribeStackResources'], $mocks->calls);
    }

    public function test_prepare_lets_the_role_use_every_generic_table_and_its_indexes(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['Account' => '111111111111']),
            new Result([]),                                   // HeadBucket
            new Result([]), new Result([]), new Result([]), new Result([]),
            new Result(['VersionId' => 'v1']),                // HeadObject (web zip)
            new Result(['Role' => ['Arn' => 'arn:role', 'Tags' => [['Key' => 'arco:region', 'Value' => 'us-east-1']]]]), // GetRole
            new Result([]),                                   // PutRolePolicy
            new Result(['functionName' => 'shop-production', 'state' => 'Active']),
            new Result(['domainName' => 'abc.on.aws', 'state' => 'Active', 'revisionWeights' => [['revisionId' => 'rev-1', 'weight' => 100]]]),
            new Result(['revisionId' => 'rev-2', 'state' => 'Pending']),
            new Result(['state' => 'Active', 'stateReason' => 'ok']),
        );
        $config = $this->resourcesConfig(fn (App $app) => $app->cache()->table('state', ttl: 'expires_at')->table('shared', existing: 'Shared'));

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->prepare($config, $this->package(), null, [], fn () => null);

        $policy = json_decode(self::byOperation($mocks)['PutRolePolicy'][0]['PolicyDocument'], true);
        $bySid = array_column($policy['Statement'], null, 'Sid');
        self::assertSame(['arn:aws:dynamodb:us-east-1:111111111111:table/shop-production-cache'], $bySid['ArcoTables']['Resource'], "Arco's own tables keep their statement");
        self::assertSame([
            'arn:aws:dynamodb:us-east-1:111111111111:table/shop-production-state',
            'arn:aws:dynamodb:us-east-1:111111111111:table/shop-production-state/index/*',
            'arn:aws:dynamodb:us-east-1:111111111111:table/Shared',
            'arn:aws:dynamodb:us-east-1:111111111111:table/Shared/index/*',
        ], $bySid['ArcoAppTables']['Resource']);
    }


    private const CONNECTOR_VERSION = 'arn:aws:lambda:us-east-1:111111111111:network-connector:shop-production:3';

    private function vpcConfig(bool $console = false, ?string $role = null): AppConfig
    {
        $app = App::make('shop')->region('us-east-1');
        if ($console) {
            $app = $app->console();
        }
        if ($role !== null) {
            $app = $app->role($role);
        }
        return ConfigResolver::resolve($app->environment('production', fn (Environment $e) => $e->vpc(subnets: ['subnet-a', 'subnet-b'], securityGroups: ['sg-1']))->toArray(), 'production');
    }

    /** @return list<Result> the reads and writes of NetworkConnectors::ensure() for an unchanged connector */
    private static function connectorReady(): array
    {
        return [
            new Result(['Arn' => 'arn:connector', 'Name' => 'shop-production', 'OperatorRole' => 'arn:aws:iam::111111111111:role/arco-shop-production-network', 'State' => 'ACTIVE',
                'LatestVersionArn' => self::CONNECTOR_VERSION, 'LatestVersion' => 3,
                'Configuration' => ['VpcEgressConfiguration' => ['SubnetIds' => ['subnet-b', 'subnet-a'], 'SecurityGroupIds' => ['sg-1'], 'NetworkProtocol' => 'IPv4', 'AssociatedComputeResourceTypes' => ['WebFunction']]]]),
            new Result(['Role' => ['Arn' => 'arn:aws:iam::111111111111:role/arco-shop-production-network']]), // GetRole (operator)
            new Result([]),                                                                                  // PutRolePolicy (operator)
        ];
    }

    public function test_vpc_deploy_order(): void
    {
        $ready = fn () => new Result(['State' => 'Active', 'LastUpdateStatus' => 'Successful']);
        $mocks = new AwsMocks();
        $mocks->handler->append(...[
            ...self::untilTheRole(),
            new Result(['Role' => ['Arn' => 'arn:role', 'Tags' => [['Key' => 'arco:region', 'Value' => 'us-east-1']]]]), // GetRole
            new Result([]),                                   // PutRolePolicy
            ...self::connectorReady(),
            new Result(['functionName' => 'shop-production', 'state' => 'Active']),
            new Result(['domainName' => 'abc.on.aws', 'state' => 'Active', 'revisionWeights' => [['revisionId' => 'rev-1', 'weight' => 100]]]),
            new Result(['revisionId' => 'rev-2', 'state' => 'Pending']),
            new Result(['state' => 'Active', 'stateReason' => 'ok']),
            new Result(['VersionId' => 'cv1']),               // HeadObject (console zip)
            new Result(['ContentLength' => 3, 'VersionId' => 'bv1']),
            new Result([]),                                   // CopyObject
            new Result(['FunctionVersion' => '6']),           // GetAlias
            $ready(), $ready(), new Result([]), $ready(), new Result([]), $ready(), new Result(['Version' => '7']),
        ]);
        $steps = [];
        $deployer = new AwsDeployer($mocks->clients(), AwsMocks::noSleep());

        $deployer->prepare($this->vpcConfig(console: true), $this->package(), $this->console(), [], function (string $key) use (&$steps): void { $steps[] = $key; });

        self::assertSame([
            ...self::UNTIL_THE_ROLE, 'GetRole', 'PutRolePolicy',
            'GetNetworkConnector', 'GetRole', 'PutRolePolicy',
            'GetWebFunction', 'GetWebFunctionEndpoint', 'CreateWebFunctionRevision', 'GetWebFunctionRevision',
            'HeadObject', 'HeadObject', 'CopyObject',
            'GetAlias', 'GetFunctionConfiguration', 'GetFunctionConfiguration', 'UpdateFunctionCode', 'GetFunctionConfiguration', 'UpdateFunctionConfiguration', 'GetFunctionConfiguration', 'PublishVersion',
        ], $mocks->calls, 'role, connector, revision, then the console');
        $revision = array_search('CreateWebFunctionRevision', $mocks->calls, true);
        self::assertSame(self::CONNECTOR_VERSION, $mocks->params[$revision]['serviceConfig']['egressNetworkConnectorArn']);
        $update = array_search('UpdateFunctionConfiguration', $mocks->calls, true);
        self::assertSame(['SubnetIds' => ['subnet-a', 'subnet-b'], 'SecurityGroupIds' => ['sg-1']], $mocks->params[$update]['VpcConfig']);
        $policy = json_decode($mocks->params[array_search('PutRolePolicy', $mocks->calls, true)]['PolicyDocument'], true);
        $vpc = array_values(array_filter($policy['Statement'], fn (array $s) => ($s['Sid'] ?? null) === 'ArcoVpcAccess'));
        self::assertCount(1, $vpc, 'the execution role may manage the console and workers network interfaces');
        self::assertContains('ec2:CreateNetworkInterface', $vpc[0]['Action']);
        self::assertSame(['deploy.step_bucket', 'deploy.step_upload', 'deploy.step_role', 'deploy.step_network', 'deploy.step_revision', 'deploy.step_console'], $steps);
    }

    public function test_failed_connector_creates_no_revision(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(...[
            ...self::untilTheRole(),
            new Result(['Role' => ['Arn' => 'arn:role', 'Tags' => [['Key' => 'arco:region', 'Value' => 'us-east-1']]]]),
            new Result([]),
            self::connectorGone(),
            new Result(['Role' => ['Arn' => 'arn:aws:iam::111111111111:role/arco-shop-production-network']]),
            new Result([]),
            new Result(['Arn' => 'arn:connector', 'Name' => 'shop-production', 'VersionArn' => self::CONNECTOR_VERSION, 'State' => 'PENDING']), // CreateNetworkConnector
            new Result(['Arn' => 'arn:connector', 'Name' => 'shop-production', 'OperatorRole' => 'arn:aws:iam::111111111111:role/arco-shop-production-network', 'State' => 'FAILED',
                'StateReason' => 'bad', 'StateReasonCode' => 'InvalidSubnet', 'Configuration' => ['VpcEgressConfiguration' => ['SubnetIds' => ['subnet-b', 'subnet-a'], 'SecurityGroupIds' => ['sg-1']]]]),
        ]);
        $deployer = new AwsDeployer($mocks->clients(), AwsMocks::noSleep());

        try {
            $deployer->prepare($this->vpcConfig(), $this->package(), null, []);
            self::fail('the deploy should stop on the failed connector');
        } catch (DeployException $e) {
            self::assertSame('deploy.network_failed', $e->key);
        }

        self::assertNotContains('CreateWebFunctionRevision', $mocks->calls);
        self::assertNotContains('CreateWebFunction', $mocks->calls);
        self::assertNotContains('UpdateWebFunctionEndpoint', $mocks->calls);
    }

    public function test_environment_without_vpc_makes_no_network_calls(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(...[
            ...self::untilTheRole(),
            new Result(['Role' => ['Arn' => 'arn:role', 'Tags' => [['Key' => 'arco:region', 'Value' => 'us-east-1']]]]),
            new Result([]),
            new Result(['functionName' => 'shop-production', 'state' => 'Active']),
            new Result(['domainName' => 'abc.on.aws', 'state' => 'Active', 'revisionWeights' => [['revisionId' => 'rev-1', 'weight' => 100]]]),
            new Result(['revisionId' => 'rev-2', 'state' => 'Pending']),
            new Result(['state' => 'Active', 'stateReason' => 'ok']),
        ]);
        $steps = [];
        $deployer = new AwsDeployer($mocks->clients(), AwsMocks::noSleep());

        $deployer->prepare($this->config(), $this->package(), null, [], function (string $key) use (&$steps): void { $steps[] = $key; });

        self::assertSame([...self::UNTIL_THE_ROLE, 'GetRole', 'PutRolePolicy', 'GetWebFunction', 'GetWebFunctionEndpoint', 'CreateWebFunctionRevision', 'GetWebFunctionRevision'], $mocks->calls);
        $revision = array_search('CreateWebFunctionRevision', $mocks->calls, true);
        self::assertArrayNotHasKey('egressNetworkConnectorArn', $mocks->params[$revision]['serviceConfig']);
        self::assertStringNotContainsString('ArcoVpcAccess', (string) $mocks->params[array_search('PutRolePolicy', $mocks->calls, true)]['PolicyDocument']);
        self::assertSame(['deploy.step_bucket', 'deploy.step_upload', 'deploy.step_role', 'deploy.step_revision'], $steps);
    }

    public function test_emulator_skips_vpc_with_note(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(...[
            ...self::untilTheRole(),
            new Result(['Role' => ['Arn' => 'arn:role', 'Tags' => [['Key' => 'arco:region', 'Value' => 'us-east-1']]]]),
            new Result([]),
            new Result(['functionName' => 'shop-production', 'state' => 'Active']),
            new Result(['domainName' => 'abc.on.aws', 'state' => 'Active', 'revisionWeights' => [['revisionId' => 'rev-1', 'weight' => 100]]]),
            new Result(['revisionId' => 'rev-2', 'state' => 'Pending']),
            new Result(['state' => 'Active', 'stateReason' => 'ok']),
        ]);
        $steps = [];

        self::behindEmulator(function () use ($mocks, &$steps): void {
            $deployer = new AwsDeployer($mocks->clients(), AwsMocks::noSleep());
            $deployer->prepare($this->vpcConfig(), $this->package(), null, [], function (string $key) use (&$steps): void { $steps[] = $key; });
        });

        self::assertNotContains('GetNetworkConnector', $mocks->calls);
        self::assertNotContains('CreateNetworkConnector', $mocks->calls);
        self::assertContains('deploy.vpc_unsupported_emulator', $steps);
        self::assertNotContains('deploy.step_network', $steps);
        $revision = array_search('CreateWebFunctionRevision', $mocks->calls, true);
        self::assertArrayNotHasKey('egressNetworkConnectorArn', $mocks->params[$revision]['serviceConfig']);
        self::assertStringNotContainsString('ArcoVpcAccess', (string) $mocks->params[array_search('PutRolePolicy', $mocks->calls, true)]['PolicyDocument']);
        self::assertSame('note', \Multiapi\Arco\Cli\StepPrinter::kind('deploy.vpc_unsupported_emulator', []));
    }

    public function test_with_a_custom_role_the_operator_role_is_still_created_and_the_execution_role_untouched(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(...[
            ...self::untilTheRole(),
            ...self::connectorReady(),
            new Result(['functionName' => 'shop-production', 'state' => 'Active']),
            new Result(['domainName' => 'abc.on.aws', 'state' => 'Active', 'revisionWeights' => [['revisionId' => 'rev-1', 'weight' => 100]]]),
            new Result(['revisionId' => 'rev-2', 'state' => 'Pending']),
            new Result(['state' => 'Active', 'stateReason' => 'ok']),
        ]);
        $deployer = new AwsDeployer($mocks->clients(), AwsMocks::noSleep());

        $deployer->prepare($this->vpcConfig(role: 'arn:aws:iam::111111111111:role/mine'), $this->package(), null, []);

        self::assertSame([...self::UNTIL_THE_ROLE, 'GetNetworkConnector', 'GetRole', 'PutRolePolicy', 'GetWebFunction', 'GetWebFunctionEndpoint', 'CreateWebFunctionRevision', 'GetWebFunctionRevision'], $mocks->calls);
        $role = array_search('PutRolePolicy', $mocks->calls, true);
        self::assertSame('arco-shop-production-network', $mocks->params[$role]['RoleName'], 'only the operator role is written');
        self::assertSame('arn:aws:iam::111111111111:role/mine', $mocks->params[array_search('CreateWebFunctionRevision', $mocks->calls, true)]['serviceConfig']['executionRoleArn']);
    }

    private const OPERATOR_ROLE = 'arn:aws:iam::111111111111:role/arco-shop-production-network';

    /**
     * Answers every call by its operation name, so a test lists what AWS returns instead of the exact order of
     * the calls (it asserts the order it cares about on $mocks->calls). A list answers its calls in turn and
     * repeats its last entry; anything else is answered as is. An operation with no answer is refused like a
     * missing permission, so every step that warns on it warns.
     *
     * @param array<string, mixed> $answers operation => Result|\Exception or a list of them
     */
    private static function answer(AwsMocks $mocks, array $answers): void
    {
        $answers += ['GetCallerIdentity' => new Result(['Account' => '111111111111'])];
        $dispatch = static function (\Aws\CommandInterface $command) use (&$answers): mixed {
            $name = $command->getName();
            if (! array_key_exists($name, $answers)) {
                return AwsMocks::error(LambdaException::class, $name, 'AccessDeniedException', 403, "no answer for $name");
            }
            if (! is_array($answers[$name])) {
                return $answers[$name];
            }
            return count($answers[$name]) > 1 ? array_shift($answers[$name]) : $answers[$name][0];
        };
        for ($i = 0; $i < 300; $i++) {
            $mocks->handler->append($dispatch);
        }
    }

    /** @param array<string, mixed> $extra */
    private static function connector(int $version = 3, string $role = self::OPERATOR_ROLE, array $extra = []): Result
    {
        return new Result([
            'Arn' => 'arn:aws:lambda:us-east-1:111111111111:network-connector:shop-production', 'Name' => 'shop-production', 'Id' => 'nc-0abc',
            'OperatorRole' => $role, 'State' => 'ACTIVE',
            'LatestVersionArn' => "arn:aws:lambda:us-east-1:111111111111:network-connector:shop-production:$version", 'LatestVersion' => $version,
            'Configuration' => ['VpcEgressConfiguration' => ['SubnetIds' => ['subnet-a', 'subnet-b'], 'SecurityGroupIds' => ['sg-1'], 'NetworkProtocol' => 'IPv4', 'AssociatedComputeResourceTypes' => ['WebFunction']]],
            ...$extra,
        ]);
    }

    private static function connectorGone(): \Aws\LambdaCore\Exception\LambdaCoreException
    {
        return AwsMocks::error(\Aws\LambdaCore\Exception\LambdaCoreException::class, 'GetNetworkConnector', 'ResourceNotFoundException', 404);
    }

    /**
     * What a deploy of an existing environment reads and writes, for answer(): the ownership reads (all owned),
     * the bucket and upload, the execution role, the web function with its live revision rev-1 (pinning
     * $liveConnector), and the console and workers, which exist.
     *
     * @return array<string, mixed>
     */
    private static function existingEnvironment(?string $liveConnector): array
    {
        $function = new Result(['FunctionName' => 'shop-production-console', 'FunctionArn' => 'arn:aws:lambda:us-east-1:111111111111:function:shop-production-console',
            'Description' => 'Arco console function', 'State' => 'Active', 'LastUpdateStatus' => 'Successful']);
        return [
            'GetCallerIdentity' => new Result(['Account' => '111111111111']),
            'HeadBucket' => new Result([]), 'PutPublicAccessBlock' => new Result([]), 'PutBucketPolicy' => new Result([]),
            'PutBucketVersioning' => new Result([]), 'PutBucketLifecycleConfiguration' => new Result([]),
            'HeadObject' => new Result(['VersionId' => 'v1', 'ContentLength' => 3]), 'CopyObject' => new Result([]),
            'GetRole' => [self::roleTags(), new Result(['Role' => ['Arn' => 'arn:role', 'Tags' => [['Key' => 'arco:region', 'Value' => 'us-east-1'], ...self::tagList(self::tags())]]])],
            'PutRolePolicy' => new Result([]),
            // lambda (Tags) and lambda-web (tags) share the operation name
            'ListTags' => new Result(['Tags' => self::tags(), 'tags' => self::tags()]),
            'GetWebFunction' => new Result(['functionName' => 'shop-production', 'functionArn' => 'arn:aws:lambda:us-east-1:111111111111:web-function:shop-production', 'state' => 'Active']),
            'GetWebFunctionEndpoint' => new Result(['domainName' => 'abc.on.aws', 'state' => 'Active', 'revisionWeights' => [['revisionId' => 'rev-1', 'weight' => 100]]]),
            'GetWebFunctionRevision' => new Result(['state' => 'Active', 'serviceConfig' => $liveConnector === null ? ['executionRoleArn' => 'arn:role'] : ['executionRoleArn' => 'arn:role', 'egressNetworkConnectorArn' => $liveConnector]]),
            'CreateWebFunctionRevision' => new Result(['revisionId' => 'rev-2', 'state' => 'Pending']),
            'GetFunctionConfiguration' => $function,
            'DescribeRule' => self::noRule(),
            'DescribeStacks' => new Result(['Stacks' => [['StackName' => 'arco-shop-production-resources', 'StackStatus' => 'CREATE_COMPLETE', 'Tags' => self::tagList(self::tags())]]]),
            'GetAlias' => new Result(['FunctionVersion' => '6']),
            'UpdateFunctionCode' => new Result([]), 'UpdateFunctionConfiguration' => new Result([]), 'PublishVersion' => new Result(['Version' => '7']),
        ];
    }

    private function leftoverConfig(): AppConfig
    {
        return ConfigResolver::resolve(App::make('shop')->region('us-east-1')->console()->queue('emails')->environment('production', fn (Environment $e) => $e->account('111111111111'))->toArray(), 'production');
    }

    /** @return list<string> the calls among $operations, in order */
    private static function only(AwsMocks $mocks, string ...$operations): array
    {
        return array_values(array_filter($mocks->calls, fn (string $call) => in_array($call, $operations, true)));
    }

    /**
     * vpc() left arco.php while the live revision still pins the connector. The deploy takes the new revision,
     * the console and the workers out of the VPC and keeps the ENI statements (an automatic rollback goes back
     * to VPC versions); only prune(), which the pipeline reaches after a verified deploy (DeployPipelineTest::
     * test_a_failed_verification_never_removes_the_network_connector), deletes the connector, then its role.
     */
    public function test_leftover_connector_is_removed_only_after_verification(): void
    {
        $mocks = new AwsMocks();
        self::answer($mocks, [...self::existingEnvironment(self::CONNECTOR_VERSION), 'GetNetworkConnector' => self::connector()]);
        $deployer = new AwsDeployer($mocks->clients(), AwsMocks::noSleep());
        $steps = [];

        $deployer->checkOwnership($this->leftoverConfig(), fn () => null);
        $prepared = $deployer->prepare($this->leftoverConfig(), $this->package(), $this->console(), [], function (string $key) use (&$steps): void { $steps[] = $key; });

        self::assertTrue($prepared->leftoverConnector);
        self::assertTrue($prepared->leftVpc, 'prune() retires the console and worker versions still in the VPC');
        $by = self::byOperation($mocks);
        self::assertArrayNotHasKey('egressNetworkConnectorArn', $by['CreateWebFunctionRevision'][0]['serviceConfig']);
        self::assertSame(['shop-production-console', 'shop-production-queue-emails'], array_column($by['UpdateFunctionConfiguration'], 'FunctionName'));
        foreach ($by['UpdateFunctionConfiguration'] as $update) {
            self::assertSame(['SubnetIds' => [], 'SecurityGroupIds' => []], $update['VpcConfig'], 'the console and the workers leave the VPC');
        }
        self::assertStringContainsString('ArcoVpcAccess', (string) $by['PutRolePolicy'][0]['PolicyDocument'], 'a rollback to a VPC console or worker version still works');
        self::assertSame([], self::only($mocks, 'DeleteNetworkConnector', 'DeleteRole', 'DeleteRolePolicy', 'CreateNetworkConnector', 'UpdateNetworkConnector'));
        self::assertNotContains('deploy.step_network', $steps);

        $mocks = new AwsMocks();
        self::answer($mocks, ['GetNetworkConnector' => [self::connector(), self::connectorGone()], 'DeleteNetworkConnector' => new Result(['State' => 'DELETING']),
            'DeleteRolePolicy' => new Result([]), 'DeleteRole' => new Result([])]);
        $steps = [];

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->prune($this->leftoverConfig(), $prepared, function (string $key, array $params = []) use (&$steps): void { $steps[] = [$key, $params]; });

        self::assertSame(['GetNetworkConnector', 'DeleteNetworkConnector', 'GetNetworkConnector', 'DeleteRolePolicy', 'DeleteRole'], self::only($mocks, 'GetNetworkConnector', 'DeleteNetworkConnector', 'DeleteRolePolicy', 'DeleteRole'));
        $by = self::byOperation($mocks);
        self::assertSame('shop-production', $by['DeleteNetworkConnector'][0]['Identifier']);
        self::assertSame('arco-shop-production-network', $by['DeleteRole'][0]['RoleName'], 'the operator role, never the execution role');
        self::assertContains(['deploy.network_removed', ['connector' => 'shop-production']], $steps);
    }

    public function test_a_live_revision_pinning_the_connector_by_its_id_is_a_leftover_too(): void
    {
        $mocks = new AwsMocks();
        self::answer($mocks, [...self::existingEnvironment('arn:aws:lambda:us-east-1:111111111111:network-connector:nc-0abc:3'), 'GetNetworkConnector' => self::connector()]);
        $deployer = new AwsDeployer($mocks->clients(), AwsMocks::noSleep());

        $deployer->checkOwnership($this->leftoverConfig(), fn () => null);
        $prepared = $deployer->prepare($this->leftoverConfig(), $this->package(), null, []);

        self::assertTrue($prepared->leftoverConnector, 'AWS names the connector by its id in a version ARN');
    }

    public function test_a_live_revision_without_a_connector_makes_no_network_connector_call(): void
    {
        $mocks = new AwsMocks();
        self::answer($mocks, self::existingEnvironment(null));
        $deployer = new AwsDeployer($mocks->clients(), AwsMocks::noSleep());

        $deployer->checkOwnership($this->leftoverConfig(), fn () => null);
        $prepared = $deployer->prepare($this->leftoverConfig(), $this->package(), $this->console(), []);
        $deployer->prune($this->leftoverConfig(), $prepared);

        self::assertFalse($prepared->leftoverConnector);
        self::assertSame([], $prepared->keepConnectorVersions);
        self::assertSame([], array_values(array_filter($mocks->calls, fn (string $call) => str_contains($call, 'NetworkConnector'))));
        self::assertStringNotContainsString('ArcoVpcAccess', (string) self::byOperation($mocks)['PutRolePolicy'][0]['PolicyDocument']);
        self::assertArrayNotHasKey('VpcConfig', self::byOperation($mocks)['UpdateFunctionConfiguration'][0], 'a function that never was in a VPC gets no VpcConfig');
    }

    public function test_a_foreign_connector_pinned_by_the_live_revision_is_left_alone_with_a_warning(): void
    {
        $mocks = new AwsMocks();
        self::answer($mocks, [...self::existingEnvironment(self::CONNECTOR_VERSION), 'GetNetworkConnector' => self::connector(role: 'arn:aws:iam::111111111111:role/someone-else')]);
        $deployer = new AwsDeployer($mocks->clients(), AwsMocks::noSleep());
        $steps = [];

        $deployer->checkOwnership($this->leftoverConfig(), fn () => null);
        $prepared = $deployer->prepare($this->leftoverConfig(), $this->package(), null, [], function (string $key) use (&$steps): void { $steps[] = $key; });
        $deployer->prune($this->leftoverConfig(), $prepared);

        self::assertFalse($prepared->leftoverConnector);
        self::assertContains('deploy.network_foreign_kept', $steps);
        self::assertContains('deploy.network_foreign_kept', \Multiapi\Arco\Cli\StepPrinter::WARNINGS);
        self::assertNotContains('DeleteNetworkConnector', $mocks->calls);
    }

    public function test_a_failed_leftover_cleanup_is_a_warning_with_the_manual_commands(): void
    {
        $mocks = new AwsMocks();
        self::answer($mocks, ['GetNetworkConnector' => self::connector(),
            'DeleteNetworkConnector' => AwsMocks::error(\Aws\LambdaCore\Exception\LambdaCoreException::class, 'DeleteNetworkConnector', 'AccessDeniedException', 403, 'not allowed')]);
        $steps = [];
        $prepared = new PreparedDeploy('rev-2', 'rev-1', 'arco-111111111111-us-east-1', 'shop/production/a.zip', null, null, leftoverConnector: true);

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->prune($this->leftoverConfig(), $prepared, function (string $key, array $params = []) use (&$steps): void { $steps[] = [$key, $params]; });

        $failed = array_values(array_filter($steps, fn (array $s) => $s[0] === 'deploy.network_cleanup_failed'));
        self::assertCount(1, $failed);
        self::assertSame(['connector' => 'shop-production', 'role' => 'arco-shop-production-network', 'region' => 'us-east-1', 'error' => 'AccessDeniedException not allowed'], $failed[0][1]);
        self::assertContains('deploy.network_cleanup_failed', \Multiapi\Arco\Cli\StepPrinter::WARNINGS);
        self::assertNotContains('DeleteRole', $mocks->calls, 'the role stays while the connector uses it');
        foreach (['en', 'es'] as $lang) {
            $text = (new \Multiapi\Arco\Cli\Translator($lang, \Multiapi\Arco\Arco::packageRoot() . '/lang'))->trans('deploy.network_cleanup_failed', $failed[0][1]);
            self::assertStringContainsString('aws lambda-core delete-network-connector --identifier shop-production --region us-east-1', $text);
            self::assertStringContainsString('aws iam delete-role --role-name arco-shop-production-network', $text);
        }
    }

    public function test_prune_keeps_live_previous_and_new_versions(): void
    {
        $base = 'arn:aws:lambda:us-east-1:111111111111:network-connector:shop-production';
        $mocks = new AwsMocks();
        self::answer($mocks, [
            ...self::existingEnvironment("$base:2"),
            'GetRole' => [self::roleTags(), self::operatorRole(), new Result(['Role' => ['Arn' => 'arn:role', 'Tags' => [['Key' => 'arco:region', 'Value' => 'us-east-1'], ...self::tagList(self::tags())]]]), self::operatorRole()],
            'GetNetworkConnector' => self::connector(3),
        ]);
        $deployer = new AwsDeployer($mocks->clients(), AwsMocks::noSleep());

        $deployer->checkOwnership($this->vpcConfig(), fn () => null);
        $prepared = $deployer->prepare($this->vpcConfig(), $this->package(), null, []);

        self::assertFalse($prepared->leftoverConnector);
        self::assertSame(["$base:2", "$base:3"], $prepared->keepConnectorVersions, 'the live revision (the rollback target) and the new one');

        $mocks = new AwsMocks();
        self::answer($mocks, [
            'ListNetworkConnectorVersions' => new Result(['NetworkConnectorVersions' => [
                ['VersionArn' => "$base:1", 'Version' => 1], ['VersionArn' => "$base:2", 'Version' => 2], ['VersionArn' => "$base:3", 'Version' => 3],
            ]]),
            'DeleteNetworkConnector' => new Result([]),
        ]);

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->prune($this->vpcConfig(), $prepared);

        self::assertSame(["$base:1"], array_column(self::byOperation($mocks)['DeleteNetworkConnector'], 'Identifier'));
        self::assertNotContains('DeleteRole', $mocks->calls);
    }

    public function test_emulator_reads_no_live_connector_and_prunes_no_versions(): void
    {
        $mocks = new AwsMocks();
        self::answer($mocks, self::existingEnvironment(self::CONNECTOR_VERSION));

        self::behindEmulator(function () use ($mocks): void {
            $deployer = new AwsDeployer($mocks->clients(), AwsMocks::noSleep());
            $deployer->checkOwnership($this->vpcConfig(), fn () => null);
            $prepared = $deployer->prepare($this->vpcConfig(), $this->package(), null, []);
            $deployer->prune($this->vpcConfig(), $prepared);
            self::assertFalse($prepared->leftoverConnector);
        });

        self::assertSame([], array_values(array_filter($mocks->calls, fn (string $call) => str_contains($call, 'NetworkConnector'))));
    }

    /** @return array<string, mixed> what a remove of shop/production reads and deletes, for answer() */
    private static function removal(?string $liveConnector): array
    {
        return [
            'GetResources' => new Result(['ResourceTagMappingList' => []]),
            'DescribeRule' => self::noRule(),
            'GetFunctionConfiguration' => self::lambdaMissing('GetFunctionConfiguration'),
            'GetWebFunction' => [new Result(['functionName' => 'shop-production', 'functionArn' => 'arn:aws:lambda:us-east-1:111111111111:web-function:shop-production', 'state' => 'Active']),
                AwsMocks::error(LambdaWebException::class, 'GetWebFunction', 'ResourceNotFoundException', 404)],
            'ListTags' => new Result(['tags' => self::tags()]),
            'GetRole' => self::roleTags(),
            'GetWebFunctionEndpoint' => new Result(['domainName' => 'abc.on.aws', 'state' => 'Active', 'revisionWeights' => [['revisionId' => 'rev-1', 'weight' => 100]]]),
            'GetWebFunctionRevision' => new Result(['state' => 'Active', 'serviceConfig' => $liveConnector === null ? [] : ['egressNetworkConnectorArn' => $liveConnector]]),
            'DescribeStacks' => AwsMocks::error(CloudFormationException::class, 'DescribeStacks', 'AccessDenied', 403),
            'DeleteFunction' => self::lambdaMissing('DeleteFunction'),
            'DeleteWebFunction' => new Result([]),
            'DeleteNetworkConnector' => new Result(['State' => 'DELETING']),
            'DeleteRolePolicy' => new Result([]), 'DeleteRole' => new Result([]),
            'GetGroupQuery' => self::noGroup(),
            // No network interface of the console or the workers left to wait for.
            'DescribeNetworkInterfaces' => new Result(['NetworkInterfaces' => []]),
        ];
    }

    /** @param list<string> $vpcVersions the console versions still in a VPC */
    private static function consoleVersions(array $vpcVersions, array $outside = []): Result
    {
        $vpc = ['SubnetIds' => ['subnet-a'], 'SecurityGroupIds' => ['sg-1'], 'VpcId' => 'vpc-1'];
        return new Result(['Versions' => [
            ...array_map(fn (string $v) => ['Version' => $v, 'VpcConfig' => $vpc], $vpcVersions),
            ...array_map(fn (string $v) => ['Version' => $v, 'VpcConfig' => ['SubnetIds' => [], 'SecurityGroupIds' => []]], $outside),
        ]]);
    }

    private static function interfaces(string ...$ids): Result
    {
        return new Result(['NetworkInterfaces' => array_map(fn (string $id) => ['NetworkInterfaceId' => $id, 'Description' => 'AWS Lambda VPC ENI-shop-production-console',
            'SubnetId' => 'subnet-a', 'Groups' => [['GroupId' => 'sg-1']]], $ids)]);
    }

    /** @param list<array{0: string, 1: list<string>, 2: list<string>}> $functions name, subnets, groups */
    private static function functionsInRegion(array $functions): Result
    {
        return new Result(['Functions' => array_map(fn (array $f) => ['FunctionName' => $f[0], 'Version' => '$LATEST',
            'VpcConfig' => ['SubnetIds' => $f[1], 'SecurityGroupIds' => $f[2]]], $functions)]);
    }

    /**
     * Lambda deletes a VPC function's network interface with the function's execution role, up to about 20 minutes
     * after the function is gone: a role deleted earlier leaves the interface in the VPC for good.
     */
    public function test_remove_waits_for_the_vpc_interfaces_before_the_execution_role(): void
    {
        $mocks = new AwsMocks();
        self::answer($mocks, [...self::removal(null), 'GetNetworkConnector' => [self::connector(), self::connectorGone()],
            // A function remove just deleted may still be listed: it never counts as another user of the interface.
            'ListFunctions' => self::functionsInRegion([['shop-production-console', ['subnet-a'], ['sg-1']], ['other-app', ['subnet-b'], ['sg-1']]]),
            'DescribeNetworkInterfaces' => [self::interfaces('eni-1'), self::interfaces()]]);
        $steps = [];

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->remove($this->vpcConfig(console: true), function (string $key, array $params = []) use (&$steps): void { $steps[] = [$key, $params]; });

        $calls = $mocks->calls;
        self::assertSame(['DescribeNetworkInterfaces', 'DescribeNetworkInterfaces'], self::only($mocks, 'DescribeNetworkInterfaces'));
        self::assertLessThan(min(array_keys($calls, 'DescribeNetworkInterfaces', true)), max(array_keys($calls, 'DeleteFunction', true)), 'the functions go first');
        self::assertLessThan(max(array_keys($calls, 'DeleteRole', true)), max(array_keys($calls, 'DescribeNetworkInterfaces', true)), 'the execution role goes once the interfaces are gone');
        $by = self::byOperation($mocks);
        self::assertSame([['Name' => 'description', 'Values' => ['AWS Lambda VPC ENI-shop-production-console', 'AWS Lambda VPC ENI-shop-production-console-*', 'AWS Lambda VPC ENI-shop-production-queue-*']]], $by['DescribeNetworkInterfaces'][0]['Filters']);
        self::assertSame('ALL', $by['ListFunctions'][0]['FunctionVersion']);
        self::assertSame(['arco-shop-production-network', 'arco-shop-production'], array_column($by['DeleteRole'], 'RoleName'));
        self::assertContains(['remove.step_vpc_interfaces', ['functions' => 'shop-production-console, shop-production-queue-*']], $steps);
    }

    /** Staging and production on the same subnets and groups share one interface: production keeps it, and its role. */
    public function test_remove_does_not_wait_for_an_interface_another_function_still_uses(): void
    {
        $mocks = new AwsMocks();
        self::answer($mocks, [...self::removal(null), 'GetNetworkConnector' => [self::connector(), self::connectorGone()],
            'ListFunctions' => self::functionsInRegion([['shop-staging-console', ['subnet-b', 'subnet-a'], ['sg-1']]]),
            'DescribeNetworkInterfaces' => self::interfaces('eni-1')]);
        $steps = [];

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->remove($this->vpcConfig(console: true), function (string $key, array $params = []) use (&$steps): void { $steps[] = [$key, $params]; });

        self::assertSame(['DescribeNetworkInterfaces'], self::only($mocks, 'DescribeNetworkInterfaces'), 'no waiting');
        self::assertSame(['arco-shop-production-network', 'arco-shop-production'], array_column(self::byOperation($mocks)['DeleteRole'], 'RoleName'));
        self::assertNotContains('remove.vpc_interfaces_pending', array_column($steps, 0));
    }

    public function test_remove_keeps_the_execution_role_while_a_vpc_interface_is_left(): void
    {
        $mocks = new AwsMocks();
        self::answer($mocks, [...self::removal(null), 'GetNetworkConnector' => [self::connector(), self::connectorGone()],
            'ListFunctions' => self::functionsInRegion([]), 'DescribeNetworkInterfaces' => self::interfaces('eni-1')]);
        $steps = [];

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->remove($this->vpcConfig(console: true), function (string $key, array $params = []) use (&$steps): void { $steps[] = [$key, $params]; });

        self::assertSame(['arco-shop-production-network'], array_column(self::byOperation($mocks)['DeleteRole'], 'RoleName'), 'only the operator role');
        self::assertContains(['remove.vpc_interfaces_pending', ['interfaces' => 'eni-1', 'region' => 'us-east-1', 'role' => 'arco-shop-production']], $steps);
        self::assertContains('remove.vpc_interfaces_pending', \Multiapi\Arco\Cli\StepPrinter::WARNINGS);
        self::assertContains('GetGroupQuery', $mocks->calls, 'the rest of the remove goes on');
        self::assertSame(1, count(self::only($mocks, 'ListFunctions')), 'the other functions are read once');
    }

    public function test_remove_keeps_the_execution_role_when_the_interfaces_cannot_be_read(): void
    {
        $mocks = new AwsMocks();
        self::answer($mocks, [...self::removal(null), 'GetNetworkConnector' => [self::connector(), self::connectorGone()],
            'DescribeNetworkInterfaces' => AwsMocks::error(LambdaException::class, 'DescribeNetworkInterfaces', 'UnauthorizedOperation', 403, 'denied')]);
        $steps = [];

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->remove($this->vpcConfig(console: true), function (string $key, array $params = []) use (&$steps): void { $steps[] = [$key, $params]; });

        self::assertSame(['arco-shop-production-network'], array_column(self::byOperation($mocks)['DeleteRole'], 'RoleName'));
        self::assertContains('remove.vpc_interfaces_failed', array_column($steps, 0));
        self::assertContains('remove.vpc_interfaces_failed', \Multiapi\Arco\Cli\StepPrinter::WARNINGS);
    }

    public function test_remove_of_an_environment_without_vpc_reads_no_interfaces(): void
    {
        $mocks = new AwsMocks();
        self::answer($mocks, self::removal(null));

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->remove($this->config());

        self::assertSame([], self::only($mocks, 'ListVersionsByFunction', 'DescribeNetworkInterfaces', 'ListFunctions'));
        self::assertSame(['arco-shop-production'], array_column(self::byOperation($mocks)['DeleteRole'], 'RoleName'));
    }

    public function test_the_interface_listing_follows_next_token(): void
    {
        $mocks = new AwsMocks();
        self::answer($mocks, [...self::removal(null), 'GetNetworkConnector' => [self::connector(), self::connectorGone()], 'ListFunctions' => self::functionsInRegion([]),
            'DescribeNetworkInterfaces' => [new Result(['NetworkInterfaces' => [], 'NextToken' => 'page-2']), self::interfaces('eni-2'), new Result(['NetworkInterfaces' => []])]]);
        $steps = [];

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->remove($this->vpcConfig(console: true), function (string $key, array $params = []) use (&$steps): void { $steps[] = [$key, $params]; });

        $by = self::byOperation($mocks);
        self::assertSame('page-2', $by['DescribeNetworkInterfaces'][1]['NextToken']);
        self::assertCount(3, $by['DescribeNetworkInterfaces'], 'eni-2 on the second page is waited for');
    }

    /**
     * Out of the VPC, the prune deletes the console and worker versions still in it and waits for their interfaces
     * while the execution role still has the ENI statements (the next deploy drops them), then the connector goes.
     */
    public function test_leaving_the_vpc_retires_the_vpc_versions_and_waits_for_their_interfaces_before_the_connector(): void
    {
        $mocks = new AwsMocks();
        self::answer($mocks, ['GetNetworkConnector' => [self::connector(), self::connectorGone()], 'DeleteNetworkConnector' => new Result(['State' => 'DELETING']),
            'DeleteRolePolicy' => new Result([]), 'DeleteRole' => new Result([]), 'DeleteFunction' => new Result([]),
            // The live alias still on a VPC version (a console that was not activated): never deleted.
            'GetAlias' => new Result(['FunctionVersion' => '6']),
            // Read by the version prune (it keeps these few) and by the retire step.
            'ListVersionsByFunction' => self::consoleVersions(['5', '6'], ['$LATEST', '7']),
            'ListFunctions' => self::functionsInRegion([]),
            'DescribeNetworkInterfaces' => [self::interfaces('eni-1'), self::interfaces()]]);
        $prepared = new PreparedDeploy('rev-2', 'rev-1', 'bucket', 'key', '7', '6', leftoverConnector: true, leftVpc: true);
        $deployer = new AwsDeployer($mocks->clients(), AwsMocks::noSleep());
        $steps = [];

        $deployer->prune($this->leftoverConfig(), $prepared, function (string $key, array $params = []) use (&$steps): void { $steps[] = [$key, $params]; });

        $by = self::byOperation($mocks);
        $qualifiers = array_column(array_filter($by['DeleteFunction'], fn (array $call) => $call['FunctionName'] === 'shop-production-console'), 'Qualifier');
        self::assertContains('5', $qualifiers, 'a version in the VPC goes');
        self::assertNotContains('6', $qualifiers, 'never the version the live alias points to');
        self::assertNotContains('$LATEST', $qualifiers);
        self::assertNotContains('7', $qualifiers);
        $calls = self::only($mocks, 'DescribeNetworkInterfaces', 'DeleteNetworkConnector');
        self::assertSame(['DescribeNetworkInterfaces', 'DescribeNetworkInterfaces', 'DeleteNetworkConnector'], $calls, 'the interfaces are gone before the connector goes');
        self::assertContains(['deploy.step_vpc_interfaces', ['functions' => 'shop-production-console, shop-production-queue-*']], $steps);
    }

    /** A worker this deploy deleted has no version left to list, but its interface is still waited for. */
    public function test_leaving_the_vpc_waits_even_when_no_vpc_version_is_left(): void
    {
        $mocks = new AwsMocks();
        self::answer($mocks, ['GetNetworkConnector' => [self::connector(), self::connectorGone()], 'DeleteNetworkConnector' => new Result(['State' => 'DELETING']),
            'DeleteRolePolicy' => new Result([]), 'DeleteRole' => new Result([]),
            'ListVersionsByFunction' => new Result(['Versions' => []]), 'ListFunctions' => self::functionsInRegion([]), 'GetAlias' => new Result(['FunctionVersion' => '7']),
            'DescribeNetworkInterfaces' => self::interfaces('eni-1')]);
        $prepared = new PreparedDeploy('rev-2', 'rev-1', 'bucket', 'key', '7', '6', leftoverConnector: true, leftVpc: true);
        $steps = [];

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->prune($this->leftoverConfig(), $prepared, function (string $key, array $params = []) use (&$steps): void { $steps[] = [$key, $params]; });

        self::assertContains(['deploy.vpc_interfaces_pending', ['interfaces' => 'eni-1', 'region' => 'us-east-1', 'role' => 'arco-shop-production']], $steps);
        self::assertContains('deploy.vpc_interfaces_pending', \Multiapi\Arco\Cli\StepPrinter::WARNINGS);
        self::assertContains('DeleteNetworkConnector', $mocks->calls, 'the connector still goes');
    }

    public function test_leaving_the_vpc_with_a_foreign_connector_still_retires_the_vpc_versions(): void
    {
        $mocks = new AwsMocks();
        self::answer($mocks, ['DeleteFunction' => new Result([]), 'ListVersionsByFunction' => self::consoleVersions(['5']), 'GetAlias' => new Result(['FunctionVersion' => '7']),
            'ListFunctions' => self::functionsInRegion([]), 'DescribeNetworkInterfaces' => self::interfaces()]);
        $prepared = new PreparedDeploy('rev-2', 'rev-1', 'bucket', 'key', '7', '6', leftoverConnector: false, leftVpc: true);

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->prune($this->leftoverConfig(), $prepared, fn () => null);

        self::assertContains('DescribeNetworkInterfaces', $mocks->calls);
        self::assertNotContains('DeleteNetworkConnector', $mocks->calls);
    }

    public function test_remove_deletes_functions_then_connector_then_role(): void
    {
        $mocks = new AwsMocks();
        self::answer($mocks, [...self::removal(null), 'GetNetworkConnector' => [self::connector(), self::connectorGone()]]);
        $steps = [];

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->remove($this->vpcConfig(), function (string $key) use (&$steps): void { $steps[] = $key; });

        self::assertSame(
            ['GetNetworkConnector', 'DeleteFunction', 'DeleteWebFunction', 'DeleteNetworkConnector', 'GetNetworkConnector', 'DeleteRolePolicy', 'DeleteRole', 'DeleteRolePolicy', 'DeleteRole'],
            self::only($mocks, 'GetNetworkConnector', 'DeleteFunction', 'DeleteWebFunction', 'DeleteNetworkConnector', 'DeleteRolePolicy', 'DeleteRole'),
            'ownership first; functions, the connector (until gone), its operator role, then the execution role',
        );
        self::assertSame(['arco-shop-production-network', 'arco-shop-production'], array_column(self::byOperation($mocks)['DeleteRole'], 'RoleName'));
        self::assertNotContains('GetWebFunctionRevision', $mocks->calls, 'with vpc() the connector is checked by name');
        self::assertSame(['remove.step_console', 'remove.step_function', 'remove.step_network', 'deploy.network_removed', 'remove.step_vpc_interfaces'], $steps);
    }

    public function test_remove_without_vpc_deletes_the_connector_the_live_revision_pins(): void
    {
        $mocks = new AwsMocks();
        self::answer($mocks, [...self::removal(self::CONNECTOR_VERSION), 'GetNetworkConnector' => [self::connector(), self::connectorGone()]]);

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->remove($this->config());

        self::assertSame(['DeleteWebFunction', 'DeleteNetworkConnector'], self::only($mocks, 'DeleteWebFunction', 'DeleteNetworkConnector'));
    }

    public function test_remove_without_vpc_or_a_pinned_connector_makes_no_network_connector_call(): void
    {
        $mocks = new AwsMocks();
        self::answer($mocks, self::removal(null));

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->remove($this->config());

        self::assertSame([], array_values(array_filter($mocks->calls, fn (string $call) => str_contains($call, 'NetworkConnector'))));
        self::assertSame(['arco-shop-production'], array_column(self::byOperation($mocks)['DeleteRole'], 'RoleName'));
    }

    public function test_remove_leaves_foreign_connector(): void
    {
        $mocks = new AwsMocks();
        self::answer($mocks, [...self::removal(null), 'GetNetworkConnector' => self::connector(role: 'arn:aws:iam::111111111111:role/someone-else')]);
        $steps = [];

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->remove($this->vpcConfig(), function (string $key, array $params = []) use (&$steps): void { $steps[] = [$key, $params]; });

        self::assertNotContains('DeleteNetworkConnector', $mocks->calls);
        self::assertSame(['arco-shop-production'], array_column(self::byOperation($mocks)['DeleteRole'], 'RoleName'), 'nor its operator role');
        self::assertContains(['remove.network_foreign_kept', ['connector' => 'shop-production', 'role' => 'arco-shop-production-network']], $steps);
        self::assertContains('remove.network_foreign_kept', \Multiapi\Arco\Cli\StepPrinter::WARNINGS);
        self::assertContains('remove.step_function', array_column($steps, 0), 'the rest of the remove goes on');
    }

    public function test_a_failed_connector_removal_is_a_warning_with_the_manual_commands(): void
    {
        $mocks = new AwsMocks();
        self::answer($mocks, [...self::removal(null), 'GetNetworkConnector' => self::connector(),
            'DeleteNetworkConnector' => AwsMocks::error(\Aws\LambdaCore\Exception\LambdaCoreException::class, 'DeleteNetworkConnector', 'AccessDeniedException', 403, 'not allowed')]);
        $steps = [];

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->remove($this->vpcConfig(), function (string $key, array $params = []) use (&$steps): void { $steps[] = [$key, $params]; });

        self::assertContains(['remove.network_failed', ['connector' => 'shop-production', 'role' => 'arco-shop-production-network', 'region' => 'us-east-1', 'error' => 'AccessDeniedException not allowed']], $steps);
        self::assertContains('remove.network_failed', \Multiapi\Arco\Cli\StepPrinter::WARNINGS);
        self::assertSame(['arco-shop-production'], array_column(self::byOperation($mocks)['DeleteRole'], 'RoleName'));
    }

    public function test_describe_reads_the_connector_with_vpc(): void
    {
        $mocks = new AwsMocks();
        self::answer($mocks, [
            'GetWebFunction' => new Result(['functionName' => 'shop-production', 'state' => 'Active']),
            'GetWebFunctionEndpoint' => new Result(['domainName' => 'abc.on.aws', 'state' => 'Active', 'revisionWeights' => [['revisionId' => 'rev-1', 'weight' => 100]]]),
            'GetNetworkConnector' => self::connector(3),
        ]);

        $status = (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->describe($this->vpcConfig());

        self::assertSame(['connector' => 'nc-0abc', 'version' => 3, 'state' => 'ACTIVE'], $status?->vpc);

        $mocks = new AwsMocks();
        self::answer($mocks, ['GetWebFunction' => new Result(['functionName' => 'shop-production', 'state' => 'Active']), 'GetWebFunctionEndpoint' => new Result([])]);
        self::assertNull((new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->describe($this->config())?->vpc);
        self::assertNotContains('GetNetworkConnector', $mocks->calls, 'no vpc(): no lambda-core call');
    }

    private static function operatorRole(?array $tags = null): Result
    {
        return new Result(['Role' => ['Arn' => self::OPERATOR_ROLE, 'Tags' => self::tagList($tags ?? self::tags()), 'Description' => 'Execution role managed by Arco']]);
    }

    /** @return list<string> the calls that change something */
    private static function writes(AwsMocks $mocks): array
    {
        return array_values(array_filter($mocks->calls, fn (string $call) => ! preg_match('/^(Get|List|Describe|Head)/', $call)));
    }

    public function test_with_vpc_another_owners_operator_role_is_refused_before_any_change(): void
    {
        $mocks = new AwsMocks();
        self::answer($mocks, [...self::existingEnvironment(null), 'GetNetworkConnector' => self::connector(),
            'GetRole' => [self::roleTags(), self::operatorRole(self::tags('other', 'staging'))]]);

        try {
            (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->checkOwnership($this->vpcConfig(), fn () => null);
            self::fail('the operator role belongs to another environment');
        } catch (DeployException $e) {
            self::assertSame('deploy.not_owned', $e->key);
            self::assertSame('arco-shop-production-network', $e->params['resource']);
        }
        self::assertSame([], self::writes($mocks));
    }

    public function test_a_leftover_connector_checks_its_operator_role_too(): void
    {
        $mocks = new AwsMocks();
        self::answer($mocks, [...self::existingEnvironment(self::CONNECTOR_VERSION), 'GetNetworkConnector' => self::connector(),
            'GetRole' => [self::roleTags(), self::operatorRole(self::tags('other', 'staging'))]]);

        try {
            (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->checkOwnership($this->leftoverConfig(), fn () => null);
            self::fail('prune() would delete another environment\'s role');
        } catch (DeployException $e) {
            self::assertSame('deploy.not_owned', $e->key);
            self::assertSame('arco-shop-production-network', $e->params['resource']);
        }
        self::assertSame([], self::writes($mocks));
    }

    public function test_with_vpc_a_foreign_connector_is_refused_before_any_change_even_with_force(): void
    {
        $mocks = new AwsMocks();
        self::answer($mocks, [...self::existingEnvironment(null), 'GetNetworkConnector' => self::connector(role: 'arn:aws:iam::111111111111:role/someone-else'),
            'ListTags' => new Result(['Tags' => [], 'tags' => []])]);

        try {
            (new AwsDeployer($mocks->clients(), AwsMocks::noSleep(), force: true))->checkOwnership($this->vpcConfig(), fn () => null);
            self::fail('lambda-core cannot tag a connector: --force cannot take it over');
        } catch (DeployException $e) {
            self::assertSame('deploy.network_foreign', $e->key);
        }
        self::assertSame([], self::writes($mocks), 'not even the tags --force would add');
    }

    public function test_remove_refuses_another_owners_operator_role_before_deleting_anything(): void
    {
        $mocks = new AwsMocks();
        self::answer($mocks, [...self::removal(null), 'GetNetworkConnector' => self::connector(),
            'GetRole' => [self::roleTags(), self::operatorRole(self::tags('other', 'staging'))]]);

        try {
            (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->remove($this->vpcConfig());
            self::fail('the operator role belongs to another environment');
        } catch (DeployException $e) {
            self::assertSame('remove.not_owned', $e->key);
            self::assertSame('arco-shop-production-network', $e->params['resource']);
        }
        self::assertSame([], self::only($mocks, 'DeleteFunction', 'DeleteWebFunction', 'DeleteNetworkConnector', 'DeleteRolePolicy', 'DeleteRole', 'DeleteRule', 'DeleteStack'));
    }

    public function test_a_failed_connector_the_live_revision_pins_stops_the_deploy(): void
    {
        $mocks = new AwsMocks();
        self::answer($mocks, [...self::existingEnvironment(self::CONNECTOR_VERSION), 'GetRole' => [self::roleTags(), self::operatorRole(),
            new Result(['Role' => ['Arn' => 'arn:role', 'Tags' => [['Key' => 'arco:region', 'Value' => 'us-east-1']]]]), self::operatorRole()],
            'GetNetworkConnector' => self::connector(extra: ['State' => 'FAILED', 'StateReasonCode' => 'InvalidSubnet'])]);
        $deployer = new AwsDeployer($mocks->clients(), AwsMocks::noSleep());

        $deployer->checkOwnership($this->vpcConfig(), fn () => null);
        try {
            $deployer->prepare($this->vpcConfig(), $this->package(), null, []);
            self::fail('the live revision serves through it');
        } catch (DeployException $e) {
            self::assertSame('deploy.network_failed', $e->key);
        }
        self::assertSame([], self::only($mocks, 'DeleteNetworkConnector', 'CreateNetworkConnector', 'CreateWebFunctionRevision'));
    }

    public function test_out_of_the_vpc_even_when_the_pinned_connector_is_not_arcos(): void
    {
        $mocks = new AwsMocks();
        self::answer($mocks, [...self::existingEnvironment(self::CONNECTOR_VERSION), 'GetNetworkConnector' => self::connector(role: 'arn:aws:iam::111111111111:role/someone-else'),
            'GetRole' => [self::roleTags(), self::noRole(), new Result(['Role' => ['Arn' => 'arn:role', 'Tags' => [['Key' => 'arco:region', 'Value' => 'us-east-1']]]])]]);
        $deployer = new AwsDeployer($mocks->clients(), AwsMocks::noSleep());

        $deployer->checkOwnership($this->leftoverConfig(), fn () => null);
        $prepared = $deployer->prepare($this->leftoverConfig(), $this->package(), $this->console(), []);

        self::assertFalse($prepared->leftoverConnector, 'not Arco\'s: never deleted');
        $by = self::byOperation($mocks);
        foreach ($by['UpdateFunctionConfiguration'] as $update) {
            self::assertSame(['SubnetIds' => [], 'SecurityGroupIds' => []], $update['VpcConfig'], 'vpc() is gone: the console and the workers leave the VPC');
        }
        self::assertStringContainsString('ArcoVpcAccess', (string) $by['PutRolePolicy'][0]['PolicyDocument'], 'a rollback to a VPC console or worker version still works');
    }

    public function test_the_console_update_is_retried_while_the_new_vpc_statements_propagate(): void
    {
        $mocks = new AwsMocks();
        self::answer($mocks, [...self::existingEnvironment(null), 'GetNetworkConnector' => self::connector(),
            'GetRole' => [self::roleTags(), self::operatorRole(), new Result(['Role' => ['Arn' => 'arn:role', 'Tags' => [['Key' => 'arco:region', 'Value' => 'us-east-1']]]]), self::operatorRole()],
            'UpdateFunctionConfiguration' => [
                AwsMocks::error(LambdaException::class, 'UpdateFunctionConfiguration', 'InvalidParameterValueException', 400, 'The provided execution role does not have permissions to call CreateNetworkInterface on EC2'),
                new Result([]),
            ]]);
        $deployer = new AwsDeployer($mocks->clients(), AwsMocks::noSleep());

        $deployer->checkOwnership($this->vpcConfig(console: true), fn () => null);
        $prepared = $deployer->prepare($this->vpcConfig(console: true), $this->package(), $this->console(), []);

        self::assertSame('7', $prepared->consoleVersion);
        self::assertCount(2, self::byOperation($mocks)['UpdateFunctionConfiguration'], 'the role gained ArcoVpcAccess in this deploy: retried like a new role');
    }

    /**
     * An environment without vpc() makes exactly the calls it made before 0.12, plus the lambda-web reads of
     * the live revision: no lambda-core call, no operator role, no new permission.
     */
    public function test_an_environment_without_vpc_makes_no_new_kind_of_call(): void
    {
        $mocks = new AwsMocks();
        self::answer($mocks, [...self::existingEnvironment(null),
            'ListObjectsV2' => new Result(['Contents' => []]), 'ListObjectVersions' => new Result([]), 'GetResources' => new Result(['ResourceTagMappingList' => []])]);
        $deployer = new AwsDeployer($mocks->clients(), AwsMocks::noSleep());

        $deployer->checkOwnership($this->config(), fn () => null);
        $prepared = $deployer->prepare($this->config(), $this->package(), null, []);
        $deployer->prune($this->config(), $prepared);

        self::assertSame([
            'GetRole', 'GetWebFunction', 'ListTags', 'GetFunctionConfiguration', 'ListTags', 'DescribeRule', 'GetWebFunctionEndpoint', 'GetWebFunctionRevision',
            'GetCallerIdentity', 'HeadBucket', 'PutPublicAccessBlock', 'PutBucketPolicy', 'PutBucketVersioning', 'PutBucketLifecycleConfiguration', 'HeadObject',
            'GetRole', 'PutRolePolicy',
            'GetWebFunction', 'GetWebFunctionEndpoint', 'CreateWebFunctionRevision', 'GetWebFunctionRevision',
            'ListObjectsV2', 'GetResources', 'ListObjectsV2',
        ], $mocks->calls);

        $mocks = new AwsMocks();
        self::answer($mocks, self::removal(null));

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->remove($this->config());

        // remove reads the live revision too (does it pin a connector?); nothing else is new.
        self::assertSame([
            'GetResources', 'DescribeRule', 'GetFunctionConfiguration', 'GetWebFunction', 'ListTags', 'GetRole',
            'GetWebFunctionEndpoint', 'GetWebFunctionRevision',
            'DescribeStacks', 'DescribeRule', 'DescribeStacks', 'DeleteFunction', 'DeleteWebFunction', 'GetWebFunction',
            'DeleteRolePolicy', 'DeleteRole', 'GetGroupQuery',
        ], $mocks->calls);
    }
}
