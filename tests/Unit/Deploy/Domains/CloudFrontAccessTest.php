<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy\Domains;

use Aws\CloudFront\Exception\CloudFrontException;
use Aws\LambdaWeb\Exception\LambdaWebException;
use Aws\Result;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler as HttpMock;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Config\Environment;
use Multiapi\Arco\Deploy\Domains\CloudFrontAccess;
use Multiapi\Arco\Deploy\DeployException;
use Multiapi\Arco\Deploy\Sleeper;
use Multiapi\Arco\Deploy\WebFunctions;
use Multiapi\Arco\Tests\Unit\Deploy\AwsMocks;
use PHPUnit\Framework\TestCase;

final class CloudFrontAccessTest extends TestCase
{
    private const FUNCTION_ARN = 'arn:aws:lambda:us-east-1:111111111111:web-function/shop-production';
    private const ENDPOINT_ARN = 'arn:aws:lambda:us-east-1:111111111111:web-function/shop-production/endpoint/default';
    private const DISTRIBUTION_ARN = 'arn:aws:cloudfront::111111111111:distribution/E123';
    private const REVISION = '0a1b2c3d-1111-2222-3333-444455556666';

    private AwsMocks $aws;
    /** @var list<array{0: string, 1: array<string, string|int>}> */
    private array $steps = [];

    protected function setUp(): void
    {
        $this->aws = new AwsMocks();
        $this->http = new HttpMock([]);
    }

    protected function assertPostConditions(): void
    {
        self::assertCount(0, $this->aws->handler, 'every queued response is used');
    }

    private Sleeper $sleeper;

    /** The site through CloudFront, after the switch. */
    private HttpMock $http;

    private function access(): CloudFrontAccess
    {
        $clients = $this->aws->clients();
        $this->sleeper = AwsMocks::noSleep();
        return new CloudFrontAccess(new WebFunctions($clients->lambdaWeb(), AwsMocks::noSleep()), $clients->cloudFront(), $this->sleeper,
            new Client(['handler' => HandlerStack::create($this->http)]));
    }

    private static function config(): AppConfig
    {
        return ConfigResolver::resolve(App::make('shop')->region('us-east-1')->environment('production', fn (Environment $e) => $e->domain('arco.acmeapp.io'))->toArray(), 'production');
    }

    private function protect(): void
    {
        $this->access()->protect(self::config(), 'E123', 'd111.cloudfront.net', '/up', function (string $key, array $params): void { $this->steps[] = [$key, $params]; });
    }

    private function protectTenant(?string $domain = null): void
    {
        $this->access()->protectTenant(self::config(), 'E123', $domain, '/up', function (string $key, array $params): void { $this->steps[] = [$key, $params]; });
    }

    private static function tenant(string $distribution = 'E123', bool $enabled = true, string $status = 'active'): Result
    {
        return new Result(['DistributionTenant' => ['Id' => 'DTENANT', 'DistributionId' => $distribution, 'Enabled' => $enabled,
            'Domains' => [['Domain' => 'customer.example.com', 'Status' => $status]]]]);
    }

    private static function tenantDns(string $status = 'valid-configuration', string $domain = 'customer.example.com'): Result
    {
        return new Result(['DnsConfigurationList' => [['Domain' => $domain, 'Status' => $status]]]);
    }

    private static function endpoint(string $authType, string $updateStatus = 'Successful'): Result
    {
        return new Result([
            'functionArn' => self::FUNCTION_ARN, 'endpointArn' => self::ENDPOINT_ARN, 'authType' => $authType,
            'state' => 'Active', 'updateStatus' => $updateStatus, 'domainName' => 'abc.lambda-web.us-east-1.on.aws',
        ]);
    }

    /** @param list<array<string, mixed>> $statements */
    private static function policy(array $statements): Result
    {
        return new Result(['policy' => json_encode(['Version' => '2012-10-17', 'Id' => 'kept', 'Statement' => $statements]), 'revisionId' => self::REVISION]);
    }

    private static function noPolicy(): LambdaWebException
    {
        return AwsMocks::error(LambdaWebException::class, 'GetResourcePolicy', 'ResourceNotFoundException', 404);
    }

    /** @return array<string, mixed> another tool's statement, which Arco must keep as it is */
    private static function foreign(): array
    {
        return ['Sid' => 'PartnerReads', 'Effect' => 'Allow', 'Principal' => ['AWS' => '222222222222'], 'Action' => 'lambda:GetWebFunction', 'Resource' => self::FUNCTION_ARN];
    }

    /** @return list<array<string, mixed>> */
    private function putStatements(int $call): array
    {
        $policy = json_decode((string) $this->aws->params[$call]['policy'], true);
        self::assertIsArray($policy);
        return $policy['Statement'];
    }

