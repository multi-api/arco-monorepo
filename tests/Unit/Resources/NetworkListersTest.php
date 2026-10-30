<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Resources;

use Aws\Result;
use Multiapi\Arco\Resources\EndpointLister;
use Multiapi\Arco\Resources\ResourceItem;
use Multiapi\Arco\Resources\ResourceType;
use Multiapi\Arco\Resources\SecurityGroupLister;
use Multiapi\Arco\Resources\SubnetEgress;
use Multiapi\Arco\Resources\SubnetLister;
use Multiapi\Arco\Tests\Unit\Deploy\AwsMocks;
use PHPUnit\Framework\TestCase;

final class NetworkListersTest extends TestCase
{
    private AwsMocks $mocks;

    protected function setUp(): void
    {
        $this->mocks = new AwsMocks();
    }

    /** @param array<string, mixed> $extra */
    private static function subnet(string $id, array $extra = []): array
    {
        return $extra + ['SubnetId' => $id, 'VpcId' => 'vpc-1', 'AvailabilityZoneId' => 'use1-az1', 'CidrBlock' => '10.0.8.0/21', 'AvailableIpAddressCount' => 2011, 'Tags' => []];
    }

    /** @param list<array<string, mixed>> $routes */
    private static function table(string $id, array $routes, ?string $subnet = null, bool $main = false): array
    {
        $associations = [];
        if ($subnet !== null) {
            $associations[] = ['SubnetId' => $subnet, 'Main' => false];
        }
        if ($main) {
            $associations[] = ['Main' => true];
        }
        return ['RouteTableId' => $id, 'VpcId' => 'vpc-1', 'Routes' => $routes, 'Associations' => $associations];
    }

    /**
     * Queues DescribeSubnets, DescribeRouteTables and DescribeVpcs answers, in the order the lister calls them.
     *
     * @param list<array<string, mixed>> $subnets
     * @param list<array<string, mixed>> $tables
     * @return list<ResourceItem>
     */
    private function listSubnets(array $subnets, array $tables, array $vpcs = [['VpcId' => 'vpc-1']]): array
    {
        $this->mocks->handler->append(new Result(['Subnets' => $subnets]), new Result(['RouteTables' => $tables]), new Result(['Vpcs' => $vpcs]));
        return (new SubnetLister())->list($this->mocks->clients());
    }

    /** @return list<string> */
    private static function keys(ResourceItem $item): array
    {
        return array_map(fn (array $w) => $w['key'], $item->warnings);
    }

    public function test_subnet_egress_through_an_explicit_route_table(): void
    {
        $items = $this->listSubnets(
            [self::subnet('subnet-a')],
            [self::table('rtb-1', [['DestinationCidrBlock' => '0.0.0.0/0', 'NatGatewayId' => 'nat-123']], 'subnet-a'), self::table('rtb-main', [['DestinationCidrBlock' => '0.0.0.0/0', 'GatewayId' => 'igw-1']], null, true)],
        );

        self::assertCount(1, $items);
        self::assertSame('nat', $items[0]->details['egress']);
        self::assertSame([], $items[0]->warnings);
        self::assertSame(ResourceType::Subnet, $items[0]->type);
        self::assertSame('use1-az1', $items[0]->details['az']);
        self::assertSame('10.0.8.0/21', $items[0]->details['cidr']);
        self::assertSame(2011, $items[0]->details['freeIps']);
    }

    public function test_subnet_without_association_uses_the_main_table(): void
    {
        $items = $this->listSubnets(
            [self::subnet('subnet-a')],
            [self::table('rtb-main', [['DestinationCidrBlock' => '10.0.0.0/16', 'GatewayId' => 'local'], ['DestinationCidrBlock' => '0.0.0.0/0', 'GatewayId' => 'igw-1']], null, true)],
        );

        self::assertSame('igw', $items[0]->details['egress']);
        self::assertSame(['resources.warning.subnet_public'], self::keys($items[0]));
    }

    public function test_subnet_without_default_route(): void
    {
        $items = $this->listSubnets([self::subnet('subnet-a')], [self::table('rtb-1', [['DestinationCidrBlock' => '10.0.0.0/16', 'GatewayId' => 'local']], 'subnet-a')]);

        self::assertSame('none', $items[0]->details['egress']);
        self::assertSame(['resources.warning.subnet_no_egress'], self::keys($items[0]));
    }

    public function test_nat_instance_route_counts_as_nat(): void
    {
        self::assertSame('nat', SubnetEgress::of(['Routes' => [['DestinationCidrBlock' => '0.0.0.0/0', 'InstanceId' => 'i-1']]]));
        self::assertSame('nat', SubnetEgress::of(['Routes' => [['DestinationCidrBlock' => '0.0.0.0/0', 'NetworkInterfaceId' => 'eni-1']]]));
        self::assertSame('none', SubnetEgress::of(['Routes' => [['DestinationCidrBlock' => '0.0.0.0/0', 'GatewayId' => 'vgw-1']]]));
        self::assertSame('none', SubnetEgress::of([]));
    }

