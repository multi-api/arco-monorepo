<?php declare(strict_types=1);

namespace Multiapi\Arco\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Multiapi\Arco\Laravel\Contracts\TenantDomains as TenantDomainsContract;
use Multiapi\Arco\Laravel\TenantDomains\FakeTenantDomains;

/**
 * @method static \Multiapi\Arco\Laravel\TenantDomains\TenantDomainStatus add(string $domain)
 * @method static \Multiapi\Arco\Laravel\TenantDomains\TenantDomainStatus status(string $domain)
 * @method static \Multiapi\Arco\Laravel\TenantDomains\TenantDomainPage list(?string $cursor = null, int $limit = 50)
 * @method static void remove(string $domain)
 * @method static void assertAdded(string $domain) only on the fake
 * @method static void assertRemoved(string $domain) only on the fake
 * @method static void assertNothingAdded() only on the fake
 *
 * @see TenantDomainsContract
 */
final class TenantDomains extends Facade
{
    /** Replaces the service with an in-memory fake (facade calls and injection alike) and returns it. */
    public static function fake(): FakeTenantDomains
    {
        $fake = new FakeTenantDomains();
        static::swap($fake);
        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return TenantDomainsContract::class;
    }
}