    public function test_an_application_managed_endpoint_is_locked_only_after_the_distribution_signs_and_may_invoke_it(): void
    {
        $this->aws->handler->append(
            self::endpoint('ApplicationManaged'),
            self::noPolicy(),
            new Result([]),                                                        // PutResourcePolicy
            new Result(['Distribution' => ['Id' => 'E123', 'Status' => 'InProgress']]),
            new Result(['Distribution' => ['Id' => 'E123', 'Status' => 'Deployed']]),
            self::endpoint('ApplicationManaged'),                                  // idle
            new Result([]),                                                        // UpdateWebFunctionEndpoint
            self::endpoint('IamAuth'),
        );
        $this->http->append(new Response(200));

        $this->protect();

        self::assertSame(['GetWebFunctionEndpoint', 'GetResourcePolicy', 'PutResourcePolicy', 'GetDistribution', 'GetDistribution',
            'GetWebFunctionEndpoint', 'UpdateWebFunctionEndpoint', 'GetWebFunctionEndpoint'], $this->aws->calls);
        self::assertSame(self::FUNCTION_ARN, $this->aws->params[1]['resourceArn'], 'the policy is on the function, not the endpoint');
        self::assertSame(self::FUNCTION_ARN, $this->aws->params[2]['resourceArn']);
        self::assertArrayNotHasKey('revisionId', $this->aws->params[2], 'a function without a policy has no revision to match');
        self::assertSame([[
            'Sid' => 'ArcoCloudFront',
            'Effect' => 'Allow',
            'Principal' => ['Service' => 'cloudfront.amazonaws.com'],
            'Action' => 'lambda:InvokeWebFunctionEndpoint',
            'Resource' => self::ENDPOINT_ARN,
            'Condition' => ['StringEquals' => ['aws:SourceArn' => self::DISTRIBUTION_ARN]],
        ]], $this->putStatements(2));
        self::assertSame('E123', $this->aws->params[3]['Id']);
        self::assertSame(['functionName' => 'shop-production', 'endpointName' => 'default', 'authType' => 'IamAuth'],
            array_intersect_key($this->aws->params[6], ['functionName' => 1, 'endpointName' => 1, 'authType' => 1]));
        self::assertArrayNotHasKey('revisionWeights', $this->aws->params[6], 'only the auth type changes');
        self::assertSame(['deploy.oac_policy', 'deploy.endpoint_protected', 'deploy.protection_check'], array_column($this->steps, 0));
        self::assertSame(['function' => 'shop-production', 'distribution' => 'E123'], $this->steps[0][1]);
        self::assertSame(['url' => 'https://d111.cloudfront.net/up'], $this->steps[2][1]);
        $request = $this->http->getLastRequest();
        self::assertSame('https://d111.cloudfront.net/up', (string) $request?->getUri(), 'the site through CloudFront');
        self::assertFalse((bool) $request?->hasHeader('Authorization'), 'unsigned, as a viewer');
    }

    /** The responses of an ApplicationManaged endpoint locked after the policy is written. */
    private function switching(): void
    {
        $this->aws->handler->append(
            self::endpoint('ApplicationManaged'), self::noPolicy(), new Result([]),
            new Result(['Distribution' => ['Id' => 'E123', 'Status' => 'Deployed']]),
            self::endpoint('ApplicationManaged'), new Result([]), self::endpoint('IamAuth'),
        );
    }

    public function test_a_few_403s_through_cloudfront_after_the_switch_are_waited_out(): void
    {
        $this->switching();
        $this->http->append(new Response(403), new Response(403), new Response(502), new Response(200));

        $this->protect();

        self::assertCount(0, $this->http, 'every answer was read');
        self::assertNotContains('ApplicationManaged', array_column(array_slice($this->aws->params, 6), 'authType'), 'not reverted');
        self::assertSame(15.0, $this->sleeper->now() - 1_000_000.0 - 10, 'three pauses of 5 s after the 10 s policy settle');
    }

    public function test_an_app_answer_other_than_403_or_5xx_counts_as_reached(): void
    {
        $this->switching();
        $this->http->append(new Response(404));

        $this->protect();

        self::assertSame(['GetWebFunctionEndpoint', 'GetResourcePolicy', 'PutResourcePolicy', 'GetDistribution', 'GetWebFunctionEndpoint', 'UpdateWebFunctionEndpoint', 'GetWebFunctionEndpoint'], $this->aws->calls);
    }

