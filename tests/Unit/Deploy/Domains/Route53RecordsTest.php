<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy\Domains;

use Aws\Result;
use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Deploy\DeployException;
use Multiapi\Arco\Deploy\Domains\DnsRecord;
use Multiapi\Arco\Deploy\Domains\HostedZone;
use Multiapi\Arco\Deploy\Domains\Route53Dns;
use Multiapi\Arco\Tests\Unit\Deploy\AwsMocks;
use PHPUnit\Framework\TestCase;

final class Route53RecordsTest extends TestCase
{
    public function test_dns_change_without_an_identifier_is_not_reported_as_propagated(): void
    {
        $aws = new AwsMocks();
        $aws->handler->append(new Result(['ChangeInfo' => ['Status' => 'PENDING']]));
        $dns = new Route53Dns($aws->clients()->route53(), AwsMocks::noSleep());
        $config = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->environment('production', fn ($e) => $e)->toArray(), 'production');

        $this->expectException(DeployException::class);
        $this->expectExceptionMessage('domains.dns_change_unconfirmed');
        $dns->apply(new HostedZone('Z1', 'example.com', false), [], [DnsRecord::cname('routing.example.com', 'd111.cloudfront.net')], $config);
    }

    public function test_dns_change_with_an_empty_identifier_is_not_reported_as_propagated(): void
    {
        $aws = new AwsMocks();
        $aws->handler->append(new Result(['ChangeInfo' => ['Id' => '', 'Status' => 'PENDING']]));
        $dns = new Route53Dns($aws->clients()->route53(), AwsMocks::noSleep());
        $config = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->environment('production', fn ($e) => $e)->toArray(), 'production');

        $this->expectException(DeployException::class);
        $this->expectExceptionMessage('domains.dns_change_unconfirmed');
        $dns->apply(new HostedZone('Z1', 'example.com', false), [], [DnsRecord::cname('routing.example.com', 'd111.cloudfront.net')], $config);
    }

    public function test_records_reads_every_page_for_the_same_name(): void
    {
        $aws = new AwsMocks();
        $a = ['Name' => 'routing.example.com.', 'Type' => 'A', 'SetIdentifier' => 'one'];
        $cname = ['Name' => 'routing.example.com.', 'Type' => 'CNAME', 'SetIdentifier' => 'two'];
        $aws->handler->append(
            new Result(['ResourceRecordSets' => [$a], 'IsTruncated' => true, 'NextRecordName' => 'routing.example.com.', 'NextRecordType' => 'CNAME', 'NextRecordIdentifier' => 'two']),
            new Result(['ResourceRecordSets' => [$cname], 'IsTruncated' => false]),
        );
        $dns = new Route53Dns($aws->clients()->route53(), AwsMocks::noSleep());

        self::assertSame([$a, $cname], $dns->records(new HostedZone('Z1', 'example.com', false), 'routing.example.com'));
        self::assertSame('two', $aws->params[1]['StartRecordIdentifier']);
        self::assertSame('CNAME', $aws->params[1]['StartRecordType']);
    }

    public function test_truncated_response_without_a_cursor_fails_closed(): void
    {
        $aws = new AwsMocks();
        $aws->handler->append(new Result(['ResourceRecordSets' => [], 'IsTruncated' => true]));
        $dns = new Route53Dns($aws->clients()->route53(), AwsMocks::noSleep());

        $this->expectException(\RuntimeException::class);
        $dns->records(new HostedZone('Z1', 'example.com', false), 'routing.example.com');
    }

    public function test_missing_record_list_is_not_treated_as_an_available_name(): void
    {
        $aws = new AwsMocks();
        $aws->handler->append(new Result(['IsTruncated' => false]));
        $dns = new Route53Dns($aws->clients()->route53(), AwsMocks::noSleep());

        $this->expectException(DeployException::class);
        $this->expectExceptionMessage('domains.record_read_incomplete');
        $dns->records(new HostedZone('Z1', 'example.com', false), 'routing.example.com');
    }

    public function test_missing_record_pagination_flag_is_not_treated_as_the_final_page(): void
    {
        $aws = new AwsMocks();
        $aws->handler->append(new Result(['ResourceRecordSets' => []]));
        $dns = new Route53Dns($aws->clients()->route53(), AwsMocks::noSleep());

        $this->expectException(DeployException::class);
        $this->expectExceptionMessage('domains.record_read_incomplete');
        $dns->records(new HostedZone('Z1', 'example.com', false), 'routing.example.com');
    }

    public function test_invalid_record_pagination_flag_is_not_treated_as_the_final_page(): void
    {
        $aws = new AwsMocks();
        $aws->handler->append(new Result(['ResourceRecordSets' => [], 'IsTruncated' => 'false']));
        $dns = new Route53Dns($aws->clients()->route53(), AwsMocks::noSleep());

        $this->expectException(DeployException::class);
        $this->expectExceptionMessage('domains.record_read_incomplete');
        $dns->records(new HostedZone('Z1', 'example.com', false), 'routing.example.com');
    }

    public function test_record_without_name_is_not_treated_as_an_available_name(): void
    {
        $aws = new AwsMocks();
        $aws->handler->append(new Result(['ResourceRecordSets' => [['Type' => 'A']], 'IsTruncated' => false]));
        $dns = new Route53Dns($aws->clients()->route53(), AwsMocks::noSleep());

        $this->expectException(DeployException::class);
        $this->expectExceptionMessage('domains.record_read_incomplete');
        $dns->records(new HostedZone('Z1', 'example.com', false), 'routing.example.com');
    }

    public function test_record_with_an_empty_name_is_not_treated_as_an_available_name(): void
    {
        $aws = new AwsMocks();
        $aws->handler->append(new Result(['ResourceRecordSets' => [['Name' => '', 'Type' => 'A']], 'IsTruncated' => false]));
        $dns = new Route53Dns($aws->clients()->route53(), AwsMocks::noSleep());

        $this->expectException(DeployException::class);
        $this->expectExceptionMessage('domains.record_read_incomplete');
        $dns->records(new HostedZone('Z1', 'example.com', false), 'routing.example.com');
    }

    public function test_empty_record_cursor_is_not_treated_as_a_different_name(): void
    {
        $aws = new AwsMocks();
        $aws->handler->append(new Result(['ResourceRecordSets' => [], 'IsTruncated' => true, 'NextRecordName' => '', 'NextRecordType' => 'A']));
        $dns = new Route53Dns($aws->clients()->route53(), AwsMocks::noSleep());

        $this->expectException(DeployException::class);
        $this->expectExceptionMessage('domains.record_read_incomplete');
        $dns->records(new HostedZone('Z1', 'example.com', false), 'routing.example.com');
    }

    public function test_malformed_record_is_not_treated_as_an_available_name(): void
    {
        $aws = new AwsMocks();
        $aws->handler->append(new Result(['ResourceRecordSets' => [null], 'IsTruncated' => false]));
        $dns = new Route53Dns($aws->clients()->route53(), AwsMocks::noSleep());

        $this->expectException(DeployException::class);
        $this->expectExceptionMessage('domains.record_read_incomplete');
        $dns->records(new HostedZone('Z1', 'example.com', false), 'routing.example.com');
    }

    public function test_incomplete_hosted_zone_pagination_does_not_select_a_parent_zone(): void
    {
        $aws = new AwsMocks();
        $aws->handler->append(new Result([
            'HostedZones' => [['Id' => 'Z1', 'Name' => 'example.com.', 'Config' => ['PrivateZone' => false]]],
            'IsTruncated' => true,
        ]));
        $dns = new Route53Dns($aws->clients()->route53(), AwsMocks::noSleep());

        $this->expectException(DeployException::class);
        $this->expectExceptionMessage('domains.zones_read_incomplete');
        $dns->zoneFor('routing.shop.example.com');
    }

    public function test_malformed_zone_list_does_not_select_a_parent_zone(): void
    {
        $aws = new AwsMocks();
        $aws->handler->append(new Result([
            'HostedZones' => [['Id' => 'Z1', 'Name' => 'example.com.', 'Config' => ['PrivateZone' => false]], null],
            'IsTruncated' => false,
        ]));
        $dns = new Route53Dns($aws->clients()->route53(), AwsMocks::noSleep());

        $this->expectException(DeployException::class);
        $this->expectExceptionMessage('domains.zones_read_incomplete');
        $dns->zoneFor('routing.shop.example.com');
    }

    public function test_missing_zone_list_is_not_treated_as_no_hosted_zones(): void
    {
        $aws = new AwsMocks();
        $aws->handler->append(new Result(['IsTruncated' => false]));
        $dns = new Route53Dns($aws->clients()->route53(), AwsMocks::noSleep());

        $this->expectException(DeployException::class);
        $this->expectExceptionMessage('domains.zones_read_incomplete');
        $dns->zoneFor('routing.example.com');
    }

    public function test_missing_zone_pagination_flag_is_not_treated_as_the_final_page(): void
    {
        $aws = new AwsMocks();
        $aws->handler->append(new Result([
            'HostedZones' => [['Id' => 'Z1', 'Name' => 'example.com.', 'Config' => ['PrivateZone' => false]]],
        ]));
        $dns = new Route53Dns($aws->clients()->route53(), AwsMocks::noSleep());

        $this->expectException(DeployException::class);
        $this->expectExceptionMessage('domains.zones_read_incomplete');
        $dns->zoneFor('routing.shop.example.com');
    }

    public function test_missing_hosted_zone_id_in_pagination_fails_closed(): void
    {
        $aws = new AwsMocks();
        $aws->handler->append(new Result(['HostedZones' => [], 'IsTruncated' => true, 'NextDNSName' => 'example.com.']));
        $dns = new Route53Dns($aws->clients()->route53(), AwsMocks::noSleep());

        $this->expectException(DeployException::class);
        $this->expectExceptionMessage('domains.zones_read_incomplete');
        $dns->zoneFor('routing.example.com');
    }

    public function test_repeated_hosted_zone_cursor_fails_closed(): void
    {
        $aws = new AwsMocks();
        $page = ['HostedZones' => [], 'IsTruncated' => true, 'NextDNSName' => 'example.com.', 'NextHostedZoneId' => 'Z1'];
        $aws->handler->append(new Result($page), new Result($page));
        $dns = new Route53Dns($aws->clients()->route53(), AwsMocks::noSleep());

        $this->expectException(DeployException::class);
        $this->expectExceptionMessage('domains.zones_read_incomplete');
        $dns->zoneFor('routing.example.com');
    }

    public function test_zone_without_a_private_flag_does_not_get_treated_as_public(): void
    {
        $aws = new AwsMocks();
        $aws->handler->append(new Result([
            'HostedZones' => [['Id' => 'Z1', 'Name' => 'example.com.']],
            'IsTruncated' => false,
        ]));
        $dns = new Route53Dns($aws->clients()->route53(), AwsMocks::noSleep());

        $this->expectException(DeployException::class);
        $this->expectExceptionMessage('domains.zones_read_incomplete');
        $dns->zoneFor('routing.example.com');
    }

    public function test_malformed_more_specific_zone_does_not_cause_parent_zone_selection(): void
    {
        $aws = new AwsMocks();
        $aws->handler->append(new Result([
            'HostedZones' => [
                ['Id' => 'Z1', 'Name' => 'example.com.', 'Config' => ['PrivateZone' => false]],
                ['Name' => 'shop.example.com.', 'Config' => ['PrivateZone' => false]],
            ],
            'IsTruncated' => false,
        ]));
        $dns = new Route53Dns($aws->clients()->route53(), AwsMocks::noSleep());

        $this->expectException(DeployException::class);
        $this->expectExceptionMessage('domains.zones_read_incomplete');
        $dns->zoneFor('routing.shop.example.com');
    }

    public function test_zone_selection_reads_all_pages_before_choosing_the_most_specific_public_zone(): void
    {
        $aws = new AwsMocks();
        $aws->handler->append(
            new Result(['HostedZones' => [['Id' => 'Z1', 'Name' => 'example.com.', 'Config' => ['PrivateZone' => false]]], 'IsTruncated' => true, 'NextDNSName' => 'shop.example.com.', 'NextHostedZoneId' => 'Z2']),
            new Result(['HostedZones' => [['Id' => 'Z2', 'Name' => 'shop.example.com.', 'Config' => ['PrivateZone' => false]]], 'IsTruncated' => false]),
        );
        $dns = new Route53Dns($aws->clients()->route53(), AwsMocks::noSleep());

        self::assertSame('Z2', $dns->zoneFor('routing.shop.example.com')?->id);
        self::assertSame('shop.example.com.', $aws->params[1]['DNSName']);
        self::assertSame('Z2', $aws->params[1]['HostedZoneId']);
    }
}