<?php declare(strict_types=1);

namespace Multiapi\Arco\Laravel\TenantDomains\Exceptions;

/** The domain is disabled but CloudFront does not allow deleting it yet: call remove() again later. */
final class TenantDomainRemovalPending extends TenantDomainException
{
    public function __construct(string $message, string $errorCode = 'removal_pending', ?\Throwable $previous = null)
    {
        parent::__construct($message, $errorCode, $previous);
    }
}
