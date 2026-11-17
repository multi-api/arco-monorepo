<?php declare(strict_types=1);

namespace Multiapi\Arco\Laravel\TenantDomains\Exceptions;

/** invalid_domain, apex_not_supported, reserved_domain or invalid_request. */
final class InvalidTenantDomain extends TenantDomainException
{
    public function __construct(string $message, string $errorCode = 'invalid_request', ?\Throwable $previous = null)
    {
        parent::__construct($message, $errorCode, $previous);
    }
}