    public function test_few_free_ips(): void
    {
        $nat = [self::table('rtb-main', [['DestinationCidrBlock' => '0.0.0.0/0', 'NatGatewayId' => 'nat-1']], null, true)];
        $items = $this->listSubnets([self::subnet('subnet-a', ['AvailableIpAddressCount' => 200]), self::subnet('subnet-b', ['AvailableIpAddressCount' => 256])], $nat);

        self::assertSame(['resources.warning.subnet_few_ips'], self::keys($items[0]));
        self::assertSame([['key' => 'resources.warning.subnet_few_ips', 'params' => ['free' => '200']]], $items[0]->warnings);
        self::assertSame([], $items[1]->warnings);
    }

    public function test_subnets_paginate(): void
    {
        $this->mocks->handler->append(
            new Result(['Subnets' => [self::subnet('subnet-a')], 'NextToken' => 't1']),
            new Result(['Subnets' => [self::subnet('subnet-b')]]),
            new Result(['RouteTables' => [self::table('rtb-1', [], 'subnet-a')], 'NextToken' => 'r1']),
            new Result(['RouteTables' => [self::table('rtb-main', [['DestinationCidrBlock' => '0.0.0.0/0', 'GatewayId' => 'igw-1']], null, true)]]),
            new Result(['Vpcs' => []]),
        );
        $items = (new SubnetLister())->list($this->mocks->clients());

        self::assertSame(['subnet-a', 'subnet-b'], array_map(fn (ResourceItem $i) => $i->id, $items));
        self::assertSame(['none', 'igw'], array_map(fn (ResourceItem $i) => $i->details['egress'], $items));
        self::assertSame('t1', $this->mocks->params[1]['NextToken']);
        self::assertSame('r1', $this->mocks->params[3]['NextToken']);
    }

    public function test_vpc_name_from_its_tag(): void
    {
        $items = $this->listSubnets([self::subnet('subnet-a')], [], [['VpcId' => 'vpc-1', 'Tags' => [['Key' => 'Name', 'Value' => 'dev']]]]);

        self::assertSame(['id' => 'vpc-1', 'name' => 'dev', 'default' => false], $items[0]->details['vpc']);
    }

    public function test_default_vpc_and_route_table(): void
    {
        $items = $this->listSubnets(
            [self::subnet('subnet-a'), self::subnet('subnet-b'), self::subnet('subnet-c', ['VpcId' => 'vpc-2'])],
            [self::table('rtb-1', [], 'subnet-a'), self::table('rtb-main', [], null, true)],
            [['VpcId' => 'vpc-1', 'IsDefault' => true]],
        );

        self::assertSame(['id' => 'vpc-1', 'name' => null, 'default' => true], $items[0]->details['vpc']);
        self::assertSame('rtb-1', $items[0]->details['routeTable']);
        self::assertSame('rtb-main', $items[1]->details['routeTable'], 'the main table of its VPC');
        self::assertSame(['id' => 'vpc-2', 'name' => null, 'default' => false], $items[2]->details['vpc']);
        self::assertNull($items[2]->details['routeTable']);
    }

    public function test_security_group_egress_all_and_none(): void
    {
        $this->mocks->handler->append(new Result(['SecurityGroups' => [
            ['GroupId' => 'sg-open', 'GroupName' => 'open', 'VpcId' => 'vpc-1', 'Description' => 'all out', 'Tags' => [['Key' => 'Name', 'Value' => 'lambda']],
                'IpPermissionsEgress' => [['IpProtocol' => '-1', 'IpRanges' => [['CidrIp' => '0.0.0.0/0']]]]],
            ['GroupId' => 'sg-closed', 'GroupName' => 'closed', 'VpcId' => 'vpc-1', 'Description' => 'nothing out', 'Tags' => [],
                'IpPermissionsEgress' => [['IpProtocol' => 'tcp', 'IpRanges' => [['CidrIp' => '0.0.0.0/0']]]]],
        ]]), new Result(['Vpcs' => [['VpcId' => 'vpc-1', 'IsDefault' => true, 'Tags' => [['Key' => 'Name', 'Value' => 'main']]]]]));
        [$closed, $open] = (new SecurityGroupLister())->list($this->mocks->clients());

        self::assertSame('lambda', $open->name);
        self::assertTrue($open->details['egressAll']);
        self::assertSame([], $open->warnings);
        self::assertSame(['id' => 'vpc-1', 'name' => 'main', 'default' => true], $open->details['vpc']);
        self::assertSame(['DescribeSecurityGroups', 'DescribeVpcs'], $this->mocks->calls);
        self::assertSame('all out', $open->details['description']);
        self::assertFalse($closed->details['egressAll']);
        self::assertSame(['resources.warning.sg_no_egress'], self::keys($closed));
    }

