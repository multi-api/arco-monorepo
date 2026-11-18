<?php declare(strict_types=1);

namespace Multiapi\Arco\Laravel\TenantDomains;

final readonly class TenantDomainPage
{
    /**
     * @param list<TenantDomainStatus> $domains
     * @param string|null $cursor opaque; pass it back to list() for the next page; null is the end
     */
    public function __construct(
        public array $domains,
        public ?string $cursor,
    ) {
    }
}