    public function test_a_site_cloudfront_cannot_reach_after_the_switch_is_reverted_to_public_and_fails_the_deploy(): void
    {
        $this->switching();
        $this->aws->handler->append(self::endpoint('IamAuth'), new Result([]), self::endpoint('ApplicationManaged'));
        $this->http->append(...array_fill(0, 13, new Response(403)));

        try {
            $this->protect();
            self::fail('a site CloudFront cannot reach must fail the deploy');
        } catch (DeployException $e) {
            self::assertSame('deploy.protection_reverted', $e->key);
            self::assertSame(['function' => 'shop-production', 'url' => 'https://d111.cloudfront.net/up', 'answer' => '403'], $e->params);
        }

        self::assertSame(['GetWebFunctionEndpoint', 'GetResourcePolicy', 'PutResourcePolicy', 'GetDistribution',
            'GetWebFunctionEndpoint', 'UpdateWebFunctionEndpoint', 'GetWebFunctionEndpoint',
            'GetWebFunctionEndpoint', 'UpdateWebFunctionEndpoint', 'GetWebFunctionEndpoint'], $this->aws->calls, 'no policy or access control change: harmless on a public endpoint');
        self::assertSame(['IamAuth', 'ApplicationManaged'], [$this->aws->params[5]['authType'], $this->aws->params[8]['authType']]);
        self::assertArrayNotHasKey('revisionWeights', $this->aws->params[8]);
        self::assertSame(60.0, $this->sleeper->now() - 1_000_000.0 - 10, 'about a minute of retries');
        self::assertCount(0, $this->http, 'thirteen attempts, 5 s apart');
        self::assertNotContains('deploy.endpoint_still_public', array_column($this->steps, 0), 'the error itself says so');
    }

    public function test_a_401_through_cloudfront_is_retried_like_a_403(): void
    {
        $this->switching();
        $this->http->append(new Response(401), new Response(200));

        $this->protect();

        self::assertCount(0, $this->http, 'the 401 was retried');
        self::assertSame(5.0, $this->sleeper->now() - 1_000_000.0 - 10);
    }

    public function test_a_persistent_401_is_reverted(): void
    {
        $this->switching();
        $this->aws->handler->append(self::endpoint('IamAuth'), new Result([]), self::endpoint('ApplicationManaged'));
        $this->http->append(...array_fill(0, 13, new Response(401)));

        $this->expectException(DeployException::class);
        $this->expectExceptionMessage('deploy.protection_reverted');
        $this->protect();
    }

    public function test_a_revert_that_fails_once_is_retried(): void
    {
        $this->switching();
        $this->aws->handler->append(
            self::endpoint('IamAuth'),
            AwsMocks::error(LambdaWebException::class, 'UpdateWebFunctionEndpoint', 'AccessDeniedException', 403),
            self::endpoint('IamAuth'), new Result([]), self::endpoint('ApplicationManaged'),
        );
        $this->http->append(...array_fill(0, 13, new Response(403)));

        try {
            $this->protect();
            self::fail('a reverted switch fails the deploy');
        } catch (DeployException $e) {
            self::assertSame('deploy.protection_reverted', $e->key);
        }

        self::assertSame(['GetWebFunctionEndpoint', 'UpdateWebFunctionEndpoint', 'GetWebFunctionEndpoint', 'UpdateWebFunctionEndpoint', 'GetWebFunctionEndpoint'],
            array_slice($this->aws->calls, 7));
        self::assertSame('ApplicationManaged', $this->aws->params[10]['authType']);
    }

    public function test_a_revert_that_keeps_failing_gives_the_manual_command(): void
    {
        $this->switching();
        $refused = fn () => AwsMocks::error(LambdaWebException::class, 'UpdateWebFunctionEndpoint', 'AccessDeniedException', 403, 'not allowed');
        $this->aws->handler->append(
            self::endpoint('IamAuth'), $refused(), self::endpoint('IamAuth'), $refused(), self::endpoint('IamAuth'), $refused(),
        );
        $this->http->append(...array_fill(0, 13, new Response(403)));

        try {
            $this->protect();
            self::fail('a failed revert must stop the deploy');
        } catch (DeployException $e) {
            self::assertSame('deploy.protection_revert_failed', $e->key);
            self::assertSame([
                'function' => 'shop-production',
                'error' => 'not allowed',
                'command' => 'aws lambda-web update-web-function-endpoint --region us-east-1 --function-name shop-production --endpoint-name default --auth-type ApplicationManaged',
            ], $e->params);
        }
        self::assertSame(3, count(array_keys(array_slice($this->aws->calls, 7), 'UpdateWebFunctionEndpoint')), 'three attempts');
        self::assertNotContains('deploy.endpoint_still_public', array_column($this->steps, 0), 'the endpoint is not known to be public');
    }

