<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy;

use Aws\Ec2\Exception\Ec2Exception;
use Aws\Result;
use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Deploy\DeployException;
use Multiapi\Arco\Deploy\EndpointPlan;
use Multiapi\Arco\Deploy\GatewayEndpoints;
use PHPUnit\Framework\TestCase;

final class GatewayEndpointsTest extends TestCase
{
    /** @param array<int|string, string> $endpoints as vpc() takes them: 's3' or 's3' => 'vpce-...' */
    private function config(array $endpoints = ['s3'], array $subnets = ['subnet-a', 'subnet-b']): AppConfig
    {
        return ConfigResolver::resolve(App::make('shop')->region('us-east-1')
            ->environment('production', fn ($e) => $e->vpc(subnets: $subnets, securityGroups: ['sg-1'], endpoints: $endpoints))->toArray(), 'production');
    }

    private function subject(AwsMocks $mocks): GatewayEndpoints
    {
        return new GatewayEndpoints($mocks->clients()->ec2(), AwsMocks::noSleep(), 'us-east-1');
    }

    private static function subnets(string ...$vpcs): Result
    {
        return new Result(['Subnets' => array_map(fn (string $vpc, int $i) => ['SubnetId' => 'subnet-' . $i, 'VpcId' => $vpc], $vpcs, array_keys($vpcs))]);
    }

    /** @param list<array{string, list<string>}> $tables route table id and the subnets it is explicitly associated with ('main' marks the main table) */
    private static function tables(array $tables): Result
    {
        return new Result(['RouteTables' => array_map(fn (array $t) => [
            'RouteTableId' => $t[0],
            'Associations' => array_map(fn (string $s) => $s === 'main' ? ['Main' => true] : ['SubnetId' => $s, 'Main' => false], $t[1]),
        ], $tables)]);
    }

    /** @param list<string> $tables @param array<string, string> $tags */
    private static function endpoint(string $id, array $tables, array $tags = [], string $state = 'available', string $type = 'Gateway', string $service = 'com.amazonaws.us-east-1.s3', string $vpc = 'vpc-1'): array
    {
        return ['VpcEndpointId' => $id, 'State' => $state, 'VpcEndpointType' => $type, 'ServiceName' => $service, 'VpcId' => $vpc, 'RouteTableIds' => $tables,
            'Tags' => array_map(fn (string $k, string $v) => ['Key' => $k, 'Value' => $v], array_keys($tags), $tags)];
    }

    /** @param list<array<string, mixed>> $endpoints */
    private static function endpoints(array $endpoints): Result
    {
        return new Result(['VpcEndpoints' => $endpoints]);
    }

    private const ARCO = ['arco:managed' => 'gateway-endpoint'];

    /** The usual network: subnet-a on rtb-a, subnet-b on rtb-b. */
    private function network(AwsMocks $mocks): void
    {
        $mocks->handler->append(self::subnets('vpc-1', 'vpc-1'));
        $mocks->handler->append(self::tables([['rtb-a', ['subnet-a']], ['rtb-b', ['subnet-b']], ['rtb-main', ['main']]]));
    }

    private function plan(AwsMocks $mocks, ?AppConfig $config = null): EndpointPlan
    {
        $plans = $this->subject($mocks)->plan($config ?? $this->config());
        self::assertCount(1, $plans);
        return $plans[0];
    }

    public function test_covered_route_tables_need_nothing(): void
    {
        $mocks = new AwsMocks();
        $this->network($mocks);
        $mocks->handler->append(self::endpoints([self::endpoint('vpce-user', ['rtb-a', 'rtb-b'])]));

        $plan = $this->plan($mocks);

        self::assertSame(EndpointPlan::NONE, $plan->action);
        self::assertNull($plan->target);
        self::assertFalse($plan->targetIsArco);
        self::assertSame(['rtb-a', 'rtb-b'], $plan->routeTables);
        self::assertSame([], $plan->missing);
        self::assertSame('vpc-1', $plan->vpcId);
        self::assertSame([['id' => 'vpce-user', 'arco' => false, 'uses' => 0]], $plan->covering);
        self::assertSame(['DescribeSubnets', 'DescribeRouteTables', 'DescribeVpcEndpoints'], $mocks->calls);
        self::assertSame(['subnet-a', 'subnet-b'], $mocks->params[0]['SubnetIds']);
        self::assertSame([['Name' => 'vpc-id', 'Values' => ['vpc-1']]], $mocks->params[1]['Filters']);
        self::assertSame([
            ['Name' => 'vpc-id', 'Values' => ['vpc-1']],
            ['Name' => 'service-name', 'Values' => ['com.amazonaws.us-east-1.s3']],
            ['Name' => 'vpc-endpoint-type', 'Values' => ['Gateway']],
        ], $mocks->params[2]['Filters']);
    }

