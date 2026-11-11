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
        yield 'dow step' => ['0 9 * * */2', 'cron(0 9 ? * */2 *)'];
        yield 'dow range step' => ['0 9 * * 1-5/2', 'cron(0 9 ? * 2-6/2 *)'];
        yield 'dow full week 0-6' => ['0 9 * * 0-6', 'cron(0 9 ? * 1-7 *)'];
        yield 'dow 5-7' => ['0 9 * * 5-7', 'cron(0 9 ? * 6-7,1 *)'];
        yield 'dow 1-7' => ['0 9 * * 1-7', 'cron(0 9 ? * 1-7 *)'];
        yield 'dow 0-7' => ['0 9 * * 0-7', 'cron(0 9 ? * 1-7 *)'];
        yield 'dow 7-7' => ['0 9 * * 7-7', null];
        yield 'minute 60' => ['60 9 * * *', null];
        yield 'hour 24' => ['0 24 * * *', null];
        yield 'dom 0' => ['0 9 0 * *', null];
        yield 'dom 32' => ['0 9 32 * *', null];
        yield 'month 0' => ['0 9 1 0 *', null];
        yield 'month 13' => ['0 9 1 13 *', null];
        yield 'lone dash' => ['- 9 * * *', null];
        yield 'double comma' => ['0,,5 9 * * *', null];
        yield 'double dash' => ['1--2 9 * * *', null];
        yield 'step zero' => ['*/0 9 * * *', null];
        yield 'reversed range' => ['5-1 9 * * *', null];
        yield 'minute list range step' => ['0-30/10 9 * * *', 'cron(0-30/10 9 * * ? *)'];
    }

    #[DataProvider('expressions')]
    public function test_to_event_bridge(string $every, ?string $expected): void
    {
        self::assertSame($expected, ScheduleExpression::toEventBridge($every));
    }
}