    public function test_an_error_after_the_switch_reverts_before_it_is_rethrown(): void
    {
        // The idle wait after the switch fails: the check never ran, so the lock is unverified.
        $this->aws->handler->append(
            self::endpoint('ApplicationManaged'), self::noPolicy(), new Result([]),
            new Result(['Distribution' => ['Id' => 'E123', 'Status' => 'Deployed']]),
            self::endpoint('ApplicationManaged'), new Result([]),
            AwsMocks::error(LambdaWebException::class, 'GetWebFunctionEndpoint', 'ValidationException', 400),
            self::endpoint('IamAuth'), new Result([]), self::endpoint('ApplicationManaged'),
        );

        try {
            $this->protect();
            self::fail('the error must stop the deploy');
        } catch (LambdaWebException $e) {
            self::assertSame('ValidationException', $e->getAwsErrorCode(), 'the original error');
        }

        self::assertSame(['IamAuth', 'ApplicationManaged'], [$this->aws->params[5]['authType'], $this->aws->params[8]['authType']]);
        self::assertSame(0, count($this->http), 'no request through CloudFront');
        self::assertNull($this->http->getLastRequest());
        self::assertSame(['deploy.oac_policy', 'deploy.endpoint_protected', 'deploy.endpoint_still_public'], array_column($this->steps, 0), 'reverted: the endpoint is public again');
    }

    public function test_a_failure_before_the_switch_says_the_endpoint_is_still_public(): void
    {
        $this->aws->handler->append(
            self::endpoint('ApplicationManaged'),
            AwsMocks::error(LambdaWebException::class, 'GetResourcePolicy', 'AccessDeniedException', 403),
        );

        try {
            $this->protect();
            self::fail('the access error must stop the deploy');
        } catch (LambdaWebException $e) {
            self::assertSame('AccessDeniedException', $e->getAwsErrorCode(), 'the original error, with its key');
        }

        self::assertSame(['deploy.oac_policy', 'deploy.endpoint_still_public'], array_column($this->steps, 0));
        self::assertSame(['function' => 'shop-production'], $this->steps[1][1]);
    }

    public function test_a_failure_on_an_iam_endpoint_adds_no_still_public_line(): void
    {
        $this->aws->handler->append(self::endpoint('IamAuth'), AwsMocks::error(LambdaWebException::class, 'GetResourcePolicy', 'AccessDeniedException', 403));

        $this->expectException(LambdaWebException::class);
        try {
            $this->protect();
        } finally {
            self::assertSame(['deploy.oac_policy'], array_column($this->steps, 0));
        }
    }

    public function test_an_iam_endpoint_whose_policy_is_in_place_is_left_alone(): void
    {
        $statement = CloudFrontAccess::statement(self::ENDPOINT_ARN, self::DISTRIBUTION_ARN);
        // AWS may hand the statement back with its keys in another order.
        $reordered = array_reverse($statement, true);
        $this->aws->handler->append(self::endpoint('IamAuth'), self::policy([self::foreign(), $reordered]));

        $this->protect();

        self::assertSame(['GetWebFunctionEndpoint', 'GetResourcePolicy'], $this->aws->calls, 'a redeploy writes nothing');
        self::assertSame(['deploy.oac_policy'], array_column($this->steps, 0));
    }

    public function test_the_statement_is_added_to_a_policy_with_foreign_statements_using_its_revision(): void
    {
        $this->aws->handler->append(self::endpoint('IamAuth'), self::policy([self::foreign()]), new Result([]));

        $this->protect();

        self::assertSame(['GetWebFunctionEndpoint', 'GetResourcePolicy', 'PutResourcePolicy'], $this->aws->calls);
        self::assertSame(self::REVISION, $this->aws->params[2]['revisionId']);
        $statements = $this->putStatements(2);
        self::assertSame([self::foreign(), CloudFrontAccess::statement(self::ENDPOINT_ARN, self::DISTRIBUTION_ARN)], $statements);
        $policy = json_decode((string) $this->aws->params[2]['policy'], true);
        self::assertSame('kept', $policy['Id'] ?? null, 'the rest of the policy is kept');
    }

    public function test_a_stale_statement_for_another_distribution_is_replaced_not_duplicated(): void
    {
        $old = CloudFrontAccess::statement(self::ENDPOINT_ARN, 'arn:aws:cloudfront::111111111111:distribution/EOLD');
        $this->aws->handler->append(self::endpoint('IamAuth'), self::policy([$old, self::foreign()]), new Result([]));

        $this->protect();

        self::assertSame([self::foreign(), CloudFrontAccess::statement(self::ENDPOINT_ARN, self::DISTRIBUTION_ARN)], $this->putStatements(2));
    }

    public function test_a_concurrent_policy_change_is_read_again_and_merged(): void
    {
        $this->aws->handler->append(
            self::endpoint('IamAuth'),
            self::policy([]),
            AwsMocks::error(LambdaWebException::class, 'PutResourcePolicy', 'ConflictException', 409),
            self::policy([self::foreign()]),
            new Result([]),
        );

        $this->protect();

        self::assertSame(['GetWebFunctionEndpoint', 'GetResourcePolicy', 'PutResourcePolicy', 'GetResourcePolicy', 'PutResourcePolicy'], $this->aws->calls);
        self::assertSame([self::foreign(), CloudFrontAccess::statement(self::ENDPOINT_ARN, self::DISTRIBUTION_ARN)], $this->putStatements(4));
        self::assertSame(3.0, $this->sleeper->now() - 1_000_000.0, 'a pause before reading again: a conflict may also be a busy function');
    }

