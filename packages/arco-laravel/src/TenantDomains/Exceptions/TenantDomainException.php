<?php declare(strict_types=1);

namespace Multiapi\Arco\Laravel\TenantDomains\Exceptions;

/** A tenant domain operation failed; $errorCode is the mediator's code (`failed` for anything unexpected). */
class TenantDomainException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'failed', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
