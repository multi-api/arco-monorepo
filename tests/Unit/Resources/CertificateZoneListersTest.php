<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Resources;

use Aws\Acm\Exception\AcmException;
use Aws\Result;
use Multiapi\Arco\Resources\CertificateLister;
use Multiapi\Arco\Resources\ResourceType;
use Multiapi\Arco\Resources\ZoneLister;
use Multiapi\Arco\Tests\Unit\Deploy\AwsMocks;
use PHPUnit\Framework\TestCase;

final class CertificateZoneListersTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private AwsMocks $mocks;

    protected function setUp(): void
    {
        $this->mocks = new AwsMocks();
    }

    private function certificates(): CertificateLister
    {
        return new CertificateLister(fn (): int => self::NOW);
    }

    /** @param array<string, mixed> $extra */
    private static function certificate(string $arn, array $extra = []): Result
    {
        return new Result(['Certificate' => [
            'CertificateArn' => $arn,
            'DomainName' => '*.dev.example.com',
            'SubjectAlternativeNames' => ['*.dev.example.com', 'dev.example.com'],
            'Status' => 'ISSUED',
            'NotAfter' => self::NOW + 90 * 86400,
            'InUseBy' => [],
            ...$extra,
        ]]);
    }

    public function test_certificates_read_in_us_east_1_with_all_key_types(): void
    {
        $this->mocks->handler->append(
            new Result(['CertificateSummaryList' => [['CertificateArn' => 'arn:c1']]]),
            self::certificate('arn:c1', ['InUseBy' => ['arn:cf1', 'arn:cf2']]),
        );
        $items = $this->certificates()->list($this->mocks->clients('eu-west-1'));

        self::assertSame(['acm.us-east-1.amazonaws.com', 'acm.us-east-1.amazonaws.com'], $this->mocks->hosts);
        self::assertSame(['ListCertificates', 'DescribeCertificate'], $this->mocks->calls);
        // ACM lists only RSA_2048 certificates unless the key types are asked for
        self::assertSame(
            ['RSA_1024', 'RSA_2048', 'RSA_3072', 'RSA_4096', 'EC_prime256v1', 'EC_secp384r1', 'EC_secp521r1'],
            $this->mocks->params[0]['Includes']['keyTypes'],
        );
        self::assertCount(1, $items);
        self::assertSame(ResourceType::Certificate, $items[0]->type);
        self::assertSame('arn:c1', $items[0]->id);
        self::assertSame('*.dev.example.com', $items[0]->name);
        self::assertSame('arn:c1', $items[0]->details['arn']);
        self::assertSame(['*.dev.example.com', 'dev.example.com'], $items[0]->details['domains']);
        self::assertSame('ISSUED', $items[0]->details['status']);
        self::assertSame(gmdate(\DATE_ATOM, self::NOW + 90 * 86400), $items[0]->details['expires']);
        self::assertSame(2, $items[0]->details['inUseBy']);
        self::assertSame([], $items[0]->warnings);
    }

    public function test_certificate_warnings(): void
    {
        $this->mocks->handler->append(
            new Result(['CertificateSummaryList' => [['CertificateArn' => 'a'], ['CertificateArn' => 'b'], ['CertificateArn' => 'c'], ['CertificateArn' => 'd'], ['CertificateArn' => 'e']]]),
            self::certificate('a', ['Status' => 'PENDING_VALIDATION', 'NotAfter' => null]),
            self::certificate('b', ['NotAfter' => self::NOW + 29 * 86400]),
            self::certificate('c', ['NotAfter' => self::NOW + 30 * 86400]),
            self::certificate('d', ['NotAfter' => self::NOW - 5 * 86400]),
            self::certificate('e', ['NotAfter' => self::NOW + 86400 + 60]),
        );
        [$a, $b, $c, $d, $e] = $this->certificates()->list($this->mocks->clients());

        self::assertSame([['key' => 'resources.warning.certificate_not_issued', 'params' => ['status' => 'PENDING_VALIDATION']]], $a->warnings);
        self::assertNull($a->details['expires']);
        self::assertSame([['key' => 'resources.warning.certificate_expiring', 'params' => ['days' => '29']]], $b->warnings);
        self::assertSame([], $c->warnings);
        self::assertSame([['key' => 'resources.warning.certificate_expired', 'params' => []]], $d->warnings);
        self::assertSame([['key' => 'resources.warning.certificate_expiring_one_day', 'params' => []]], $e->warnings);
    }

    public function test_certificate_deleted_while_listing_is_skipped(): void
    {
        $this->mocks->handler->append(
            new Result(['CertificateSummaryList' => [['CertificateArn' => 'gone'], ['CertificateArn' => 'kept']]]),
            AwsMocks::error(AcmException::class, 'DescribeCertificate', 'ResourceNotFoundException', 400),
            self::certificate('kept'),
        );
        $items = $this->certificates()->list($this->mocks->clients(overrides: ['retries' => 0]));

        self::assertSame(['kept'], array_map(fn ($i) => $i->id, $items));
    }

    public function test_other_describe_errors_fail_the_type(): void
    {
        $this->mocks->handler->append(
            new Result(['CertificateSummaryList' => [['CertificateArn' => 'a']]]),
            AwsMocks::error(AcmException::class, 'DescribeCertificate', 'AccessDeniedException', 400),
        );
        $this->expectException(AcmException::class);
        $this->certificates()->list($this->mocks->clients(overrides: ['retries' => 0]));
    }

    public function test_zones_from_two_profiles(): void
    {
        $deploy = new AwsMocks();
        $dns = new AwsMocks();
        $deploy->handler->append(new Result(['HostedZones' => [
            ['Id' => '/hostedzone/Z1', 'Name' => 'example.com.', 'ResourceRecordSetCount' => 7, 'Config' => ['PrivateZone' => false]],
            ['Id' => '/hostedzone/Z3', 'Name' => 'shared.com.', 'ResourceRecordSetCount' => 2, 'Config' => ['PrivateZone' => false]],
        ]]));
        $dns->handler->append(new Result(['HostedZones' => [
            ['Id' => '/hostedzone/Z2', 'Name' => 'dns.example.org.', 'ResourceRecordSetCount' => 12, 'Config' => ['PrivateZone' => false]],
            ['Id' => '/hostedzone/Z3', 'Name' => 'shared.com.', 'ResourceRecordSetCount' => 2, 'Config' => ['PrivateZone' => false]],
        ]]));

        $items = (new ZoneLister($dns->clients(), 'deploy', 'dns'))->list($deploy->clients());

        self::assertSame(['Z1', 'Z2', 'Z3'], array_map(fn ($i) => $i->id, $items));
        self::assertSame(['ListHostedZones'], $deploy->calls);
        self::assertSame(['ListHostedZones'], $dns->calls);
        self::assertSame(ResourceType::Zone, $items[0]->type);
        self::assertSame('example.com', $items[0]->name);
        self::assertSame(['name' => 'example.com', 'private' => false, 'records' => 7, 'account' => 'deploy'], $items[0]->details);
        self::assertSame('dns', $items[1]->details['account']);
        self::assertSame(12, $items[1]->details['records']);
        self::assertSame('deploy', $items[2]->details['account']);
        self::assertNull($items[0]->apply);
    }

    public function test_zones_without_dns_profile(): void
    {
        $this->mocks->handler->append(new Result(['HostedZones' => [['Id' => '/hostedzone/Z1', 'Name' => 'example.com.', 'ResourceRecordSetCount' => 3]]]));
        $items = (new ZoneLister(null, 'default', null))->list($this->mocks->clients());

        self::assertCount(1, $items);
        self::assertSame('default', $items[0]->details['account']);
        self::assertFalse($items[0]->details['private']);
        self::assertSame(['ListHostedZones'], $this->mocks->calls);
    }

    public function test_private_zone_warning(): void
    {
        $this->mocks->handler->append(new Result(['HostedZones' => [
            ['Id' => '/hostedzone/ZP', 'Name' => 'internal.', 'ResourceRecordSetCount' => 2, 'Config' => ['PrivateZone' => true]],
        ]]));
        [$zone] = (new ZoneLister(null, 'default', null))->list($this->mocks->clients());

        self::assertTrue($zone->details['private']);
        self::assertSame([['key' => 'resources.warning.zone_private', 'params' => []]], $zone->warnings);
    }

    public function test_certificates_and_zones_paginate(): void
    {
        $this->mocks->handler->append(
            new Result(['CertificateSummaryList' => [['CertificateArn' => 'a']], 'NextToken' => 'n1']),
            new Result(['CertificateSummaryList' => [['CertificateArn' => 'b']]]),
            self::certificate('a'),
            self::certificate('b'),
            new Result(['HostedZones' => [['Id' => '/hostedzone/Z1', 'Name' => 'a.com.', 'ResourceRecordSetCount' => 1]], 'IsTruncated' => true, 'NextMarker' => 'm1']),
            new Result(['HostedZones' => [['Id' => '/hostedzone/Z2', 'Name' => 'b.com.', 'ResourceRecordSetCount' => 1]], 'IsTruncated' => false]),
        );
        $aws = $this->mocks->clients();
        $certificates = $this->certificates()->list($aws);
        $zones = (new ZoneLister(null, 'default', null))->list($aws);

        self::assertSame(['a', 'b'], array_map(fn ($i) => $i->id, $certificates));
        self::assertSame(['Z1', 'Z2'], array_map(fn ($i) => $i->id, $zones));
        self::assertSame(['ListCertificates', 'ListCertificates', 'DescribeCertificate', 'DescribeCertificate', 'ListHostedZones', 'ListHostedZones'], $this->mocks->calls);
        self::assertSame('n1', $this->mocks->params[1]['NextToken']);
        self::assertSame('m1', $this->mocks->params[5]['Marker']);
    }
}
