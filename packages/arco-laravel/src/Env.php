<?php declare(strict_types=1);

namespace Multiapi\Arco\Laravel;

/** @internal Reads a process environment variable the way Lambda and the console runtime set it. */
final class Env
{
    public static function get(string $name): ?string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);
        return is_string($value) && $value !== '' ? $value : null;
    }
}
