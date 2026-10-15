<?php declare(strict_types=1);

namespace Multiapi\Arco\Logs;

/** --since / --until: 30s, 10m, 2h, 1d (before now) or a date PHP understands. */
final class TimeExpression
{
    private const UNITS = ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400];

    /** @return int epoch milliseconds */
    public static function parse(string $value, int $nowMs): int
    {
        $value = trim($value);
        if (preg_match('/^(\d+)([smhd])$/', $value, $m) === 1) {
            return $nowMs - (int) $m[1] * self::UNITS[$m[2]] * 1000;
        }
        if ($value === '') {
            throw new \InvalidArgumentException('Empty time.');
        }
        try {
            $date = new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw new \InvalidArgumentException("Unrecognized time \"$value\".");
        }
        return (int) $date->format('Uv');
    }
}
