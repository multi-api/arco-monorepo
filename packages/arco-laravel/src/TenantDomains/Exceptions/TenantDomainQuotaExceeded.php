<?php declare(strict_types=1);

namespace Multiapi\Arco\Laravel\TenantDomains\Exceptions;

/** CloudFront refused the creation for a limit. */
final class TenantDomainQuotaExceeded extends TenantDomainException
{
    public function __construct(string $message, string $errorCode = 'quota', ?\Throwable $previous = null)
    {
        parent::__construct($message, $errorCode, $previous);
    }
}
