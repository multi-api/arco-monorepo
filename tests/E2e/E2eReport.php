<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\E2e;

/** The progress lines of the end-to-end suites. */
final class E2eReport
{
    /** One line; without a duration (a check that never ran) it prints a dash, never an epoch-sized time. */
    public static function line(string $check, ?float $seconds, string $result): string
    {
        $time = $seconds === null || $seconds < 0 ? sprintf('%7s', '-') : sprintf('%7.1f s', $seconds);
        return sprintf("[e2e] %-40s %-10s %s\n", $check, $result, $time);
    }
}
