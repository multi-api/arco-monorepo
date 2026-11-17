<?php declare(strict_types=1);

namespace Multiapi\Arco\Laravel\TenantDomains;

final readonly class TenantDomainStatus
{
    public const PENDING = 'pending';
    public const ACTIVE = 'active';
    public const FAILED = 'failed';
    public const STATES = [self::PENDING, self::ACTIVE, self::FAILED];

    public function __construct(
        public string $domain,
        public string $state,
        public ?string $reason = null,
    ) {
    }

    public function isActive(): bool
    {
        return $this->state === self::ACTIVE;
    }

    public function isPending(): bool
    {
        return $this->state === self::PENDING;
    }

    public function isFailed(): bool
    {
        return $this->state === self::FAILED;
    }
}
