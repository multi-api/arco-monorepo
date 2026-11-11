<?php declare(strict_types=1);

namespace Multiapi\Arco\Config;

/** Turns a schedule's `every` (`5m`, `6h`, `1d` or a 5-field cron, UTC) into an EventBridge schedule expression. */
final class ScheduleExpression
{
    private const FIELD = '/^[0-9*,\/-]+$/';

    /** The EventBridge expression for `every`, or null when it is neither a short interval nor a cron expression. */
    public static function toEventBridge(string $every): ?string
    {
        $every = trim($every);
        if (preg_match('/^([1-9]\d*)([mhd])$/', $every, $m) === 1) {
            return self::interval((int) $m[1], $m[2]);
        }
        $fields = preg_split('/\s+/', $every) ?: [];
        return count($fields) === 5 ? self::cron($fields) : null;
    }

    private static function interval(int $n, string $unit): ?string
    {
        if ($unit === 'd') {
            return $n === 1 ? 'cron(0 0 * * ? *)' : null;
        }
        $minutes = $unit === 'm';
        $limit = $minutes ? 59 : 23;
        if ($n > $limit) {
            return null;
        }
        if ($n === 1) {
            return $minutes ? 'cron(* * * * ? *)' : 'cron(0 * * * ? *)';
        }
        if (($minutes ? 60 : 24) % $n === 0) {
            return $minutes ? "cron(0/$n * * * ? *)" : "cron(0 0/$n * * ? *)";
        }
        return 'rate(' . $n . ($minutes ? ' minutes)' : ' hours)');
    }

    /** @param list<string> $fields minute, hour, day of month, month, day of week */
    private static function cron(array $fields): ?string
    {
        foreach ($fields as $field) {
            if (preg_match(self::FIELD, $field) !== 1) {
                return null;
            }
        }
        [$minute, $hour, $dom, $month, $dow] = $fields;
        if ($dom !== '*' && $dow !== '*') {
            return null;
        }
        if ($dow !== '*') {
            $dow = self::weekdays($dow);
            if ($dow === null) {
                return null;
            }
            $dom = '?';
        } else {
            $dow = '?';
        }
        return "cron($minute $hour $dom $month $dow *)";
    }

    /** Cron weekdays (0 or 7 is Sunday, 1 Monday) as EventBridge's (1 is Sunday); null when it cannot be translated. */
    private static function weekdays(string $dow): ?string
    {
        $parts = [];
        foreach (explode(',', $dow) as $item) {
            if (preg_match('/^(\d+|\*)(?:-(\d+))?(?:\/(\d+))?$/', $item, $m) !== 1) {
                return null;
            }
            $start = $m[1];
            $end = $m[2] ?? '';
            $step = $m[3] ?? '';
            if ($start !== '*') {
                $first = (int) $start;
                if ($first > 7 || ($end !== '' && $first === 7)) {
                    return null;
                }
                $start = (string) ($first % 7 + 1);
            }
            if ($end !== '') {
                $last = (int) $end;
                if ($last > 6) {
                    return null;
                }
                $end = '-' . ($last + 1);
            }
            if ($step !== '' && (int) $step < 1) {
                return null;
            }
            $parts[] = $start . $end . ($step !== '' ? '/' . $step : '');
        }
        return implode(',', $parts);
    }
}
