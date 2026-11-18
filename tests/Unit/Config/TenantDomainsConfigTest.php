<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Config;

use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Config\Environment;
use Multiapi\Arco\Config\Validator;
use Multiapi\Arco\Tests\Support\TempPaths;
use PHPUnit\Framework\TestCase;

final class TenantDomainsConfigTest extends TestCase
{
    /** @return list<array{key: string, params: array<string, string|int>}> */
    private function errors(App $app): array
    {
        return (new Validator())->validate(ConfigResolver::resolve($app->toArray(), 'production'), TempPaths::path('arco-tenant-config-'))->errors;
    }

    public function test_target_is_inherited_and_can_be_replaced_per_environment(): void
    {
        $raw = App::make('shop')->region('us-east-1')
            ->tenantDomains(target: 'routing.example.com')
            ->environment('staging')
            ->environment('production', fn (Environment $e) => $e->tenantDomains(target: 'shops.example.com'))
            ->toArray();

        $staging = ConfigResolver::resolve($raw, 'staging');
        $production = ConfigResolver::resolve($raw, 'production');
        self::assertSame('routing.example.com', $staging->tenantDomainTarget);
        self::assertSame('app', $staging->sources['tenantDomainTarget']);
        self::assertSame('shops.example.com', $production->tenantDomainTarget);
        self::assertSame('environment', $production->sources['tenantDomainTarget']);
        self::assertSame('shops.example.com', $production->withAccount('123456789012')->tenantDomainTarget);
    }

    public function test_valid_target_supports_local_and_external_route53(): void
    {
        $app = App::make('shop')->region('us-east-1')->domain('shop.example.com')->tenantDomains('routing.example.com')->environment('production');
        self::assertSame([], $this->errors($app));
        self::assertSame([], $this->errors($app->dns('common')));
    }

    public function test_invalid_target_is_rejected(): void
    {
        foreach (['', 'localhost', '*.example.com', 'https://example.com', 'Routing.example.com', '-a.example.com', "routing.example.com\n"] as $target) {
            $app = App::make('shop')->region('us-east-1')->tenantDomains($target)->environment('production');
            self::assertContains(['key' => 'validation.tenant_domain_target', 'params' => ['target' => $target]], $this->errors($app));
        }
    }

    public function test_target_must_not_be_a_declared_domain(): void
    {
        $app = App::make('shop')->region('us-east-1')->domain('routing.example.com')
            ->environment('production', fn (Environment $e) => $e->tenantDomains('routing.example.com'));
        self::assertContains(['key' => 'validation.tenant_domain_conflict', 'params' => ['target' => 'routing.example.com']], $this->errors($app));
    }

    public function test_tenant_targets_accept_cloudflare_dns(): void
    {
        $app = App::make('shop')->region('us-east-1')->domain('shop.example.com')->dns('cloudflare')
            ->tenantDomains('routing.example.com')->environment('production');
        self::assertSame([], $this->errors($app));
    }

    public function test_tenant_targets_accept_manual_dns(): void
    {
        $app = App::make('shop')->region('us-east-1')->domain('shop.example.com')->dns(false)
            ->tenantDomains('routing.example.com')->environment('production');
        self::assertSame([], $this->errors($app));
    }

    public function test_tenant_domains_need_a_domain_that_locks_the_endpoint(): void
    {
        $app = App::make('shop')->region('us-east-1')->tenantDomains('routing.example.com')->environment('production');
        self::assertSame([['key' => 'validation.tenant_domain_requires_domain', 'params' => ['target' => 'routing.example.com']]], $this->errors($app));
    }
}
