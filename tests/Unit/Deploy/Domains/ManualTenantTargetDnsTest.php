<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy\Domains;

use Multiapi\Arco\Deploy\DeployException;
use Multiapi\Arco\Deploy\Domains\ManualTenantTargetDns;
use PHPUnit\Framework\TestCase;

final class ManualTenantTargetDnsTest extends TestCase
{
    public function test_preflight_accepts_a_target_below_its_public_zone(): void
    {
        $dns = new ManualTenantTargetDns(fn (): false => false, fn (): string => 'example.com');

        self::assertSame('example.com', $dns->preflight('routing.example.com'));
    }

    public function test_preflight_rejects_the_public_zone_apex(): void
    {
        $dns = new ManualTenantTargetDns(fn (): false => false, fn (): string => 'example.com');

        $this->expectException(DeployException::class);
        $this->expectExceptionMessage('domains.tenant_target_apex');
        $dns->preflight('example.com');
    }

    public function test_preflight_rejects_an_uncertain_public_zone(): void
    {
        $dns = new ManualTenantTargetDns(fn (): false => false, fn (): false => false);

        $this->expectException(DeployException::class);
        $this->expectExceptionMessage('domains.tenant_target_zone_missing');
        $dns->preflight('routing.example.com');
    }

    public function test_public_cname_must_point_directly_to_the_routing_endpoint(): void
    {
        $queries = [];
        $dns = new ManualTenantTargetDns(function (string $name) use (&$queries): array {
            $queries[] = $name;
            return [['host' => $name, 'type' => 'CNAME', 'target' => 'endpoint.cloudfront.net.']];
        });

        $dns->verify('routing.example.com', 'endpoint.cloudfront.net');
        self::assertSame(['routing.example.com'], $queries);
    }

    public function test_missing_cname_reports_the_exact_expected_record(): void
    {
        $dns = new ManualTenantTargetDns(fn (): array => []);

        try {
            $dns->verify('routing.example.com', 'endpoint.cloudfront.net');
            self::fail('An absent CNAME must not be reported as a successful deployment.');
        } catch (DeployException $exception) {
            self::assertSame('domains.tenant_manual_cname_missing', $exception->key);
            self::assertSame(['target' => 'routing.example.com', 'endpoint' => 'endpoint.cloudfront.net'], $exception->params);
        }
    }

    public function test_foreign_cname_is_not_accepted(): void
    {
        $dns = new ManualTenantTargetDns(fn (string $name): array => [
            ['host' => $name, 'type' => 'CNAME', 'target' => 'other.cloudfront.net'],
        ]);

        $this->expectException(DeployException::class);
        $this->expectExceptionMessage('domains.tenant_manual_cname_missing');
        $dns->verify('routing.example.com', 'endpoint.cloudfront.net');
    }

    public function test_dns_failure_does_not_confirm_the_record(): void
    {
        $dns = new ManualTenantTargetDns(fn (): false => false);

        $this->expectException(DeployException::class);
        $this->expectExceptionMessage('domains.tenant_manual_cname_missing');
        $dns->verify('routing.example.com', 'endpoint.cloudfront.net');
    }

    public function test_record_for_a_different_hostname_does_not_confirm_the_target(): void
    {
        $dns = new ManualTenantTargetDns(fn (): array => [
            ['host' => 'other.example.com', 'type' => 'CNAME', 'target' => 'endpoint.cloudfront.net'],
        ]);

        $this->expectException(DeployException::class);
        $this->expectExceptionMessage('domains.tenant_manual_cname_missing');
        $dns->verify('routing.example.com', 'endpoint.cloudfront.net');
    }

    public function test_multiple_results_are_not_assumed_to_be_an_exact_cname(): void
    {
        $dns = new ManualTenantTargetDns(fn (string $name): array => [
            ['host' => $name, 'type' => 'CNAME', 'target' => 'endpoint.cloudfront.net'],
            ['host' => $name, 'type' => 'CNAME', 'target' => 'other.cloudfront.net'],
        ]);

        $this->expectException(DeployException::class);
        $this->expectExceptionMessage('domains.tenant_manual_cname_missing');
        $dns->verify('routing.example.com', 'endpoint.cloudfront.net');
    }

    /** @param array<string, list<array<string, string>>|false> $answers by record type */
    private static function manual(array $answers): ManualTenantTargetDns
    {
        return new ManualTenantTargetDns(
            fn (string $name): array|false => $answers['CNAME'] ?? [],
            fn (): string => 'example.com',
            fn (string $name, string $type): array|false => $answers[$type] ?? [],
        );
    }

