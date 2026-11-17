<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy\Domains;

use Aws\Result;
use Multiapi\Arco\Deploy\DeployException;
use Multiapi\Arco\Deploy\Domains\Route53Dns;
use Multiapi\Arco\Deploy\Domains\TenantTargetDns;
use Multiapi\Arco\Tests\Unit\Deploy\AwsMocks;
use PHPUnit\Framework\TestCase;

final class TenantTargetDnsTest extends TestCase
{
    public function test_preflight_selects_the_most_specific_public_zone_for_a_free_target(): void
    {
        $aws = new AwsMocks();
        $aws->handler->append(self::zones(), self::records([]));

        $zone = self::target($aws)->preflight('routing.shop.example.com');

        self::assertSame('Z2', $zone->id);
        self::assertSame(['ListHostedZonesByName', 'ListResourceRecordSets'], $aws->calls);
        self::assertSame('Z2', $aws->params[1]['HostedZoneId']);
    }

    public function test_preflight_rejects_the_apex_before_reading_or_writing_records(): void
    {
        $aws = new AwsMocks();
        $aws->handler->append(self::zones());

        try {
            self::target($aws)->preflight('shop.example.com');
            self::fail('The target at the zone apex must be rejected.');
        } catch (DeployException $exception) {
            self::assertSame('domains.tenant_target_apex', $exception->key);
            self::assertSame(['ListHostedZonesByName'], $aws->calls);
        }
    }

    public function test_preflight_rejects_an_occupied_target_without_a_verified_owned_endpoint(): void
    {
        $aws = new AwsMocks();
        $aws->handler->append(self::zones(), self::records([self::cname()]));

        $this->expectException(DeployException::class);
        $this->expectExceptionMessage('domains.tenant_target_occupied');
        self::target($aws)->preflight('routing.shop.example.com');
    }

    public function test_preflight_accepts_the_existing_cname_only_for_a_verified_owned_endpoint(): void
    {
        $aws = new AwsMocks();
        $aws->handler->append(self::zones(), self::records([self::cname()]));

        $zone = self::target($aws)->preflight('routing.shop.example.com', 'endpoint.cloudfront.net');

        self::assertSame('Z2', $zone->id);
        self::assertSame(['ListHostedZonesByName', 'ListResourceRecordSets'], $aws->calls);
    }

    public function test_preflight_rejects_a_cname_pointing_to_a_different_endpoint(): void
    {
        $aws = new AwsMocks();
        $aws->handler->append(self::zones(), self::records([self::cname()]));

        $this->expectException(DeployException::class);
        $this->expectExceptionMessage('domains.tenant_target_occupied');
        self::target($aws)->preflight('routing.shop.example.com', 'other.cloudfront.net');
    }

    public function test_preflight_rejects_routing_policies_even_when_the_cname_points_to_the_owned_endpoint(): void
    {
        $aws = new AwsMocks();
        $aws->handler->append(self::zones(), self::records([self::cname() + ['SetIdentifier' => 'foreign', 'Weight' => 100]]));

        $this->expectException(DeployException::class);
        $this->expectExceptionMessage('domains.tenant_target_occupied');
        self::target($aws)->preflight('routing.shop.example.com', 'endpoint.cloudfront.net');
    }

    public function test_preflight_rejects_other_records_even_when_a_cname_points_to_the_owned_endpoint(): void
    {
        $aws = new AwsMocks();
        $aws->handler->append(self::zones(), self::records([self::cname(), ['Name' => 'routing.shop.example.com.', 'Type' => 'TXT', 'TTL' => 300, 'ResourceRecords' => [['Value' => '"x"']]]]));

        $this->expectException(DeployException::class);
        $this->expectExceptionMessage('domains.tenant_target_occupied');
        self::target($aws)->preflight('routing.shop.example.com', 'endpoint.cloudfront.net');
    }

