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
use Multiapi\Arco\Deploy\Ownership;
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
    private const OWN = ['arco:app' => 'shop', 'arco:environment' => 'production'];
    private const USE = 'arco:uses:shop/production';

    /** @param list<array{string, array<string, string|int>}> $steps */
    private function recorder(array &$steps): \Closure
    {
        return function (string $key, array $params = []) use (&$steps): void {
            $steps[] = [$key, $params];
        };
    }

    /** @return array<string, string> */
    private static function tagParams(array $tags): array
    {
        $out = [];
        foreach ($tags as $tag) {
            $out[$tag['Key']] = $tag['Value'] ?? null;
        }
        return $out;
    }

    private function apply(AwsMocks $mocks, EndpointPlan $plan, array &$steps, ?AppConfig $config = null): array
    {
        $config ??= $this->config();
        return $this->subject($mocks)->apply($config, $plan, Ownership::tags($config), $this->recorder($steps));
    }

    private static function makePlan(string $action, array $missing, ?string $target = null, bool $arco = false, array $covering = []): EndpointPlan
    {
        return new EndpointPlan('s3', 'vpc-1', ['rtb-a', 'rtb-b'], $missing, $action, $target, $arco, [], $covering);
    }

    public function test_create_tags_waits_and_sets_usage(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(new Result(['VpcEndpoint' => ['VpcEndpointId' => 'vpce-new']]));
        $mocks->handler->append(self::endpoints([self::endpoint('vpce-new', ['rtb-a', 'rtb-b'], self::ARCO, 'pending')]));
        $mocks->handler->append(self::endpoints([self::endpoint('vpce-new', ['rtb-a', 'rtb-b'], self::ARCO, 'available')]));
        $steps = [];

        $ids = $this->apply($mocks, self::makePlan(EndpointPlan::CREATE, ['rtb-a', 'rtb-b']), $steps);

        self::assertSame(['vpce-new'], $ids);
        self::assertSame(['CreateVpcEndpoint', 'DescribeVpcEndpoints', 'DescribeVpcEndpoints'], $mocks->calls);
        $create = $mocks->params[0];
        self::assertSame('Gateway', $create['VpcEndpointType']);
        self::assertSame('vpc-1', $create['VpcId']);
        self::assertSame('com.amazonaws.us-east-1.s3', $create['ServiceName']);
        self::assertSame(['rtb-a', 'rtb-b'], $create['RouteTableIds']);
        self::assertSame('vpc-endpoint', $create['TagSpecifications'][0]['ResourceType']);
        self::assertSame(['arco:app' => 'shop', 'arco:environment' => 'production', 'arco:managed' => 'gateway-endpoint', self::USE => '1'], self::tagParams($create['TagSpecifications'][0]['Tags']));
        self::assertSame(['vpce-new'], $mocks->params[1]['VpcEndpointIds']);
        self::assertSame([['deploy.endpoint_created', ['service' => 's3', 'endpoint' => 'vpce-new', 'route_tables' => 'rtb-a, rtb-b']]], $steps);
    }

    public function test_create_that_never_becomes_available_times_out(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(new Result(['VpcEndpoint' => ['VpcEndpointId' => 'vpce-new']]));
        for ($i = 0; $i < 70; $i++) {
            $mocks->handler->append(self::endpoints([self::endpoint('vpce-new', [], self::ARCO, 'pending')]));
        }
        $steps = [];

        try {
            $this->apply($mocks, self::makePlan(EndpointPlan::CREATE, ['rtb-a']), $steps);
            self::fail('expected a timeout');
        } catch (DeployException $e) {
            self::assertSame('deploy.endpoint_timeout', $e->key);
        }
    }

    public function test_associate_user_endpoint_adds_no_tag(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(new Result([]));
        $steps = [];

        $ids = $this->apply($mocks, self::makePlan(EndpointPlan::ASSOCIATE, ['rtb-b'], 'vpce-user', false, [['id' => 'vpce-user', 'arco' => false, 'uses' => 0]]), $steps);

        self::assertSame([], $ids);
        self::assertSame(['ModifyVpcEndpoint'], $mocks->calls);
        self::assertSame('vpce-user', $mocks->params[0]['VpcEndpointId']);
        self::assertSame(['rtb-b'], $mocks->params[0]['AddRouteTableIds']);
        self::assertSame([['deploy.endpoint_routes_added', ['service' => 's3', 'endpoint' => 'vpce-user', 'route_tables' => 'rtb-b']]], $steps);
    }

    public function test_associate_arco_endpoint_adds_usage_tag(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(self::endpoints([self::endpoint('vpce-arco', ['rtb-a'], self::ARCO + ['arco:uses:other/x' => '1'])]));
        $mocks->handler->append(new Result([]));
        $mocks->handler->append(new Result([]));
        $steps = [];

        $ids = $this->apply($mocks, self::makePlan(EndpointPlan::ASSOCIATE, ['rtb-b'], 'vpce-arco', true), $steps);

        self::assertSame(['vpce-arco'], $ids);
        self::assertSame(['DescribeVpcEndpoints', 'CreateTags', 'ModifyVpcEndpoint'], $mocks->calls, 'the usage tag first: a release in between finds the endpoint in use');
        self::assertSame(['vpce-arco'], $mocks->params[1]['Resources']);
        self::assertSame([['Key' => self::USE, 'Value' => '1']], $mocks->params[1]['Tags']);
        self::assertSame('deploy.endpoint_routes_added', $steps[0][0]);
    }

    public function test_none_with_arco_target_only_tags_usage_when_missing(): void
    {
        $covering = [['id' => 'vpce-arco', 'arco' => true, 'uses' => 1]];
        $mocks = new AwsMocks();
        $mocks->handler->append(self::endpoints([self::endpoint('vpce-arco', ['rtb-a', 'rtb-b'], self::ARCO + [self::USE => '1'])]));
        $steps = [];
        $ids = $this->apply($mocks, self::makePlan(EndpointPlan::NONE, [], 'vpce-arco', true, $covering), $steps);
        self::assertSame(['vpce-arco'], $ids);
        self::assertSame(['DescribeVpcEndpoints'], $mocks->calls);
        self::assertSame([['deploy.endpoint_shared', ['service' => 's3', 'endpoint' => 'vpce-arco']]], $steps, 'a deploy that changes nothing still says which endpoint serves it');

        $mocks = new AwsMocks();
        $mocks->handler->append(self::endpoints([self::endpoint('vpce-arco', ['rtb-a', 'rtb-b'], self::ARCO)]));
        $mocks->handler->append(new Result([]));
        $this->apply($mocks, self::makePlan(EndpointPlan::NONE, [], 'vpce-arco', true, $covering), $steps);
        self::assertSame(['DescribeVpcEndpoints', 'CreateTags'], $mocks->calls);
    }

    public function test_two_arco_endpoints_covering_one_environment_are_both_tagged_and_returned(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(self::endpoints([
            self::endpoint('vpce-b', ['rtb-b'], self::ARCO),
            self::endpoint('vpce-a', ['rtb-a'], self::ARCO),
        ]));
        $mocks->handler->append(new Result([]));
        $steps = [];

        $ids = $this->apply($mocks, self::makePlan(EndpointPlan::NONE, [], 'vpce-b', true, [
            ['id' => 'vpce-b', 'arco' => true, 'uses' => 0],
            ['id' => 'vpce-a', 'arco' => true, 'uses' => 0],
        ]), $steps);

        self::assertSame(['vpce-a', 'vpce-b'], $ids);
        self::assertSame(['DescribeVpcEndpoints', 'CreateTags'], $mocks->calls);
        self::assertEqualsCanonicalizing(['vpce-a', 'vpce-b'], $mocks->params[1]['Resources']);
        self::assertSame([
            ['deploy.endpoint_shared', ['service' => 's3', 'endpoint' => 'vpce-a']],
            ['deploy.endpoint_shared', ['service' => 's3', 'endpoint' => 'vpce-b']],
        ], $steps);
    }

    public function test_reused_user_endpoint_reports(): void
    {
        $mocks = new AwsMocks();
        $steps = [];

        $ids = $this->apply($mocks, self::makePlan(EndpointPlan::NONE, [], 'vpce-user', false, [['id' => 'vpce-user', 'arco' => false, 'uses' => 0]]), $steps);

        self::assertSame([], $ids);
        self::assertSame([], $mocks->calls);
        self::assertSame([['deploy.endpoint_reused', ['service' => 's3', 'endpoint' => 'vpce-user']]], $steps);
    }

    public function test_a_plan_to_decide_cannot_be_applied(): void
    {
        $this->expectException(\LogicException::class);
        $steps = [];
        $this->apply(new AwsMocks(), self::makePlan(EndpointPlan::DECIDE, ['rtb-a']), $steps);
    }

    public function test_conflict_is_retried_once_after_replanning(): void
    {
        $conflict = fn () => AwsMocks::error(Ec2Exception::class, 'CreateVpcEndpoint', 'RouteAlreadyExists', 400, 'Route table rtb-a already has a route for the prefix list');
        $mocks = new AwsMocks();
        $mocks->handler->append($conflict());
        $this->network($mocks);
        $mocks->handler->append(self::endpoints([self::endpoint('vpce-other', ['rtb-a', 'rtb-b'], self::ARCO)]));
        $mocks->handler->append(self::endpoints([self::endpoint('vpce-other', ['rtb-a', 'rtb-b'], self::ARCO)]));
        $mocks->handler->append(new Result([]));
        $steps = [];

        $ids = $this->apply($mocks, self::makePlan(EndpointPlan::CREATE, ['rtb-a', 'rtb-b']), $steps);

        self::assertSame(['vpce-other'], $ids);
        self::assertSame(['CreateVpcEndpoint', 'DescribeSubnets', 'DescribeRouteTables', 'DescribeVpcEndpoints', 'DescribeVpcEndpoints', 'CreateTags'], $mocks->calls);
    }

    public function test_a_user_endpoint_that_hits_a_route_conflict_is_not_retried_when_the_replan_needs_a_decision(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(AwsMocks::error(Ec2Exception::class, 'ModifyVpcEndpoint', 'RouteAlreadyExists', 400, 'Route table rtb-b already has a route for the prefix list'));
        $this->network($mocks);
        $mocks->handler->append(self::endpoints([self::endpoint('vpce-user', ['rtb-a'])]));
        $steps = [];
        $decided = (new EndpointPlan('s3', 'vpc-1', ['rtb-a', 'rtb-b'], ['rtb-b'], EndpointPlan::DECIDE, null, false, ['vpce-user'], []))->withAssociate('vpce-user');

        try {
            $this->apply($mocks, $decided, $steps);
            self::fail('expected a conflict');
        } catch (DeployException $e) {
            self::assertSame('deploy.endpoint_conflict', $e->key);
        }

        self::assertSame(['ModifyVpcEndpoint', 'DescribeSubnets', 'DescribeRouteTables', 'DescribeVpcEndpoints'], $mocks->calls, 'no second ModifyVpcEndpoint');
        self::assertSame([], $steps);
    }

    public function test_a_create_that_conflicts_because_a_user_endpoint_now_covers_the_tables_reuses_it(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(AwsMocks::error(Ec2Exception::class, 'CreateVpcEndpoint', 'RouteAlreadyExists', 400, 'Route table rtb-a already has a route for the prefix list'));
        $this->network($mocks);
        $mocks->handler->append(self::endpoints([self::endpoint('vpce-user', ['rtb-a', 'rtb-b'])]));
        $steps = [];

        $ids = $this->apply($mocks, self::makePlan(EndpointPlan::CREATE, ['rtb-a', 'rtb-b']), $steps);

        self::assertSame([], $ids);
        self::assertSame(['CreateVpcEndpoint', 'DescribeSubnets', 'DescribeRouteTables', 'DescribeVpcEndpoints'], $mocks->calls, "nothing is tagged or modified on the user's endpoint");
        self::assertSame([['deploy.endpoint_reused', ['service' => 's3', 'endpoint' => 'vpce-user']]], $steps);
    }

    public function test_an_associate_without_a_target_cannot_be_applied(): void
    {
        $mocks = new AwsMocks();
        $steps = [];

        try {
            $this->apply($mocks, self::makePlan(EndpointPlan::ASSOCIATE, ['rtb-a']), $steps);
            self::fail('expected a LogicException');
        } catch (\LogicException) {
            self::assertSame([], $mocks->calls);
        }
    }

    public function test_plan_without_endpoints_or_vpc_makes_no_call(): void
    {
        $mocks = new AwsMocks();
        $noEndpoints = ConfigResolver::resolve(App::make('shop')->region('us-east-1')
            ->environment('production', fn ($e) => $e->vpc(subnets: ['subnet-a'], securityGroups: ['sg-1']))->toArray(), 'production');
        $noVpc = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->environment('production')->toArray(), 'production');

        self::assertSame([], $this->subject($mocks)->plan($noEndpoints));
        self::assertSame([], $this->subject($mocks)->plan($noVpc));
        self::assertSame([], $mocks->calls);
    }

    public function test_a_second_conflict_fails(): void
    {
        $conflict = fn () => AwsMocks::error(Ec2Exception::class, 'CreateVpcEndpoint', 'InvalidParameter', 400, 'route table rtb-a already has a route with destination-prefix-list-id pl-1');
        $mocks = new AwsMocks();
        $mocks->handler->append($conflict());
        $this->network($mocks);
        $mocks->handler->append(self::endpoints([]));
        $mocks->handler->append($conflict());
        $steps = [];

        try {
            $this->apply($mocks, self::makePlan(EndpointPlan::CREATE, ['rtb-a', 'rtb-b']), $steps);
            self::fail('expected a conflict');
        } catch (DeployException $e) {
            self::assertSame('deploy.endpoint_conflict', $e->key);
        }
    }

    public function test_other_errors_are_not_retried(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(AwsMocks::error(Ec2Exception::class, 'CreateVpcEndpoint', 'UnauthorizedOperation', 403));
        $steps = [];

        $this->expectException(Ec2Exception::class);
        $this->apply($mocks, self::makePlan(EndpointPlan::CREATE, ['rtb-a']), $steps);
    }

    public function test_tag_limit(): void
    {
        $tags = self::ARCO;
        for ($i = 1; $i < GatewayEndpoints::MAX_TAGS; $i++) {
            $tags["arco:uses:app$i/env"] = '1';
        }
        $mocks = new AwsMocks();
        $mocks->handler->append(self::endpoints([self::endpoint('vpce-arco', ['rtb-a'], $tags)]));
        $steps = [];

        try {
            $this->apply($mocks, self::makePlan(EndpointPlan::ASSOCIATE, ['rtb-b'], 'vpce-arco', true), $steps);
            self::fail('expected the tag limit');
        } catch (DeployException $e) {
            self::assertSame('deploy.endpoint_tag_limit', $e->key);
        }
        self::assertSame(['DescribeVpcEndpoints'], $mocks->calls);
    }

    public function test_release_untags_and_keeps_shared(): void
    {
        $mocks = new AwsMocks();
        $shared = self::endpoint('vpce-arco', ['rtb-a'], self::ARCO + [self::USE => '1', 'arco:uses:other/x' => '1']);
        $mocks->handler->append(self::endpoints([$shared]));
        $mocks->handler->append(new Result([]));
        $mocks->handler->append(self::endpoints([self::endpoint('vpce-arco', ['rtb-a'], self::ARCO + ['arco:uses:other/x' => '1'])]));
        $steps = [];

        $ok = $this->subject($mocks)->release($this->config(), ['vpce-arco'], $this->recorder($steps), 'deploy.');

        self::assertTrue($ok);
        self::assertSame(['DescribeVpcEndpoints', 'DeleteTags', 'DescribeVpcEndpoints'], $mocks->calls);
        self::assertSame(['vpce-arco'], $mocks->params[1]['Resources']);
        self::assertSame([['Key' => self::USE]], $mocks->params[1]['Tags']);
        self::assertSame([['deploy.endpoint_released', ['endpoint' => 'vpce-arco']]], $steps);
    }

    public function test_release_deletes_last_user_after_rereading(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(self::endpoints([self::endpoint('vpce-arco', ['rtb-a'], self::ARCO + [self::USE => '1'])]));
        $mocks->handler->append(new Result([]));
        $mocks->handler->append(self::endpoints([self::endpoint('vpce-arco', ['rtb-a'], self::ARCO)]));
        $mocks->handler->append(new Result([]));
        $steps = [];

        $ok = $this->subject($mocks)->release($this->config(), ['vpce-arco'], $this->recorder($steps), 'remove.');

        self::assertTrue($ok);
        self::assertSame(['DescribeVpcEndpoints', 'DeleteTags', 'DescribeVpcEndpoints', 'DeleteVpcEndpoints'], $mocks->calls);
        self::assertSame(['vpce-arco'], $mocks->params[3]['VpcEndpointIds']);
        self::assertSame([['remove.endpoint_deleted', ['endpoint' => 'vpce-arco']]], $steps);
    }

    public function test_release_keeps_an_endpoint_another_environment_tagged_in_the_meantime(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(self::endpoints([self::endpoint('vpce-arco', ['rtb-a'], self::ARCO + [self::USE => '1'])]));
        $mocks->handler->append(new Result([]));
        $mocks->handler->append(self::endpoints([self::endpoint('vpce-arco', ['rtb-a'], self::ARCO + ['arco:uses:late/env' => '1'])]));
        $steps = [];

        $this->subject($mocks)->release($this->config(), ['vpce-arco'], $this->recorder($steps), 'deploy.');

        self::assertNotContains('DeleteVpcEndpoints', $mocks->calls);
    }

    public function test_release_never_deletes_without_marker(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(self::endpoints([self::endpoint('vpce-user', ['rtb-a'])]));
        $steps = [];

        $ok = $this->subject($mocks)->release($this->config(), ['vpce-user'], $this->recorder($steps), 'deploy.');

        self::assertTrue($ok);
        self::assertSame(['DescribeVpcEndpoints'], $mocks->calls);
        self::assertSame([], $steps);
    }

    public function test_release_missing_endpoint_is_success(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(AwsMocks::error(Ec2Exception::class, 'DescribeVpcEndpoints', 'InvalidVpcEndpointId.NotFound', 400));
        $steps = [];

        self::assertTrue($this->subject($mocks)->release($this->config(), ['vpce-gone'], $this->recorder($steps), 'deploy.'));
        self::assertSame([], $steps);
    }

    public function test_release_failure_warns_with_commands(): void
    {
        foreach (['deploy.', 'remove.'] as $prefix) {
            $mocks = new AwsMocks();
            $mocks->handler->append(self::endpoints([self::endpoint('vpce-arco', ['rtb-a'], self::ARCO + [self::USE => '1'])]));
            $mocks->handler->append(AwsMocks::error(Ec2Exception::class, 'DeleteTags', 'UnauthorizedOperation', 403, 'not allowed'));
            $mocks->handler->append(self::endpoints([self::endpoint('vpce-two', ['rtb-b'], self::ARCO + [self::USE => '1'])]));
            $mocks->handler->append(new Result([]));
            $mocks->handler->append(self::endpoints([self::endpoint('vpce-two', ['rtb-b'], self::ARCO)]));
            $mocks->handler->append(new Result([]));
            $steps = [];

            $ok = $this->subject($mocks)->release($this->config(), ['vpce-arco', 'vpce-two'], $this->recorder($steps), $prefix);

            self::assertFalse($ok);
            self::assertSame([$prefix . 'endpoint_cleanup_failed', ['endpoint' => 'vpce-arco', 'tag' => self::USE, 'region' => 'us-east-1', 'error' => 'UnauthorizedOperation not allowed']], $steps[0]);
            self::assertSame($prefix . 'endpoint_deleted', $steps[1][0], 'the next endpoint is still released');
        }
    }
    public function test_user_endpoints_covering_without_a_named_target_are_reported(): void
    {
        $mocks = new AwsMocks();
        $steps = [];

        $ids = $this->apply($mocks, self::makePlan(EndpointPlan::NONE, [], null, false, [
            ['id' => 'vpce-u2', 'arco' => false, 'uses' => 0],
            ['id' => 'vpce-u1', 'arco' => false, 'uses' => 0],
        ]), $steps);

        self::assertSame([], $ids);
        self::assertSame([], $mocks->calls);
        self::assertSame([
            ['deploy.endpoint_reused', ['service' => 's3', 'endpoint' => 'vpce-u1']],
            ['deploy.endpoint_reused', ['service' => 's3', 'endpoint' => 'vpce-u2']],
        ], $steps);
    }

    public function test_reused_message_names_the_owner_in_each_language(): void
    {
        $en = require __DIR__ . '/../../../packages/arco/lang/en.php';
        $es = require __DIR__ . '/../../../packages/arco/lang/es.php';
        self::assertStringNotContainsString('{owner}', $en['deploy.endpoint_reused']);
        self::assertStringContainsString('your', $en['deploy.endpoint_reused']);
        self::assertStringNotContainsString('{owner}', $es['deploy.endpoint_reused']);
        self::assertMatchesRegularExpression('/tu |tuyo/', $es['deploy.endpoint_reused']);
    }

    public function test_shared_message_names_arco_in_each_language(): void
    {
        $en = require __DIR__ . '/../../../packages/arco/lang/en.php';
        $es = require __DIR__ . '/../../../packages/arco/lang/es.php';
        self::assertStringContainsString('Arco', $en['deploy.endpoint_shared']);
        self::assertStringContainsString('Arco', $es['deploy.endpoint_shared']);
        self::assertStringContainsString('{endpoint}', $es['deploy.endpoint_shared']);
    }

    public function test_create_survives_a_not_found_right_after_creating(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(new Result(['VpcEndpoint' => ['VpcEndpointId' => 'vpce-new']]));
        $mocks->handler->append(AwsMocks::error(Ec2Exception::class, 'DescribeVpcEndpoints', 'InvalidVpcEndpointId.NotFound', 400));
        $mocks->handler->append(self::endpoints([self::endpoint('vpce-new', ['rtb-a'], self::ARCO, 'available')]));
        $steps = [];

        $ids = $this->apply($mocks, self::makePlan(EndpointPlan::CREATE, ['rtb-a']), $steps);

        self::assertSame(['vpce-new'], $ids);
        self::assertSame(['CreateVpcEndpoint', 'DescribeVpcEndpoints', 'DescribeVpcEndpoints'], $mocks->calls);
    }

    public function test_release_reports_an_unsuccessful_delete(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(self::endpoints([self::endpoint('vpce-arco', ['rtb-a'], self::ARCO + [self::USE => '1'])]));
        $mocks->handler->append(new Result([]));
        $mocks->handler->append(self::endpoints([self::endpoint('vpce-arco', ['rtb-a'], self::ARCO)]));
        $mocks->handler->append(new Result(['Unsuccessful' => [['ResourceId' => 'vpce-arco', 'Error' => ['Code' => 'DependencyViolation', 'Message' => 'in use']]]]));
        $steps = [];

        $ok = $this->subject($mocks)->release($this->config(), ['vpce-arco'], $this->recorder($steps), 'deploy.');

        self::assertFalse($ok);
        self::assertSame([['deploy.endpoint_cleanup_failed', ['endpoint' => 'vpce-arco', 'tag' => self::USE, 'region' => 'us-east-1', 'error' => 'DependencyViolation in use']]], $steps);
    }

    public function test_release_unsuccessful_not_found_is_success(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(self::endpoints([self::endpoint('vpce-arco', ['rtb-a'], self::ARCO + [self::USE => '1'])]));
        $mocks->handler->append(new Result([]));
        $mocks->handler->append(self::endpoints([self::endpoint('vpce-arco', ['rtb-a'], self::ARCO)]));
        $mocks->handler->append(new Result(['Unsuccessful' => [['ResourceId' => 'vpce-arco', 'Error' => ['Code' => 'InvalidVpcEndpointId.NotFound', 'Message' => 'gone']]]]));
        $steps = [];

        self::assertTrue($this->subject($mocks)->release($this->config(), ['vpce-arco'], $this->recorder($steps), 'deploy.'));
        self::assertSame([], $steps);
    }

    public function test_associate_that_returns_false_fails(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(new Result(['Return' => false]));
        $steps = [];

        try {
            $this->apply($mocks, self::makePlan(EndpointPlan::ASSOCIATE, ['rtb-b'], 'vpce-user'), $steps);
            self::fail('expected a failure');
        } catch (DeployException $e) {
            self::assertSame('deploy.endpoint_modify_failed', $e->key);
        }
    }
}
