<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Config;

use Multiapi\Arco\Config\SchedulerInterval;
use Multiapi\Arco\Config\Validator;
use PHPUnit\Framework\TestCase;

final class SchedulerIntervalTest extends TestCase
{
    public function test_allowed_intervals_match_the_validator(): void
    {
        self::assertSame([1, 2, 3, 4, 5, 6, 10, 12, 15, 20, 30, 60], SchedulerInterval::ALLOWED);
        self::assertSame(SchedulerInterval::ALLOWED, Validator::SCHEDULER_INTERVALS);
    }

    public function test_required_is_the_largest_interval_dividing_every_minute(): void
    {
        self::assertSame(1, SchedulerInterval::required(range(0, 59)));
        self::assertSame(5, SchedulerInterval::required(range(0, 55, 5)));
        self::assertSame(30, SchedulerInterval::required([0, 30]));
        self::assertSame(60, SchedulerInterval::required([0]));
        self::assertSame(1, SchedulerInterval::required([7]));
        self::assertSame(60, SchedulerInterval::required([]));
        self::assertSame(15, SchedulerInterval::required([15, 45]));
    }

    public function test_missed_lists_tasks_the_interval_skips(): void
    {
        $tasks = [['task' => 'a', 'minutes' => range(0, 55, 5)], ['task' => 'b', 'minutes' => [0]]];

        self::assertSame(['a'], SchedulerInterval::missed(30, $tasks));
        self::assertSame([], SchedulerInterval::missed(5, $tasks));
    }
}