    public function test_preflight_rejects_a_missing_public_zone(): void
    {
        $aws = new AwsMocks();
        $aws->handler->append(new Result(['HostedZones' => [], 'IsTruncated' => false]));

        $this->expectException(DeployException::class);
        $this->expectExceptionMessage('domains.tenant_target_zone_missing');
        self::target($aws)->preflight('routing.example.com');
    }

    public function test_publish_creates_a_cname_atomically_and_waits_for_propagation(): void
    {
        $aws = new AwsMocks();
        $aws->handler->append(
            self::zones(), self::records([]),
            new Result(['ChangeInfo' => ['Id' => '/change/C1', 'Status' => 'PENDING']]),
            new Result(['ChangeInfo' => ['Id' => '/change/C1', 'Status' => 'INSYNC']]),
        );

        self::target($aws)->publish('routing.shop.example.com', 'endpoint.cloudfront.net');

        self::assertSame(['ListHostedZonesByName', 'ListResourceRecordSets', 'ChangeResourceRecordSets', 'GetChange'], $aws->calls);
        self::assertSame('Z2', $aws->params[2]['HostedZoneId']);
        self::assertSame([['Action' => 'CREATE', 'ResourceRecordSet' => [
            'Name' => 'routing.shop.example.com', 'Type' => 'CNAME', 'TTL' => 300,
            'ResourceRecords' => [['Value' => 'endpoint.cloudfront.net']],
        ]]], $aws->params[2]['ChangeBatch']['Changes']);
    }

    public function test_publish_never_overwrites_a_target_occupied_after_preflight(): void
    {
        $aws = new AwsMocks();
        $aws->handler->append(self::zones(), self::records([]), self::records([self::cname()]));
        $target = self::target($aws);
        $target->preflight('routing.shop.example.com');

        try {
            $target->publish('routing.shop.example.com', 'endpoint.cloudfront.net');
            self::fail('A target occupied after preflight must not be overwritten.');
        } catch (DeployException $exception) {
            self::assertSame('domains.tenant_target_occupied', $exception->key);
            self::assertSame(['ListHostedZonesByName', 'ListResourceRecordSets', 'ListResourceRecordSets'], $aws->calls);
        }
    }

    public function test_publish_does_not_change_an_existing_verified_cname(): void
    {
        $aws = new AwsMocks();
        $aws->handler->append(self::zones(), self::records([self::cname()]));

        self::target($aws)->publish('routing.shop.example.com', 'endpoint.cloudfront.net', 'endpoint.cloudfront.net');

        self::assertSame(['ListHostedZonesByName', 'ListResourceRecordSets'], $aws->calls);
    }

    public function test_publish_rejects_changing_the_verified_endpoint(): void
    {
        $aws = new AwsMocks();

        try {
            self::target($aws)->publish('routing.shop.example.com', 'new.cloudfront.net', 'endpoint.cloudfront.net');
            self::fail('Changing the endpoint is not safe without a tenant reconciliation.');
        } catch (DeployException $exception) {
            self::assertSame('domains.tenant_target_change', $exception->key);
            self::assertSame([], $aws->calls);
        }
    }

    private static function target(AwsMocks $aws): TenantTargetDns
    {
        return new TenantTargetDns(new Route53Dns($aws->clients()->route53(), AwsMocks::noSleep()));
    }

    private static function zones(): Result
    {
        return new Result(['HostedZones' => [
            ['Id' => 'Z1', 'Name' => 'example.com.', 'Config' => ['PrivateZone' => false]],
            ['Id' => 'Z2', 'Name' => 'shop.example.com.', 'Config' => ['PrivateZone' => false]],
        ], 'IsTruncated' => false]);
    }

    /** @param list<array<string, mixed>> $sets */
    private static function records(array $sets): Result
    {
        return new Result(['ResourceRecordSets' => $sets, 'IsTruncated' => false]);
    }

    /** @return array<string, mixed> */
    private static function cname(): array
    {
        return ['Name' => 'routing.shop.example.com.', 'Type' => 'CNAME', 'TTL' => 300, 'ResourceRecords' => [['Value' => 'endpoint.cloudfront.net.']]];
    }
}