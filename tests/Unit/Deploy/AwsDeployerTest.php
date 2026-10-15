<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy;

use Aws\CloudFormation\Exception\CloudFormationException;
use Aws\Iam\Exception\IamException;
use Aws\LambdaWeb\Exception\LambdaWebException;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Config\Environment;
use Multiapi\Arco\Deploy\AwsDeployer;
use Multiapi\Arco\Deploy\DeployResult;
use Multiapi\Arco\Package\Package;
use PHPUnit\Framework\TestCase;

final class AwsDeployerTest extends TestCase
{
    private function config(): AppConfig
    {
        return ConfigResolver::resolve(App::make('shop')->region('us-east-1')->environment('production')->toArray(), 'production');
    }

    private function package(): Package
    {
        $file = tempnam(sys_get_temp_dir(), 'pkg');
        file_put_contents((string) $file, 'zip');
        return new Package((string) $file, str_repeat('a', 64), 3);
    }

    public function test_first_deploy(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['Account' => '111111111111']),                                             // GetCallerIdentity
            new Result([]),                                                                      // HeadBucket
            new Result([]), new Result([]), new Result([]), new Result([]),                      // PutPublicAccessBlock, PutBucketPolicy, PutBucketVersioning, PutBucketLifecycleConfiguration
            AwsMocks::error(S3Exception::class, 'HeadObject', 'NotFound', 404),
            new Result(['VersionId' => 'v1']),                                                   // PutObject
            AwsMocks::error(IamException::class, 'GetRole', 'NoSuchEntity', 404),
            new Result(['Role' => ['Arn' => 'arn:aws:iam::111111111111:role/arco-shop-production']]), // CreateRole
            new Result([]),                                                                      // PutRolePolicy
            AwsMocks::error(LambdaWebException::class, 'GetWebFunction', 'ResourceNotFoundException', 404),
            // The role was just created, so a propagation error is retried.
            AwsMocks::error(LambdaWebException::class, 'CreateWebFunction', 'ValidationException', 400, 'The role defined for the function cannot be assumed by Lambda.'),
            new Result(['revision' => ['revisionId' => 'rev-1'], 'endpoint' => ['domainName' => 'abc.xpt.lambda-web.us-east-1.on.aws']]),
            new Result(['state' => 'Active', 'stateReason' => 'ok']),                           // GetWebFunctionRevision
            new Result(['state' => 'Active', 'domainName' => 'abc.xpt.lambda-web.us-east-1.on.aws', 'revisionWeights' => []]), // GetWebFunctionEndpoint (idle before routing)
            new Result([]),                                                                      // UpdateWebFunctionEndpoint
            new Result(['state' => 'Active', 'updateStatus' => 'Successful', 'domainName' => 'abc.xpt.lambda-web.us-east-1.on.aws', 'revisionWeights' => [['revisionId' => 'rev-1', 'weight' => 100]]]),
            new Result(['Contents' => []]),                                                      // ListObjectsV2 (prune)
        );
        $steps = [];

        $result = (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))
            ->deploy($this->config(), $this->package(), ['APP_ENV' => 'production'], function (string $key) use (&$steps): void { $steps[] = $key; });

        self::assertSame([
            'GetCallerIdentity', 'HeadBucket', 'PutPublicAccessBlock', 'PutBucketPolicy', 'PutBucketVersioning', 'PutBucketLifecycleConfiguration', 'HeadObject', 'PutObject', 'GetRole', 'CreateRole', 'PutRolePolicy',
            'GetWebFunction', 'CreateWebFunction', 'CreateWebFunction', 'GetWebFunctionRevision', 'GetWebFunctionEndpoint',
            'UpdateWebFunctionEndpoint', 'GetWebFunctionEndpoint', 'ListObjectsV2',
        ], $mocks->calls);
        self::assertSame('https://abc.xpt.lambda-web.us-east-1.on.aws', $result->url());
        self::assertSame('rev-1', $result->revisionId);
        self::assertNull($result->previousRevisionId);
        self::assertSame('arco-111111111111-us-east-1', $result->bucket);
        self::assertSame('shop/production/' . str_repeat('a', 64) . '.zip', $result->key);

        $update = $mocks->params[16];
        self::assertSame('Disabled', $update['autoDeploymentMode']);
        self::assertSame([['revisionId' => 'rev-1', 'weight' => 100]], $update['revisionWeights']);
        self::assertSame(['deploy.step_bucket', 'deploy.step_upload', 'deploy.step_role', 'deploy.step_function', 'deploy.step_traffic'], $steps);
    }

    public function test_redeploy_creates_a_revision_and_remembers_the_previous_one(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['Account' => '111111111111']),
            new Result([]),                                   // HeadBucket
            new Result([]), new Result([]), new Result([]), new Result([]), // bucket hardening PUTs
            new Result(['VersionId' => 'v1']),                // HeadObject (already uploaded)
            new Result(['Role' => ['Arn' => 'arn:role']]),    // GetRole
            new Result([]),                                   // PutRolePolicy
            new Result(['functionName' => 'shop-production', 'state' => 'Active']),
            new Result(['domainName' => 'abc.on.aws', 'state' => 'Active', 'revisionWeights' => [['revisionId' => 'rev-1', 'weight' => 100]]]),
            new Result(['revisionId' => 'rev-2', 'state' => 'Pending']),
            new Result(['state' => 'Active', 'stateReason' => 'ok']),
            new Result(['state' => 'Active', 'domainName' => 'abc.on.aws', 'revisionWeights' => [['revisionId' => 'rev-1', 'weight' => 100]]]), // idle before routing
            new Result([]),
            new Result(['state' => 'Active', 'updateStatus' => 'Successful', 'domainName' => 'abc.on.aws', 'revisionWeights' => [['revisionId' => 'rev-2', 'weight' => 100]]]),
            new Result(['Contents' => []]),
        );

        $result = (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->deploy($this->config(), $this->package(), []);

        self::assertSame('rev-2', $result->revisionId);
        self::assertSame('rev-1', $result->previousRevisionId);
        self::assertSame([
            'GetCallerIdentity', 'HeadBucket', 'PutPublicAccessBlock', 'PutBucketPolicy', 'PutBucketVersioning', 'PutBucketLifecycleConfiguration', 'HeadObject', 'GetRole', 'PutRolePolicy', 'GetWebFunction', 'GetWebFunctionEndpoint',
            'CreateWebFunctionRevision', 'GetWebFunctionRevision', 'GetWebFunctionEndpoint', 'UpdateWebFunctionEndpoint',
            'GetWebFunctionEndpoint', 'ListObjectsV2',
        ], $mocks->calls);
        self::assertNotContains('PutObject', $mocks->calls);
    }

    public function test_rollback_routes_traffic_to_the_given_revision(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['state' => 'Active', 'domainName' => 'x', 'revisionWeights' => [['revisionId' => 'rev-2', 'weight' => 100]]]),
            new Result([]),
            new Result(['state' => 'Active', 'updateStatus' => 'Successful', 'domainName' => 'x', 'revisionWeights' => [['revisionId' => 'rev-1', 'weight' => 100]]]),
        );

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->rollback($this->config(), 'rev-1');

        self::assertSame(['GetWebFunctionEndpoint', 'UpdateWebFunctionEndpoint', 'GetWebFunctionEndpoint'], $mocks->calls);
        self::assertSame([['revisionId' => 'rev-1', 'weight' => 100]], $mocks->params[1]['revisionWeights']);
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
            new Result([]),                                                                     // DeleteWebFunction
            AwsMocks::error(LambdaWebException::class, 'GetWebFunction', 'ResourceNotFoundException', 404),
            new Result([]), new Result([]),                                                     // DeleteRolePolicy, DeleteRole
        );

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->remove($this->config());

        self::assertSame(['DescribeStacks', 'DeleteWebFunction', 'GetWebFunction', 'DeleteRolePolicy', 'DeleteRole'], $mocks->calls);
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
            new Result([]),
            AwsMocks::error(LambdaWebException::class, 'GetWebFunction', 'ResourceNotFoundException', 404),
            new Result([]), new Result([]),
        );
        $steps = [];

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->remove($config, function (string $key) use (&$steps): void { $steps[] = $key; });

        self::assertSame(['DescribeStacks', 'DescribeStacks', 'DeleteStack', 'DescribeStacks', 'ListCertificates', 'DeleteWebFunction', 'GetWebFunction', 'DeleteRolePolicy', 'DeleteRole'], $mocks->calls);
        self::assertSame(['remove.step_distribution', 'remove.step_function'], $steps);
    }

    public function test_describe_includes_the_distribution_when_domains_are_configured(): void
    {
        $config = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->environment('production', fn (Environment $e) => $e->domain('a.example.com'))->toArray(), 'production');
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['functionName' => 'shop-production', 'state' => 'Active']),
            new Result(['domainName' => 'abc.on.aws', 'state' => 'Active', 'revisionWeights' => [['revisionId' => 'rev-1', 'weight' => 100]]]),
            new Result(['Stacks' => [['StackName' => 'arco-shop-production', 'StackStatus' => 'UPDATE_COMPLETE', 'Outputs' => [['OutputKey' => 'DistributionDomain', 'OutputValue' => 'd1.cloudfront.net']]]]]),
            new Result(['functionName' => 'shop-production', 'state' => 'Active']),
            new Result(['domainName' => 'abc.on.aws', 'state' => 'Active', 'revisionWeights' => []]),
            AwsMocks::error(CloudFormationException::class, 'DescribeStacks', 'AccessDenied', 403),
        );
        $deployer = new AwsDeployer($mocks->clients(), AwsMocks::noSleep());

        $status = $deployer->describe($config);
        self::assertSame('d1.cloudfront.net', $status?->distributionDomain);
        self::assertSame('UPDATE_COMPLETE', $status?->distributionStatus);

        $status = $deployer->describe($config);
        self::assertSame('abc.on.aws', $status?->domainName, 'still described without CloudFormation access');
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
