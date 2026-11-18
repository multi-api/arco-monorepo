<?php declare(strict_types=1);

namespace Multiapi\Arco\Laravel\TenantDomains;

use Multiapi\Arco\Laravel\Contracts\TenantDomains;
use Multiapi\Arco\Laravel\TenantDomains\Exceptions\InvalidTenantDomain;
use Multiapi\Arco\Laravel\TenantDomains\Exceptions\TenantDomainNotFound;
use PHPUnit\Framework\Assert;

/** In-memory tenant domains for tests: never touches AWS and works without ARCO=1. */
final class FakeTenantDomains implements TenantDomains
{
    /** @var array<string, TenantDomainStatus> insertion order */
    private array $domains = [];
    /** @var list<string> */
    private array $added = [];
    /** @var list<string> */
    private array $removed = [];

    public function add(string $domain): TenantDomainStatus
    {
        $domain = self::normalize($domain);
        $this->added[] = $domain;
        return $this->domains[$domain] ??= new TenantDomainStatus($domain, TenantDomainStatus::PENDING);
    }

    public function status(string $domain): TenantDomainStatus
    {
        $domain = self::normalize($domain);
        return $this->domains[$domain] ?? throw new TenantDomainNotFound("This environment has no domain $domain.");
    }

    public function list(?string $cursor = null, int $limit = 50): TenantDomainPage
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidTenantDomain('The limit must be between 1 and 100.', 'invalid_request');
        }
        $offset = 0;
        if ($cursor !== null) {
            $decoded = base64_decode($cursor, true);
            if ($decoded === false || preg_match('/^fake:(\d+)$/', $decoded, $match) !== 1) {
                throw new InvalidTenantDomain('The cursor is malformed.', 'invalid_request');
            }
            $offset = (int) $match[1];
        }
        $all = array_values($this->domains);
        $page = array_slice($all, $offset, $limit);
        $next = $offset + $limit;
        return new TenantDomainPage($page, $next < count($all) ? base64_encode("fake:$next") : null);
    }

    public function remove(string $domain): void
    {
        $domain = self::normalize($domain);
        $this->removed[] = $domain;
        unset($this->domains[$domain]);
    }

    /** Marks an added domain active (DNS and certificate in place). */
    public function activate(string $domain): void
    {
        $this->set($domain, TenantDomainStatus::ACTIVE, null);
    }

    /** Marks an added domain failed. */
    public function fail(string $domain, string $reason): void
    {
        $this->set($domain, TenantDomainStatus::FAILED, $reason);
    }

    public function assertAdded(string $domain): void
    {
        Assert::assertContains(self::normalize($domain), $this->added, "Failed asserting that $domain was added.");
    }

    public function assertRemoved(string $domain): void
    {
        Assert::assertContains(self::normalize($domain), $this->removed, "Failed asserting that $domain was removed.");
    }

    public function assertNothingAdded(): void
    {
        Assert::assertSame([], $this->added, 'Failed asserting that no tenant domain was added.');
    }

    private function set(string $domain, string $state, ?string $reason): void
    {
        $domain = self::normalize($domain);
        if (! isset($this->domains[$domain])) {
            throw new TenantDomainNotFound("This environment has no domain $domain.");
        }
        $this->domains[$domain] = new TenantDomainStatus($domain, $state, $reason);
    }

    private static function normalize(string $domain): string
    {
        return rtrim(strtolower(trim($domain)), '.');
    }
}