    public function test_revoke_removes_only_arcos_statement(): void
    {
        $ours = CloudFrontAccess::statement(self::ENDPOINT_ARN, self::DISTRIBUTION_ARN);
        $this->aws->handler->append(self::policy([$ours, self::foreign()]), new Result([]));

        self::assertTrue($this->access()->revoke(self::FUNCTION_ARN));

        self::assertSame(['GetResourcePolicy', 'PutResourcePolicy'], $this->aws->calls);
        self::assertSame(self::REVISION, $this->aws->params[1]['revisionId']);
        self::assertSame([self::foreign()], $this->putStatements(1));
    }

    public function test_revoke_deletes_a_policy_that_only_held_arcos_statement(): void
    {
        $this->aws->handler->append(self::policy([CloudFrontAccess::statement(self::ENDPOINT_ARN, self::DISTRIBUTION_ARN)]), new Result([]));

        self::assertTrue($this->access()->revoke(self::FUNCTION_ARN));

        self::assertSame(['GetResourcePolicy', 'DeleteResourcePolicy'], $this->aws->calls);
        self::assertSame(['resourceArn' => self::FUNCTION_ARN, 'revisionId' => self::REVISION], array_intersect_key($this->aws->params[1], ['resourceArn' => 1, 'revisionId' => 1]));
    }

    public function test_revoke_without_arcos_statement_changes_nothing(): void
    {
        $this->aws->handler->append(self::policy([self::foreign()]), self::noPolicy());

        self::assertFalse($this->access()->revoke(self::FUNCTION_ARN));
        self::assertFalse($this->access()->revoke(self::FUNCTION_ARN));

        self::assertSame(['GetResourcePolicy', 'GetResourcePolicy'], $this->aws->calls);
    }

    public function test_a_single_statement_written_as_an_object_is_kept(): void
    {
        $this->aws->handler->append(
            self::endpoint('IamAuth'),
            new Result(['policy' => json_encode(['Version' => '2012-10-17', 'Statement' => self::foreign()]), 'revisionId' => self::REVISION]),
            new Result([]),
        );

        $this->protect();

        self::assertSame([self::foreign(), CloudFrontAccess::statement(self::ENDPOINT_ARN, self::DISTRIBUTION_ARN)], $this->putStatements(2));
    }

