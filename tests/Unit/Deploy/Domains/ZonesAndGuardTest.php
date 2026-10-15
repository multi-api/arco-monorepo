<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy\Domains;

use Multiapi\Arco\Deploy\Domains\HostedZone;
use Multiapi\Arco\Deploy\Domains\RecordGuard;
use PHPUnit\Framework\TestCase;

final class ZonesAndGuardTest extends TestCase
{
    public function test_picks_the_longest_public_zone_that_is_a_suffix(): void
    {
        $zones = [
            new HostedZone('Z1', 'acmeapp.io.', false),
            new HostedZone('Z2', 'arco.acmeapp.io.', false),
            new HostedZone('Z3', 'api.arco.acmeapp.io.', true),
            new HostedZone('Z4', 'i-acmeapp.io.', false),
        ];

        self::assertSame('Z2', HostedZone::select($zones, 'api.arco.acmeapp.io')?->id, 'private zones are ignored');
        self::assertSame('Z2', HostedZone::select($zones, 'arco.acmeapp.io.')?->id);
        self::assertSame('Z1', HostedZone::select($zones, '_abc.api-arco.acmeapp.io.')?->id);
        self::assertNull(HostedZone::select($zones, 'example.com'));
        self::assertNull(HostedZone::select($zones, 'cl'));
        self::assertNull(HostedZone::select([new HostedZone('Z9', 'i-acmeapp.io.', false)], 'acmeapp.io'), 'a label suffix is not a zone suffix');
    }

    public function test_records_pointing_elsewhere_are_conflicts(): void
    {
        $sets = [
            ['Name' => 'arco.acmeapp.io.', 'Type' => 'CNAME', 'TTL' => 300, 'ResourceRecords' => [['Value' => 'old-server.example.com']]],
            ['Name' => 'arco.acmeapp.io.', 'Type' => 'TXT', 'TTL' => 300, 'ResourceRecords' => [['Value' => '"v=spf1"']]],
            ['Name' => 'other.acmeapp.io.', 'Type' => 'A', 'TTL' => 300, 'ResourceRecords' => [['Value' => '1.2.3.4']]],
        ];

        $plan = RecordGuard::plan($sets, 'arco.acmeapp.io', 'd111.cloudfront.net', false);

        self::assertSame([$sets[0]], $plan['conflicts']);
        self::assertSame(['arco.acmeapp.io CNAME → old-server.example.com'], array_map(RecordGuard::describe(...), $plan['conflicts']));
    }

    public function test_aliases_to_our_distribution_are_not_conflicts(): void
    {
        $ours = ['Name' => 'arco.acmeapp.io.', 'Type' => 'A', 'AliasTarget' => ['HostedZoneId' => 'Z2FDTNDATAQYW2', 'DNSName' => 'D111.cloudfront.net.', 'EvaluateTargetHealth' => false]];
        $theirs = ['Name' => 'arco.acmeapp.io.', 'Type' => 'AAAA', 'AliasTarget' => ['HostedZoneId' => 'Z2FDTNDATAQYW2', 'DNSName' => 'd999.cloudfront.net.', 'EvaluateTargetHealth' => false]];

        self::assertSame([], RecordGuard::plan([$ours], 'arco.acmeapp.io', 'd111.cloudfront.net', false)['conflicts']);
        self::assertSame([$theirs], RecordGuard::plan([$ours, $theirs], 'arco.acmeapp.io', 'd111.cloudfront.net', false)['conflicts']);
        self::assertSame([$ours], RecordGuard::plan([$ours], 'arco.acmeapp.io', null, false)['conflicts'], 'before the distribution exists, every address record is a conflict');
    }

    public function test_cnames_are_deleted_when_ours_or_forced_since_they_cannot_sit_next_to_an_alias(): void
    {
        $ourCname = ['Name' => 'arco.acmeapp.io.', 'Type' => 'CNAME', 'TTL' => 60, 'ResourceRecords' => [['Value' => 'd111.cloudfront.net']]];
        $foreignCname = ['Name' => 'arco.acmeapp.io.', 'Type' => 'CNAME', 'TTL' => 60, 'ResourceRecords' => [['Value' => 'elsewhere.example.com']]];
        $foreignA = ['Name' => 'arco.acmeapp.io.', 'Type' => 'A', 'TTL' => 60, 'ResourceRecords' => [['Value' => '1.2.3.4']]];
        $weighted = ['Name' => 'arco.acmeapp.io.', 'Type' => 'A', 'SetIdentifier' => 'blue', 'Weight' => 10, 'TTL' => 60, 'ResourceRecords' => [['Value' => '1.2.3.4']]];

        $plan = RecordGuard::plan([$ourCname], 'arco.acmeapp.io', 'd111.cloudfront.net', false);
        self::assertSame(['conflicts' => [], 'deletes' => [$ourCname]], $plan);

        $plan = RecordGuard::plan([$foreignCname, $foreignA, $weighted], 'arco.acmeapp.io', 'd111.cloudfront.net', false);
        self::assertSame([$foreignCname, $foreignA, $weighted], $plan['conflicts']);
        self::assertSame([], $plan['deletes'], 'nothing is touched without --force-dns');

        $plan = RecordGuard::plan([$foreignCname, $foreignA, $weighted], 'arco.acmeapp.io', 'd111.cloudfront.net', true);
        self::assertSame([$foreignCname, $weighted], $plan['deletes'], 'a plain A record is replaced by the UPSERT');
    }

    public function test_removable_aliases_only_point_at_our_distribution(): void
    {
        $oursA = ['Name' => 'arco.acmeapp.io.', 'Type' => 'A', 'AliasTarget' => ['HostedZoneId' => 'Z2FDTNDATAQYW2', 'DNSName' => 'd111.cloudfront.net.', 'EvaluateTargetHealth' => false]];
        $oursAaaa = ['Name' => 'arco.acmeapp.io.', 'Type' => 'AAAA', 'AliasTarget' => ['HostedZoneId' => 'Z2FDTNDATAQYW2', 'DNSName' => 'd111.cloudfront.net.', 'EvaluateTargetHealth' => false]];
        $other = ['Name' => 'arco.acmeapp.io.', 'Type' => 'A', 'AliasTarget' => ['HostedZoneId' => 'Z2FDTNDATAQYW2', 'DNSName' => 'd999.cloudfront.net.', 'EvaluateTargetHealth' => false]];
        $txt = ['Name' => 'arco.acmeapp.io.', 'Type' => 'TXT', 'TTL' => 300, 'ResourceRecords' => [['Value' => '"x"']]];
        $sibling = ['Name' => 'x.arco.acmeapp.io.', 'Type' => 'A', 'AliasTarget' => ['HostedZoneId' => 'Z2FDTNDATAQYW2', 'DNSName' => 'd111.cloudfront.net.', 'EvaluateTargetHealth' => false]];

        self::assertSame([$oursA, $oursAaaa], RecordGuard::ourAliases([$oursA, $oursAaaa, $other, $txt, $sibling], 'arco.acmeapp.io', 'd111.cloudfront.net'));
    }
}
