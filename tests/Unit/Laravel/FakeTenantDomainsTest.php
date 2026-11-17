<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Laravel;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Multiapi\Arco\Laravel\ArcoServiceProvider;
use Multiapi\Arco\Laravel\Facades\TenantDomains;
use Multiapi\Arco\Laravel\Contracts\TenantDomains as TenantDomainsContract;
use Multiapi\Arco\Laravel\TenantDomains\Exceptions\InvalidTenantDomain;
use Multiapi\Arco\Laravel\TenantDomains\Exceptions\TenantDomainNotFound;
use Multiapi\Arco\Laravel\TenantDomains\FakeTenantDomains;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

final class FakeTenantDomainsTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        unset($_SERVER['ARCO']);
        $this->app = new Application(sys_get_temp_dir());
        (new ArcoServiceProvider($this->app))->register();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
    }

    public function test_fake_is_shared_by_the_facade_and_container_injection_without_arco(): void
    {
        $fake = TenantDomains::fake();

        self::assertSame($fake, $this->app->make(TenantDomainsContract::class));
        TenantDomains::add('Shop.Customer.com.');
        $fake->assertAdded('shop.customer.com');
        self::assertSame('shop.customer.com', $this->app->make(TenantDomainsContract::class)->status('shop.customer.com')->domain);
    }

    public function test_assertions_pass_and_fail(): void
    {
        $fake = TenantDomains::fake();
        $fake->assertNothingAdded();
        try {
            $fake->assertAdded('a.example.com');
            self::fail('Expected a failure');
        } catch (AssertionFailedError) {
            self::assertTrue(true);
        }

        $fake->add('a.example.com');
        $fake->assertAdded('A.example.com');
        try {
            $fake->assertNothingAdded();
            self::fail('Expected a failure');
        } catch (AssertionFailedError) {
            self::assertTrue(true);
        }

        try {
            $fake->assertRemoved('a.example.com');
            self::fail('Expected a failure');
        } catch (AssertionFailedError) {
            self::assertTrue(true);
        }
        $fake->remove('a.example.com');
        $fake->assertRemoved('a.example.com');
    }

    public function test_status_of_an_unknown_domain_is_not_found(): void
    {
        $this->expectException(TenantDomainNotFound::class);
        (new FakeTenantDomains())->status('nope.example.com');
    }

    public function test_add_is_idempotent_and_keeps_the_current_state(): void
    {
        $fake = new FakeTenantDomains();
        self::assertTrue($fake->add('a.example.com')->isPending());
        $fake->activate('a.example.com');
        self::assertTrue($fake->add('a.example.com')->isActive());
        self::assertCount(1, $fake->list()->domains);
    }

    public function test_activate_and_fail_change_status_and_list(): void
    {
        $fake = new FakeTenantDomains();
        $fake->add('a.example.com');
        $fake->add('b.example.com');
        $fake->activate('a.example.com');
        $fake->fail('b.example.com', 'bad dns');

        self::assertTrue($fake->status('a.example.com')->isActive());
        self::assertSame('bad dns', $fake->status('b.example.com')->reason);
        $states = array_map(static fn ($s) => $s->state, $fake->list()->domains);
        self::assertSame(['active', 'failed'], $states);
    }

    public function test_activating_an_unknown_domain_is_not_found(): void
    {
        $this->expectException(TenantDomainNotFound::class);
        (new FakeTenantDomains())->activate('nope.example.com');
    }

    public function test_list_paginates_in_insertion_order_and_ends_with_a_null_cursor(): void
    {
        $fake = new FakeTenantDomains();
        foreach (['a', 'b', 'c', 'd', 'e'] as $name) {
            $fake->add("$name.example.com");
        }

        $seen = [];
        $cursor = null;
        $pages = 0;
        do {
            $page = $fake->list($cursor, 2);
            $pages++;
            foreach ($page->domains as $status) {
                $seen[] = $status->domain;
            }
            $cursor = $page->cursor;
        } while ($cursor !== null);

        self::assertSame(3, $pages);
        self::assertSame(['a.example.com', 'b.example.com', 'c.example.com', 'd.example.com', 'e.example.com'], $seen);
        self::assertNull($fake->list(null, 5)->cursor);
        self::assertSame([], (new FakeTenantDomains())->list()->domains);
    }

    public function test_list_validates_limit_and_cursor(): void
    {
        $fake = new FakeTenantDomains();
        foreach ([0, 101] as $limit) {
            try {
                $fake->list(null, $limit);
                self::fail('Expected an exception');
            } catch (InvalidTenantDomain $e) {
                self::assertSame('invalid_request', $e->errorCode);
            }
        }
        $this->expectException(InvalidTenantDomain::class);
        $fake->list('garbage');
    }

    public function test_remove_is_idempotent_and_deletes(): void
    {
        $fake = new FakeTenantDomains();
        $fake->add('a.example.com');
        $fake->remove('a.example.com');
        $fake->remove('a.example.com');
        $fake->remove('never.example.com');

        self::assertSame([], $fake->list()->domains);
        $fake->assertRemoved('never.example.com');
    }
}
