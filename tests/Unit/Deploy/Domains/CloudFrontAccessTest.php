<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy\Domains;

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
}
