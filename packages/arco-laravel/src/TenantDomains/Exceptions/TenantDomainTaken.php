<?php declare(strict_types=1);

namespace Multiapi\Arco\Laravel\TenantDomains\Exceptions;

/** CloudFront has the domain on a tenant or distribution that is not this environment's. */
final class TenantDomainTaken extends TenantDomainException
{
    public function __construct(string $message, string $errorCode = 'domain_taken', ?\Throwable $previous = null)
    {
        parent::__construct($message, $errorCode, $previous);
    }
}
