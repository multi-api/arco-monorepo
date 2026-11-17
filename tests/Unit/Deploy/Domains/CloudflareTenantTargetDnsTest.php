<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy\Domains;

use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Deploy\DeployException;
use Multiapi\Arco\Deploy\Domains\CloudflareDns;
use Multiapi\Arco\Deploy\Domains\CloudflareTenantTargetDns;
use PHPUnit\Framework\TestCase;

final class CloudflareTenantTargetDnsTest extends TestCase
{
    private const TARGET = 'routing.example.com';
    private const ENDPOINT = 'endpoint.cloudfront.net';
    private const MARKER = 'managed by arco: shop/production';

    public function test_apex_is_rejected_before_writing(): void
    {
        $cf = (new FakeCloudflare())->zone('example.com', 'z1');
        $dns = new CloudflareTenantTargetDns(new CloudflareDns($cf->api()), self::owner('example.com'));

        try {
            $dns->preflight('example.com');
            self::fail('An apex cannot be used as a tenant target.');
        } catch (DeployException $exception) {
            self::assertSame('domains.tenant_target_apex', $exception->key);
            self::assertSame([], $cf->writes());
        }
    }

    public function test_publish_creates_an_owned_dns_only_cname(): void
    {
        $cf = (new FakeCloudflare())->zone('example.com', 'z1');
        $dns = new CloudflareTenantTargetDns(new CloudflareDns($cf->api()), self::owner(self::TARGET));

        $dns->publish(self::TARGET, self::ENDPOINT);

        $records = $cf->named(self::TARGET);
        self::assertCount(1, $records);
        self::assertSame(self::ENDPOINT, $records[0]['content']);
        self::assertSame(self::MARKER, $records[0]['comment']);
        self::assertFalse($records[0]['proxied']);
    }

    public function test_a_foreign_cname_is_not_overwritten_even_with_force(): void
    {
        $cf = (new FakeCloudflare())->zone('example.com', 'z1');
        $cf->record('z1', self::TARGET, 'CNAME', self::ENDPOINT, 'another owner');
        $dns = new CloudflareTenantTargetDns(new CloudflareDns($cf->api(), true), self::owner(self::TARGET));

        try {
            $dns->publish(self::TARGET, self::ENDPOINT);
            self::fail('A foreign record must not be overwritten.');
        } catch (DeployException $exception) {
            self::assertSame('domains.tenant_target_occupied', $exception->key);
            self::assertSame([], $cf->writes());
        }
    }

    public function test_an_existing_record_requires_a_verified_endpoint_even_with_our_marker(): void
    {
        $cf = (new FakeCloudflare())->zone('example.com', 'z1');
        $cf->record('z1', self::TARGET, 'CNAME', self::ENDPOINT, self::MARKER);
        $dns = new CloudflareTenantTargetDns(new CloudflareDns($cf->api()), self::owner(self::TARGET));

        $this->expectException(DeployException::class);
        $this->expectExceptionMessage('domains.tenant_target_occupied');
        $dns->publish(self::TARGET, self::ENDPOINT);
    }

    public function test_a_proxied_verified_target_is_refused_without_modifying_it(): void
    {
        $cf = (new FakeCloudflare())->zone('example.com', 'z1');
        $cf->record('z1', self::TARGET, 'CNAME', self::ENDPOINT, self::MARKER, ['proxied' => true]);
        $dns = new CloudflareTenantTargetDns(new CloudflareDns($cf->api()), self::owner(self::TARGET));

        try {
            $dns->publish(self::TARGET, self::ENDPOINT, self::ENDPOINT);
            self::fail('A proxied target requires a safe manual correction.');
        } catch (DeployException $exception) {
            self::assertSame('domains.tenant_target_proxied', $exception->key);
            self::assertTrue($cf->named(self::TARGET)[0]['proxied']);
            self::assertSame([], $cf->writes());
        }
    }

    public function test_a_proxied_verified_target_is_rejected_at_preflight(): void
    {
        $cf = (new FakeCloudflare())->zone('example.com', 'z1');
        $cf->record('z1', self::TARGET, 'CNAME', self::ENDPOINT, self::MARKER, ['proxied' => true]);
        $dns = new CloudflareTenantTargetDns(new CloudflareDns($cf->api()), self::owner(self::TARGET));

        $this->expectException(DeployException::class);
        $this->expectExceptionMessage('domains.tenant_target_proxied');
        $dns->preflight(self::TARGET, self::ENDPOINT);
    }

    public function test_an_existing_endpoint_cannot_be_changed_during_publish(): void
    {
        $cf = (new FakeCloudflare())->zone('example.com', 'z1');
        $dns = new CloudflareTenantTargetDns(new CloudflareDns($cf->api()), self::owner(self::TARGET));

        try {
            $dns->publish(self::TARGET, 'new.cloudfront.net', self::ENDPOINT);
            self::fail('A changed endpoint requires tenant reconciliation.');
        } catch (DeployException $exception) {
            self::assertSame('domains.tenant_target_change', $exception->key);
            self::assertSame([], $cf->writes());
        }
    }

    private static function owner(string $target): AppConfig
    {
        return ConfigResolver::resolve(App::make('shop')->region('us-east-1')->tenantDomains($target)
            ->dns('cloudflare')->environment('production')->toArray(), 'production');
    }
}