    public function test_check_accepts_a_target_without_public_records(): void
    {
        $queries = [];
        $dns = new ManualTenantTargetDns(fn (): array => [], fn (): string => 'example.com', function (string $name, string $type) use (&$queries): array {
            $queries[] = "$name $type";
            return [];
        });

        $dns->check('routing.example.com', null);

        self::assertSame(['routing.example.com CNAME', 'routing.example.com A', 'routing.example.com AAAA'], $queries);
    }

    public function test_check_rejects_the_apex_before_any_record_lookup(): void
    {
        $dns = new ManualTenantTargetDns(fn (): array => [], fn (): string => 'example.com', fn (): array => self::fail('no lookup for an apex'));

        $this->expectException(DeployException::class);
        $this->expectExceptionMessage('domains.tenant_target_apex');
        $dns->check('example.com', null);
    }

    public function test_check_rejects_a_target_that_already_resolves(): void
    {
        $cname = [['host' => 'routing.example.com.', 'type' => 'CNAME', 'target' => 'endpoint.cloudfront.net.']];
        foreach ([
            'a cname before the environment has an endpoint' => [['CNAME' => $cname], null],
            'a cname to another endpoint' => [['CNAME' => $cname], 'mine.cloudfront.net'],
            'two cnames' => [['CNAME' => [...$cname, ...$cname]], 'endpoint.cloudfront.net'],
            'a cname of another name' => [['CNAME' => [['host' => 'x.example.com.', 'type' => 'CNAME', 'target' => 'endpoint.cloudfront.net.']]], 'endpoint.cloudfront.net'],
            'an address record' => [['A' => [['host' => 'routing.example.com.', 'type' => 'A', 'target' => '192.0.2.1']]], 'endpoint.cloudfront.net'],
            'an ipv6 address record' => [['AAAA' => [['host' => 'routing.example.com.', 'type' => 'AAAA', 'target' => '2001:db8::1']]], null],
        ] as $case => [$answers, $owned]) {
            try {
                self::manual($answers)->check('routing.example.com', $owned);
                self::fail("$case must be rejected");
            } catch (DeployException $exception) {
                self::assertSame('domains.tenant_target_occupied', $exception->key, $case);
            }
        }
    }

    public function test_check_accepts_only_the_exact_cname_of_the_verified_endpoint(): void
    {
        $cname = [['host' => 'routing.example.com.', 'type' => 'CNAME', 'target' => 'endpoint.cloudfront.net.']];

        self::manual(['CNAME' => $cname])->check('routing.example.com', 'endpoint.cloudfront.net');
        $this->addToAssertionCount(1);
    }

    public function test_check_fails_closed_when_public_dns_cannot_be_read(): void
    {
        foreach ([['CNAME' => false], ['A' => false], ['AAAA' => false]] as $answers) {
            try {
                self::manual($answers)->check('routing.example.com', null);
                self::fail('an unreadable answer is not a free target');
            } catch (DeployException $exception) {
                self::assertSame('domains.tenant_target_unverified', $exception->key);
                self::assertSame(['target' => 'routing.example.com'], $exception->params);
            }
        }
        try {
            (new ManualTenantTargetDns(fn (): array => [], fn (): string => 'example.com'))->check('routing.example.com', null);
            self::fail('without a record lookup nothing is verified');
        } catch (DeployException $exception) {
            self::assertSame('domains.tenant_target_unverified', $exception->key);
        }
    }

    public function test_publish_only_verifies_the_record_the_user_creates(): void
    {
        $cname = [['host' => 'routing.example.com.', 'type' => 'CNAME', 'target' => 'endpoint.cloudfront.net.']];
        self::manual(['CNAME' => $cname])->publish('routing.example.com', 'endpoint.cloudfront.net', null);

        try {
            self::manual([])->publish('routing.example.com', 'endpoint.cloudfront.net', null);
            self::fail('a missing record must stop the deploy');
        } catch (DeployException $exception) {
            self::assertSame('domains.tenant_manual_cname_missing', $exception->key);
        }
        try {
            self::manual(['CNAME' => $cname])->publish('routing.example.com', 'new.cloudfront.net', 'endpoint.cloudfront.net');
            self::fail('a changed endpoint must stop the deploy');
        } catch (DeployException $exception) {
            self::assertSame('domains.tenant_target_change', $exception->key);
        }
    }
}
