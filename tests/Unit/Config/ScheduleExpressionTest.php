<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Config;

use Multiapi\Arco\Config\ScheduleExpression;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScheduleExpressionTest extends TestCase
{
    /** @return iterable<string, array{string, string|null}> */
    public static function expressions(): iterable
    {
        yield '1m' => ['1m', 'cron(* * * * ? *)'];
        yield '5m' => ['5m', 'cron(0/5 * * * ? *)'];
        yield '7m' => ['7m', 'rate(7 minutes)'];
        yield '1h' => ['1h', 'cron(0 * * * ? *)'];
        yield '6h' => ['6h', 'cron(0 0/6 * * ? *)'];
        yield '5h' => ['5h', 'rate(5 hours)'];
        yield '1d' => ['1d', 'cron(0 0 * * ? *)'];
        yield '0m' => ['0m', null];
        yield '60m' => ['60m', null];
        yield '24h' => ['24h', null];
        yield '2d' => ['2d', null];
        yield 'foo' => ['foo', null];
        yield 'empty' => ['', null];
        yield 'daily cron' => ['30 2 * * *', 'cron(30 2 * * ? *)'];
        yield 'weekdays' => ['0 9 * * 1-5', 'cron(0 9 ? * 2-6 *)'];
        yield 'sunday 0' => ['0 9 * * 0', 'cron(0 9 ? * 1 *)'];
        yield 'sunday 7' => ['0 9 * * 7', 'cron(0 9 ? * 1 *)'];
        yield 'weekday list' => ['0 9 * * 1,3,5', 'cron(0 9 ? * 2,4,6 *)'];
        yield 'day of month' => ['0 9 1 * *', 'cron(0 9 1 * ? *)'];
        yield 'dom and dow' => ['0 9 1 * 1', null];
        yield 'four fields' => ['0 9 * *', null];
        yield 'six fields' => ['0 0 9 * * *', null];
        yield 'names' => ['0 9 * * MON', null];
        yield 'weekday out of range' => ['0 9 * * 8', null];
    }

    #[DataProvider('expressions')]
    public function test_to_event_bridge(string $every, ?string $expected): void
    {
        self::assertSame($expected, ScheduleExpression::toEventBridge($every));
    }
}
