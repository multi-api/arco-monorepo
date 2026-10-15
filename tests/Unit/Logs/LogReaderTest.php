<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Logs;

use Aws\CloudWatchLogs\Exception\CloudWatchLogsException;
use Aws\Result;
use Multiapi\Arco\Logs\LogEvent;
use Multiapi\Arco\Logs\LogReader;
use Multiapi\Arco\Tests\Unit\Deploy\AwsMocks;
use PHPUnit\Framework\TestCase;

final class LogReaderTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function row(string $id, int $timestamp): array
    {
        return ['eventId' => $id, 'timestamp' => $timestamp, 'message' => $id, 'logStreamName' => 's'];
    }

    public function test_merges_groups_by_timestamp_and_follows_pages(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['events' => [self::row('w1', 1000), self::row('w3', 3000)], 'nextToken' => 't2']),
            new Result(['events' => [self::row('w4', 4000)]]),
            new Result(['events' => [self::row('c2', 2000), self::row('c3', 3000)]]),
        );

        $read = (new LogReader($mocks->clients()->logs()))->read(['web', 'console'], 500, 9000, '"x"');

        self::assertSame(['w1', 'c2', 'w3', 'c3', 'w4'], array_map(fn (LogEvent $e) => $e->id, $read['events']), 'stable on equal timestamps');
        self::assertSame(['web', 'console'], $read['existing']);
        self::assertFalse($read['truncated']);
        self::assertSame(['logGroupName' => 'web', 'startTime' => 500, 'endTime' => 9000, 'filterPattern' => '"x"'], array_intersect_key($mocks->params[0], array_flip(['logGroupName', 'startTime', 'endTime', 'filterPattern'])));
        self::assertSame('t2', $mocks->params[1]['nextToken']);
    }

    public function test_missing_groups_are_skipped(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            AwsMocks::error(CloudWatchLogsException::class, 'FilterLogEvents', 'ResourceNotFoundException', 400),
            new Result(['events' => [self::row('c1', 1000)]]),
        );

        $read = (new LogReader($mocks->clients()->logs()))->read(['web.local', 'console'], 0, null, null);

        self::assertSame(['console'], $read['existing']);
        self::assertCount(1, $read['events']);
    }

    public function test_other_errors_are_not_hidden(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(AwsMocks::error(CloudWatchLogsException::class, 'FilterLogEvents', 'AccessDeniedException', 400));

        $this->expectException(CloudWatchLogsException::class);
        (new LogReader($mocks->clients()->logs()))->read(['web'], 0, null, null);
    }

    public function test_stops_at_the_event_limit(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(new Result(['events' => [self::row('a', 1), self::row('b', 2), self::row('c', 3)]]));

        $read = (new LogReader($mocks->clients()->logs()))->read(['web'], 0, null, null, 2);

        self::assertCount(2, $read['events']);
        self::assertTrue($read['truncated']);
    }
}
