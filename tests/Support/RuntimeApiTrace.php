<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Support;

/** The Lambda-Runtime-Trace-Id the Runtime API emulator sends with an invocation: one X-Ray trace per id. */
final class RuntimeApiTrace
{
    public static function root(string $id): string
    {
        return '1-6543210f-' . substr(hash('sha256', $id), 0, 24);
    }

    public static function header(string $id): string
    {
        return 'Root=' . self::root($id) . ';Parent=' . substr(hash('sha256', "parent-$id"), 0, 16) . ';Sampled=0';
    }
}