    /** @return iterable<string, array{0: Result}> */
    public static function unreadablePolicies(): iterable
    {
        yield 'not JSON' => [new Result(['policy' => '{"Statement": [', 'revisionId' => self::REVISION])];
        yield 'a JSON list' => [new Result(['policy' => '[1, 2]', 'revisionId' => self::REVISION])];
        yield 'a scalar Statement' => [new Result(['policy' => '{"Version": "2012-10-17", "Statement": "everything"}', 'revisionId' => self::REVISION])];
        yield 'a scalar statement in the list' => [new Result(['policy' => json_encode(['Statement' => [self::foreign(), 'x']]), 'revisionId' => self::REVISION])];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unreadablePolicies')]
    public function test_a_policy_arco_cannot_read_is_never_written(Result $policy): void
    {
        $this->aws->handler->append(self::endpoint('ApplicationManaged'), $policy);

        try {
            $this->protect();
            self::fail('an unreadable policy must stop the deploy');
        } catch (DeployException $e) {
            self::assertSame('deploy.policy_unreadable', $e->key);
            self::assertSame(['arn' => self::FUNCTION_ARN], $e->params);
        }
        self::assertSame(['GetWebFunctionEndpoint', 'GetResourcePolicy'], $this->aws->calls, 'no write, and the endpoint keeps its auth');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unreadablePolicies')]
    public function test_revoke_never_writes_a_policy_it_cannot_read(Result $policy): void
    {
        $this->aws->handler->append($policy);

        $this->expectException(DeployException::class);
        try {
            $this->access()->revoke(self::FUNCTION_ARN);
        } finally {
            self::assertSame(['GetResourcePolicy'], $this->aws->calls);
        }
    }

    /** @return iterable<string, array{0: string, 1: string, 2: string}> */
    public static function malformedArns(): iterable
    {
        yield 'no function ARN' => ['', self::ENDPOINT_ARN, 'E123'];
        yield 'no account' => ['arn:aws:lambda:us-east-1::web-function/shop-production', 'arn:aws:lambda:us-east-1::web-function/shop-production/endpoint/default', 'E123'];
        yield 'not a web function' => ['arn:aws:lambda:us-east-1:111111111111:function:shop-production', self::ENDPOINT_ARN, 'E123'];
        yield 'no endpoint ARN' => [self::FUNCTION_ARN, '', 'E123'];
        yield 'an endpoint of another function' => [self::FUNCTION_ARN, 'arn:aws:lambda:us-east-1:111111111111:web-function/other/endpoint/default', 'E123'];
        yield 'an empty distribution id' => [self::FUNCTION_ARN, self::ENDPOINT_ARN, ''];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedArns')]
    public function test_a_malformed_arn_stops_before_any_policy_or_endpoint_change(string $functionArn, string $endpointArn, string $distributionId): void
    {
        $this->aws->handler->append(new Result(['functionArn' => $functionArn, 'endpointArn' => $endpointArn, 'authType' => 'ApplicationManaged', 'state' => 'Active']));

        try {
            $this->access()->protect(self::config(), $distributionId, 'd111.cloudfront.net', '/up', fn () => null);
            self::fail('a malformed ARN must stop the deploy');
        } catch (DeployException $e) {
            self::assertSame('deploy.access_arn_invalid', $e->key);
        }
        self::assertSame(['GetWebFunctionEndpoint'], $this->aws->calls);
    }

    public function test_the_partition_and_account_come_from_the_function_arn(): void
    {
        $function = 'arn:aws-cn:lambda:cn-north-1:333333333333:web-function/shop-production';
        $this->aws->handler->append(
            new Result(['functionArn' => $function, 'endpointArn' => "$function/endpoint/default", 'authType' => 'IamAuth', 'state' => 'Active']),
            self::noPolicy(),
            new Result([]),
        );

        $this->protect();

        self::assertSame('arn:aws-cn:cloudfront::333333333333:distribution/E123', $this->putStatements(2)[0]['Condition']['StringEquals']['aws:SourceArn']);
    }

    public function test_tenant_grant_preserves_standard_and_foreign_statements_on_an_iam_endpoint_without_a_tenant_domain(): void
    {
        $standard = CloudFrontAccess::statement(self::ENDPOINT_ARN, self::DISTRIBUTION_ARN);
        $this->aws->handler->append(self::endpoint('IamAuth'), self::policy([$standard, self::foreign()]), new Result([]));

        $this->protectTenant();

        $tenant = CloudFrontAccess::tenantStatement(self::ENDPOINT_ARN, self::DISTRIBUTION_ARN);
        self::assertSame('ArcoCloudFrontTenant', $tenant['Sid']);
        self::assertSame(['Sid' => 'ArcoCloudFrontTenant'] + $standard, $tenant);
        self::assertSame([$standard, self::foreign(), $tenant], $this->putStatements(2));
        self::assertSame(['GetWebFunctionEndpoint', 'GetResourcePolicy', 'PutResourcePolicy'], $this->aws->calls);
    }

    public function test_tenant_grant_is_idempotent_and_replaces_only_its_own_stale_statement(): void
    {
        $standard = CloudFrontAccess::statement(self::ENDPOINT_ARN, self::DISTRIBUTION_ARN);
        $tenant = CloudFrontAccess::tenantStatement(self::ENDPOINT_ARN, self::DISTRIBUTION_ARN);
        $old = CloudFrontAccess::tenantStatement(self::ENDPOINT_ARN, 'arn:aws:cloudfront::111111111111:distribution/EOLD');
        $this->aws->handler->append(self::endpoint('IamAuth'), self::policy([$standard, $tenant, self::foreign()]),
            self::endpoint('IamAuth'), self::policy([$old, $standard, self::foreign()]), new Result([]));

        $this->protectTenant();
        $this->protectTenant();

        self::assertSame([$standard, self::foreign(), $tenant], $this->putStatements(4));
    }

    public function test_revoke_each_grant_preserves_the_other_and_foreign_statements(): void
    {
        $standard = CloudFrontAccess::statement(self::ENDPOINT_ARN, self::DISTRIBUTION_ARN);
        $tenant = CloudFrontAccess::tenantStatement(self::ENDPOINT_ARN, self::DISTRIBUTION_ARN);
        $this->aws->handler->append(self::policy([$standard, $tenant, self::foreign()]), new Result([]),
            self::policy([$standard, $tenant, self::foreign()]), new Result([]));
        $access = $this->access();

        self::assertTrue($access->revoke(self::FUNCTION_ARN));
        self::assertTrue($access->revokeTenant(self::FUNCTION_ARN));
        self::assertSame([$tenant, self::foreign()], $this->putStatements(1));
        self::assertSame([$standard, self::foreign()], $this->putStatements(3));
    }

    public function test_public_tenant_endpoint_cannot_switch_without_an_actual_tenant_domain(): void
    {
        $this->aws->handler->append(self::endpoint('ApplicationManaged'));
        try {
            $this->protectTenant();
            self::fail('a public endpoint requires a tenant to check');
        } catch (DeployException $e) {
            self::assertSame('deploy.tenant_domains_unavailable', $e->key);
        }
        self::assertSame(['GetWebFunctionEndpoint'], $this->aws->calls);
        self::assertContains('deploy.endpoint_still_public', array_column($this->steps, 0));
    }

    /** @return iterable<string, array{0: Result}> */
    public static function invalidTenants(): iterable
    {
        yield 'different distribution' => [self::tenant('EOTHER')];
        yield 'disabled' => [self::tenant(enabled: false)];
        yield 'inactive domain' => [self::tenant(status: 'inactive')];
        yield 'different domain' => [new Result(['DistributionTenant' => ['DistributionId' => 'E123', 'Enabled' => true,
            'Domains' => [['Domain' => 'other.example.com', 'Status' => 'active']]]])];
        yield 'missing tenant id' => [new Result(['DistributionTenant' => ['DistributionId' => 'E123', 'Enabled' => true,
            'Domains' => [['Domain' => 'customer.example.com', 'Status' => 'active']]]])];
        yield 'empty tenant id' => [new Result(['DistributionTenant' => ['Id' => '', 'DistributionId' => 'E123', 'Enabled' => true,
            'Domains' => [['Domain' => 'customer.example.com', 'Status' => 'active']]]])];
        yield 'invalid tenant id' => [new Result(['DistributionTenant' => ['Id' => 42, 'DistributionId' => 'E123', 'Enabled' => true,
            'Domains' => [['Domain' => 'customer.example.com', 'Status' => 'active']]]])];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidTenants')]
    public function test_public_tenant_endpoint_rejects_an_unusable_tenant_before_changes(Result $tenant): void
    {
        $this->aws->handler->append(self::endpoint('ApplicationManaged'), $tenant);
        try {
            $this->protectTenant('customer.example.com');
            self::fail('tenant is not usable');
        } catch (DeployException $e) {
            self::assertSame('deploy.tenant_domains_unavailable', $e->key);
        }
        self::assertSame(['GetWebFunctionEndpoint', 'GetDistributionTenantByDomain'], $this->aws->calls);
    }

    public function test_public_tenant_endpoint_switches_and_checks_the_real_tenant(): void
    {
        $this->aws->handler->append(self::endpoint('ApplicationManaged'), self::tenant(), self::tenantDns(), self::noPolicy(), new Result([]),
            new Result(['Distribution' => ['Id' => 'E123', 'Status' => 'Deployed']]),
            self::endpoint('ApplicationManaged'), new Result([]), self::endpoint('IamAuth'));
        $this->http->append(new Response(200));

        $this->protectTenant('customer.example.com');

        self::assertSame('customer.example.com', $this->aws->params[1]['Domain']);
        self::assertSame(['Identifier' => 'DTENANT', 'Domain' => 'customer.example.com'], array_intersect_key($this->aws->params[2], ['Identifier' => 1, 'Domain' => 1]));
        self::assertSame('IamAuth', $this->aws->params[7]['authType']);
        self::assertSame('https://customer.example.com/up', (string) $this->http->getLastRequest()?->getUri());
        self::assertSame('ArcoCloudFrontTenant', $this->putStatements(4)[0]['Sid']);
    }

    public function test_a_failed_tenant_check_never_makes_the_endpoint_public_again(): void
    {
        $this->aws->handler->append(self::endpoint('ApplicationManaged'), self::tenant(), self::tenantDns(), self::noPolicy(), new Result([]),
            new Result(['Distribution' => ['Id' => 'E123', 'Status' => 'Deployed']]),
            self::endpoint('ApplicationManaged'), new Result([]), self::endpoint('IamAuth'));
        $this->http->append(...array_fill(0, 13, new Response(403)));

        try {
            $this->protectTenant('customer.example.com');
            self::fail('unverified endpoint must fail the deploy');
        } catch (DeployException $e) {
            self::assertSame('deploy.timeout', $e->key);
        }
        self::assertSame(['IamAuth'], array_values(array_filter(array_column($this->aws->params, 'authType'))));
        self::assertNotContains('deploy.endpoint_still_public', array_column($this->steps, 0));
    }

    public function test_a_tenant_policy_conflict_is_read_again_without_losing_standard_or_foreign_grants(): void
    {
        $standard = CloudFrontAccess::statement(self::ENDPOINT_ARN, self::DISTRIBUTION_ARN);
        $this->aws->handler->append(self::endpoint('IamAuth'), self::policy([$standard]),
            AwsMocks::error(LambdaWebException::class, 'PutResourcePolicy', 'ConflictException', 409),
            self::policy([$standard, self::foreign()]), new Result([]));

        $this->protectTenant();

        self::assertSame([$standard, self::foreign(), CloudFrontAccess::tenantStatement(self::ENDPOINT_ARN, self::DISTRIBUTION_ARN)], $this->putStatements(4));
        self::assertSame(3.0, $this->sleeper->now() - 1_000_000.0);
    }

    public function test_revoking_the_last_tenant_grant_deletes_only_that_policy(): void
    {
        $this->aws->handler->append(self::policy([CloudFrontAccess::tenantStatement(self::ENDPOINT_ARN, self::DISTRIBUTION_ARN)]), new Result([]), self::noPolicy());
        $access = $this->access();

        self::assertTrue($access->revokeTenant(self::FUNCTION_ARN));
        self::assertFalse($access->revokeTenant(self::FUNCTION_ARN));
        self::assertSame(['GetResourcePolicy', 'DeleteResourcePolicy', 'GetResourcePolicy'], $this->aws->calls);
    }

    /** @return iterable<string, array{0: string}> */
    public static function invalidTenantDomains(): iterable
    {
        yield 'empty' => [''];
        yield 'URL' => ['https://customer.example.com'];
        yield 'port' => ['customer.example.com:443'];
        yield 'wildcard' => ['*.example.com'];
        yield 'routing endpoint with path' => ['customer.example.com/other'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidTenantDomains')]
    public function test_public_tenant_endpoint_rejects_an_invalid_hostname_before_calling_cloudfront(string $domain): void
    {
        $this->aws->handler->append(self::endpoint('ApplicationManaged'));
        try {
            $this->protectTenant($domain);
            self::fail('invalid domain must not be used');
        } catch (DeployException $e) {
            self::assertSame('deploy.tenant_domains_unavailable', $e->key);
        }
        self::assertSame(['GetWebFunctionEndpoint'], $this->aws->calls);
    }

    public function test_a_tenant_check_error_after_switching_does_not_restore_public_access(): void
    {
        $this->aws->handler->append(self::endpoint('ApplicationManaged'), self::tenant(), self::tenantDns(), self::noPolicy(), new Result([]),
            new Result(['Distribution' => ['Id' => 'E123', 'Status' => 'Deployed']]), self::endpoint('ApplicationManaged'), new Result([]),
            AwsMocks::error(LambdaWebException::class, 'GetWebFunctionEndpoint', 'ValidationException', 400));

        try {
            $this->protectTenant('customer.example.com');
            self::fail('the endpoint error must stop the deploy');
        } catch (LambdaWebException $e) {
            self::assertSame('ValidationException', $e->getAwsErrorCode());
        }
        self::assertSame(['IamAuth'], array_values(array_filter(array_column($this->aws->params, 'authType'))));
        self::assertNotContains('deploy.endpoint_still_public', array_column($this->steps, 0));
    }

    /** @return iterable<string, array{0: Result}> */
    public static function invalidTenantDns(): iterable
    {
        yield 'invalid' => [self::tenantDns('invalid-configuration')];
        yield 'unknown' => [self::tenantDns('unknown-configuration')];
        yield 'wrong domain' => [self::tenantDns(domain: 'other.example.com')];
        yield 'missing' => [new Result([])];
        yield 'conflicting statuses' => [new Result(['DnsConfigurationList' => [
            ['Domain' => 'customer.example.com', 'Status' => 'valid-configuration'],
            ['Domain' => 'customer.example.com', 'Status' => 'invalid-configuration'],
        ]])];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidTenantDns')]
    public function test_public_tenant_endpoint_rejects_dns_that_does_not_point_to_the_tenant(Result $dns): void
    {
        $this->aws->handler->append(self::endpoint('ApplicationManaged'), self::tenant(), $dns);
        try {
            $this->protectTenant('customer.example.com');
            self::fail('unverified DNS must not be used');
        } catch (DeployException $e) {
            self::assertSame('deploy.tenant_domains_unavailable', $e->key);
        }
        self::assertSame(['GetWebFunctionEndpoint', 'GetDistributionTenantByDomain', 'VerifyDnsConfiguration'], $this->aws->calls);
    }

    public function test_a_dns_verification_error_stops_before_policy_or_endpoint_changes(): void
    {
        $this->aws->handler->append(self::endpoint('ApplicationManaged'), self::tenant(),
            AwsMocks::error(CloudFrontException::class, 'VerifyDnsConfiguration', 'AccessDenied', 403));

        try {
            $this->protectTenant('customer.example.com');
            self::fail('DNS verification failure must stop the deploy');
        } catch (CloudFrontException $e) {
            self::assertSame('AccessDenied', $e->getAwsErrorCode());
        }
        self::assertSame(['GetWebFunctionEndpoint', 'GetDistributionTenantByDomain', 'VerifyDnsConfiguration'], $this->aws->calls);
        self::assertContains('deploy.endpoint_still_public', array_column($this->steps, 0));
    }
}
