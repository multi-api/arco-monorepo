<?php declare(strict_types=1);

namespace Multiapi\Arco\Laravel\TenantDomains\Exceptions;

/** Not running on Arco, tenantDomains() is not configured, or the mediator is not usable. Not the same as not found. */
final class TenantDomainsUnavailable extends TenantDomainException
{
    public function __construct(string $message, string $errorCode = 'unavailable', ?\Throwable $previous = null)
    {
        parent::__construct($message, $errorCode, $previous);
    }
}
