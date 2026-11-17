<?php declare(strict_types=1);

namespace Multiapi\Arco\Laravel\TenantDomains\Exceptions;

/** The domain does not point to the target; \$expected is what its DNS must lead to. */
final class TenantDomainDnsIncorrect extends TenantDomainException
{
    public function __construct(string $message, public readonly ?string $expected = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 'dns_incorrect', $previous);
    }
}