    public function test_main_route_table_is_used_for_a_subnet_without_association(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(self::subnets('vpc-1', 'vpc-1'));
        $mocks->handler->append(self::tables([['rtb-a', ['subnet-a']], ['rtb-main', ['main']]]));
        $mocks->handler->append(self::endpoints([]));

        $plan = $this->plan($mocks);

        self::assertSame(['rtb-a', 'rtb-main'], $plan->routeTables);
    }

    public function test_shared_route_table_appears_once(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(self::subnets('vpc-1', 'vpc-1'));
        $mocks->handler->append(self::tables([['rtb-shared', ['subnet-a', 'subnet-b']], ['rtb-main', ['main']]]));
        $mocks->handler->append(self::endpoints([]));

        self::assertSame(['rtb-shared'], $this->plan($mocks)->routeTables);
    }

    public function test_named_endpoint_gets_missing_route_tables(): void
    {
        $mocks = new AwsMocks();
        $this->network($mocks);
        $mocks->handler->append(self::endpoints([self::endpoint('vpce-mine', ['rtb-a'])]));

        $plan = $this->plan($mocks, $this->config(['s3' => 'vpce-mine']));

        self::assertSame(EndpointPlan::ASSOCIATE, $plan->action);
        self::assertSame('vpce-mine', $plan->target);
        self::assertFalse($plan->targetIsArco);
        self::assertSame(['rtb-b'], $plan->missing);
        self::assertSame(['DescribeSubnets', 'DescribeRouteTables', 'DescribeVpcEndpoints'], $mocks->calls);
    }

    public function test_named_endpoint_skips_tables_covered_by_another_endpoint(): void
    {
        $mocks = new AwsMocks();
        $this->network($mocks);
        $mocks->handler->append(self::endpoints([self::endpoint('vpce-mine', ['rtb-a']), self::endpoint('vpce-u', ['rtb-b'])]));

        $plan = $this->plan($mocks, $this->config(['s3' => 'vpce-mine']));

        self::assertSame(EndpointPlan::NONE, $plan->action);
        self::assertSame('vpce-mine', $plan->target);
        self::assertSame([], $plan->missing);

        $mocks = new AwsMocks();
        $mocks->handler->append(self::subnets('vpc-1', 'vpc-1', 'vpc-1'));
        $mocks->handler->append(self::tables([['rtb-a', ['subnet-a']], ['rtb-b', ['subnet-b']], ['rtb-c', ['subnet-c']]]));
        $mocks->handler->append(self::endpoints([self::endpoint('vpce-mine', ['rtb-a']), self::endpoint('vpce-u', ['rtb-b'])]));

        $plan = $this->plan($mocks, $this->config(['s3' => 'vpce-mine'], ['subnet-a', 'subnet-b', 'subnet-c']));

        self::assertSame(EndpointPlan::ASSOCIATE, $plan->action);
        self::assertSame('vpce-mine', $plan->target);
        self::assertSame(['rtb-c'], $plan->missing);
    }

    public function test_named_arco_endpoint_is_treated_as_arco(): void
    {
        $mocks = new AwsMocks();
        $this->network($mocks);
        $mocks->handler->append(self::endpoints([self::endpoint('vpce-mine', ['rtb-a'], self::ARCO)]));

        $plan = $this->plan($mocks, $this->config(['s3' => 'vpce-mine']));

        self::assertSame('vpce-mine', $plan->target);
        self::assertTrue($plan->targetIsArco);
    }

