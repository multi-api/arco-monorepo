<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Support;

/**
 * A random port for a local emulator. Under ParaTest each worker (TEST_TOKEN) draws from its own slice of the
 * range, so two workers never start servers on the same port: the emulators' "did my server answer, or another
 * process on that port?" check can be fooled while the losing server is still exiting.
 */
final class TestPorts
{
    private const SLICES = 10;

    public static function random(int $from, int $to): int
    {
        $token = getenv('TEST_TOKEN');
        if (! is_string($token) || preg_match('/^\d+$/D', $token) !== 1) {
            return random_int($from, $to);
        }
        $size = intdiv($to - $from + 1, self::SLICES);
        $start = $from + ((int) $token % self::SLICES) * $size;

        return random_int($start, $start + $size - 1);
    }
}
