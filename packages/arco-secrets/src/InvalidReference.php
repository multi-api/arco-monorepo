<?php declare(strict_types=1);

namespace Multiapi\Arco\Secrets;

use InvalidArgumentException;

final class InvalidReference extends InvalidArgumentException
{
    public static function notAReference(string $value): self
    {
        return new self(sprintf('"%s" is not an arco-ssm: or arco-secret: reference.', $value));
    }

    public static function malformed(string $value, string $reason): self
    {
        return new self(sprintf('Invalid secret reference "%s": %s', $value, $reason));
    }
}