    /** @return iterable<string, array{array<string, mixed>|null, string}> */
    public static function badNamedEndpoints(): iterable
    {
        yield 'unknown' => [null, 'deploy.endpoint_not_found'];
        yield 'deleting' => [self::endpoint('vpce-mine', [], [], 'deleting'), 'deploy.endpoint_not_found'];
        yield 'interface' => [self::endpoint('vpce-mine', [], [], 'available', 'Interface'), 'deploy.endpoint_wrong_type'];
        yield 'other service' => [self::endpoint('vpce-mine', [], [], 'available', 'Gateway', 'com.amazonaws.us-east-1.dynamodb'), 'deploy.endpoint_wrong_service'];
        yield 'other vpc' => [self::endpoint('vpce-mine', [], [], 'available', 'Gateway', 'com.amazonaws.us-east-1.s3', 'vpc-2'), 'deploy.endpoint_wrong_vpc'];
    }

    /** @param array<string, mixed>|null $endpoint */
    #[\PHPUnit\Framework\Attributes\DataProvider('badNamedEndpoints')]
    public function test_named_endpoint_must_exist_be_gateway_service_and_vpc(?array $endpoint, string $key): void
    {
        $mocks = new AwsMocks();
        $this->network($mocks);
        $mocks->handler->append(self::endpoints([]));
        $mocks->handler->append($endpoint === null
            ? AwsMocks::error(Ec2Exception::class, 'DescribeVpcEndpoints', 'InvalidVpcEndpointId.NotFound', 400)
            : self::endpoints([$endpoint]));

        try {
            $this->plan($mocks, $this->config(['s3' => 'vpce-mine']));
            self::fail('expected a DeployException');
        } catch (DeployException $e) {
            self::assertSame($key, $e->key);
            self::assertSame(['service' => 's3', 'endpoint' => 'vpce-mine'], $e->params);
        }
        self::assertSame(['DescribeSubnets', 'DescribeRouteTables', 'DescribeVpcEndpoints', 'DescribeVpcEndpoints'], $mocks->calls);
        self::assertSame(['vpce-mine'], $mocks->params[3]['VpcEndpointIds']);
    }

    public function test_named_endpoint_missing_from_an_empty_answer_is_not_found(): void
    {
        $mocks = new AwsMocks();
        $this->network($mocks);
        $mocks->handler->append(self::endpoints([]), self::endpoints([]));

        $this->expectException(DeployException::class);
        $this->expectExceptionMessage('deploy.endpoint_not_found');
        $this->plan($mocks, $this->config(['s3' => 'vpce-mine']));
    }

    public function test_arco_endpoint_in_vpc_gets_missing_route_tables(): void
    {
        $mocks = new AwsMocks();
        $this->network($mocks);
        $mocks->handler->append(self::endpoints([
            self::endpoint('vpce-b', ['rtb-b'], self::ARCO + ['arco:uses:blog/production' => '1']),
            self::endpoint('vpce-a', ['rtb-other'], self::ARCO),
        ]));

        $plan = $this->plan($mocks);

        self::assertSame(EndpointPlan::ASSOCIATE, $plan->action);
        self::assertSame('vpce-a', $plan->target);
        self::assertTrue($plan->targetIsArco);
        self::assertSame(['rtb-a'], $plan->missing);
        self::assertSame([['id' => 'vpce-b', 'arco' => true, 'uses' => 1]], $plan->covering);
    }

    public function test_arco_endpoint_covering_everything_is_still_a_target_for_the_usage_tag(): void
    {
        $mocks = new AwsMocks();
        $this->network($mocks);
        $mocks->handler->append(self::endpoints([self::endpoint('vpce-arco', ['rtb-a', 'rtb-b'], self::ARCO + ['arco:uses:a/b' => '1', 'arco:uses:c/d' => '1'])]));

        $plan = $this->plan($mocks);

        self::assertSame(EndpointPlan::NONE, $plan->action);
        self::assertSame('vpce-arco', $plan->target);
        self::assertTrue($plan->targetIsArco);
        self::assertSame([['id' => 'vpce-arco', 'arco' => true, 'uses' => 2]], $plan->covering);
    }

    public function test_no_endpoint_means_create(): void
    {
        $mocks = new AwsMocks();
        $this->network($mocks);
        $mocks->handler->append(self::endpoints([]));

        $plan = $this->plan($mocks);

        self::assertSame(EndpointPlan::CREATE, $plan->action);
        self::assertNull($plan->target);
        self::assertSame(['rtb-a', 'rtb-b'], $plan->missing);
        self::assertSame([], $plan->userEndpoints);
    }