    public function test_only_gateway_s3_and_dynamodb_endpoints(): void
    {
        $this->mocks->handler->append(new Result(['VpcEndpoints' => [
            ['VpcEndpointId' => 'vpce-if', 'VpcEndpointType' => 'Interface', 'ServiceName' => 'com.amazonaws.us-east-1.s3', 'VpcId' => 'vpc-1'],
            ['VpcEndpointId' => 'vpce-s3', 'VpcEndpointType' => 'Gateway', 'ServiceName' => 'com.amazonaws.us-east-1.s3', 'VpcId' => 'vpc-1', 'State' => 'available', 'RouteTableIds' => ['rtb-1']],
            ['VpcEndpointId' => 'vpce-other', 'VpcEndpointType' => 'Gateway', 'ServiceName' => 'com.amazonaws.us-east-1.kinesis', 'VpcId' => 'vpc-1'],
            ['VpcEndpointId' => 'vpce-gone', 'VpcEndpointType' => 'Gateway', 'ServiceName' => 'com.amazonaws.us-east-1.dynamodb', 'VpcId' => 'vpc-1', 'State' => 'deleted'],
        ]]), new Result(['Vpcs' => [['VpcId' => 'vpc-1', 'IsDefault' => false]]]));
        $items = (new EndpointLister())->list($this->mocks->clients());

        self::assertCount(1, $items);
        self::assertSame('vpce-s3', $items[0]->id);
        self::assertSame('s3', $items[0]->details['service']);
        self::assertSame(['rtb-1'], $items[0]->details['routeTables']);
        self::assertSame(['id' => 'vpc-1', 'name' => null, 'default' => false], $items[0]->details['vpc']);
    }

    public function test_stack_arco_and_unnamed(): void
    {
        $tagged = new ResourceItem(ResourceType::Subnet, 'subnet-a', 'dev-1a', [
            'Name' => 'dev-1a', 'aws:cloudformation:stack-name' => 'dev-vpc', 'arco:app' => 'shop', 'arco:environment' => 'staging',
        ], ['cidr' => '10.0.0.0/24']);
        $bare = new ResourceItem(ResourceType::Subnet, 'subnet-b', null, [], []);
        $unrelated = new ResourceItem(ResourceType::Subnet, 'subnet-c', null, ['team' => 'x'], []);

        self::assertSame('dev-vpc', $tagged->stack());
        self::assertSame(['app' => 'shop', 'environment' => 'staging'], $tagged->arco());
        self::assertFalse($tagged->unnamed());
        self::assertNull($bare->stack());
        self::assertNull($bare->arco());
        self::assertTrue($bare->unnamed());
        self::assertFalse($unrelated->unnamed());
    }

    public function test_to_array_follows_the_json_shape(): void
    {
        $item = new ResourceItem(
            ResourceType::Subnet, 'subnet-a', 'dev-1a', ['Name' => 'dev-1a'],
            ['vpc' => ['id' => 'vpc-1', 'name' => 'dev'], 'az' => 'use1-az1', 'cidr' => '10.0.8.0/21', 'freeIps' => 2011, 'egress' => 'nat'],
            inUse: ['source' => 'arco.php'], apply: ['command' => 'vpc:set', 'args' => ['--subnet=subnet-a']],
        );
        $array = $item->toArray();

        self::assertSame(['type', 'id', 'name', 'vpc', 'az', 'cidr', 'freeIps', 'egress', 'stack', 'arco', 'tags', 'unnamed', 'inUse', 'hidden', 'warnings', 'apply'], array_keys($array));
        self::assertSame('subnet', $array['type']);
        self::assertNull($array['stack']);
        self::assertFalse($array['unnamed']);
        self::assertSame(['source' => 'arco.php'], $array['inUse']);
        self::assertSame('{"Name":"dev-1a"}', json_encode($array['tags']));
        self::assertSame('{}', json_encode((new ResourceItem(ResourceType::Zone, 'z', null, [], []))->toArray()['tags']));
    }

    public function test_calls_are_read_only(): void
    {
        $this->listSubnets([self::subnet('subnet-a')], []);
        $this->mocks->handler->append(new Result(['SecurityGroups' => []]), new Result(['VpcEndpoints' => []]));
        (new SecurityGroupLister())->list($this->mocks->clients());
        (new EndpointLister())->list($this->mocks->clients());

        self::assertSame([], array_values(array_diff($this->mocks->calls, ['DescribeSubnets', 'DescribeRouteTables', 'DescribeVpcs', 'DescribeSecurityGroups', 'DescribeVpcEndpoints'])));
        self::assertCount(5, $this->mocks->calls);
    }
}
