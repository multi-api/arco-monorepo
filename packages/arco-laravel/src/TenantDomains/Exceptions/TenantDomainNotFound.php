<?php declare(strict_types=1);

namespace Multiapi\Arco\Laravel\TenantDomains\Exceptions;

/** This environment has no such domain. */
final class TenantDomainNotFound extends TenantDomainException
{
    public function __construct(string $message, string $errorCode = 'not_found', ?\Throwable $previous = null)
    {
        parent::__construct($message, $errorCode, $previous);
    }
}