    public function test_only_user_endpoints_means_decide(): void
    {
        $mocks = new AwsMocks();
        $this->network($mocks);
        $mocks->handler->append(self::endpoints([self::endpoint('vpce-u1', ['rtb-other'])]));

        $plan = $this->plan($mocks);

        self::assertSame(EndpointPlan::DECIDE, $plan->action);
        self::assertNull($plan->target);
        self::assertSame(['vpce-u1'], $plan->userEndpoints);
        self::assertSame(['rtb-a', 'rtb-b'], $plan->missing);
        self::assertSame([], $plan->covering);
    }

    public function test_pending_counts_deleting_is_ignored(): void
    {
        $mocks = new AwsMocks();
        $this->network($mocks);
        $mocks->handler->append(self::endpoints([
            self::endpoint('vpce-gone', ['rtb-a', 'rtb-b'], self::ARCO, 'deleting'),
            self::endpoint('vpce-failed', ['rtb-a', 'rtb-b'], self::ARCO, 'failed'),
            self::endpoint('vpce-new', ['rtb-a', 'rtb-b'], self::ARCO, 'pending'),
        ]));

        $plan = $this->plan($mocks);

        self::assertSame(EndpointPlan::NONE, $plan->action);
        self::assertSame('vpce-new', $plan->target);
        self::assertSame([['id' => 'vpce-new', 'arco' => true, 'uses' => 0]], $plan->covering);

        $mocks = new AwsMocks();
        $this->network($mocks);
        $mocks->handler->append(self::endpoints([self::endpoint('vpce-gone', ['rtb-a', 'rtb-b'], self::ARCO, 'deleting')]));
        self::assertSame(EndpointPlan::CREATE, $this->plan($mocks)->action);
    }

    public function test_subnets_in_two_vpcs_fail(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(self::subnets('vpc-1', 'vpc-2'));

        try {
            $this->plan($mocks);
            self::fail('expected a DeployException');
        } catch (DeployException $e) {
            self::assertSame('deploy.endpoint_vpc_mixed', $e->key);
        }
        self::assertSame(['DescribeSubnets'], $mocks->calls);
    }

    public function test_plans_follow_the_declaration_order_of_the_services(): void
    {
        $mocks = new AwsMocks();
        $this->network($mocks);
        $mocks->handler->append(self::endpoints([]), self::endpoints([self::endpoint('vpce-d', ['rtb-a', 'rtb-b'], [], 'available', 'Gateway', 'com.amazonaws.us-east-1.dynamodb')]));

        $plans = $this->subject($mocks)->plan($this->config(['dynamodb', 's3']));

        self::assertSame(['dynamodb', 's3'], array_map(fn (EndpointPlan $p) => $p->service, $plans));
        self::assertSame(['DescribeSubnets', 'DescribeRouteTables', 'DescribeVpcEndpoints', 'DescribeVpcEndpoints'], $mocks->calls);
        self::assertSame('com.amazonaws.us-east-1.dynamodb', $mocks->params[2]['Filters'][1]['Values'][0]);
    }

    public function test_usage_tag(): void
    {
        self::assertSame('arco:uses:shop/production', GatewayEndpoints::usageTag($this->config()));
    }

    public function test_config_line_and_command(): void
    {
        $plan = new EndpointPlan('s3', 'vpc-1', ['rtb-a', 'rtb-b'], ['rtb-b'], EndpointPlan::ASSOCIATE, 'vpce-0abc', false, [], []);

        self::assertSame("endpoints: ['s3' => 'vpce-0abc']", $plan->configLine());
        self::assertSame('aws ec2 modify-vpc-endpoint --region us-east-1 --vpc-endpoint-id vpce-0abc --add-route-table-ids rtb-b', $plan->awsCommand('vpce-0abc', 'us-east-1'));
    }

    public function test_the_answer_to_decide_becomes_an_associate_or_a_skip(): void
    {
        $plan = new EndpointPlan('s3', 'vpc-1', ['rtb-a'], ['rtb-a'], EndpointPlan::DECIDE, null, false, ['vpce-u1'], []);

        $associate = $plan->withAssociate('vpce-u1');
        self::assertSame(EndpointPlan::ASSOCIATE, $associate->action);
        self::assertSame('vpce-u1', $associate->target);
        self::assertFalse($associate->targetIsArco);

        $skip = $plan->withSkip();
        self::assertSame(EndpointPlan::NONE, $skip->action);
        self::assertNull($skip->target);
        self::assertSame(['rtb-a'], $skip->missing);
    }
}
