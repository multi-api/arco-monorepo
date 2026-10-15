<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Logs;

use Aws\CloudWatchLogs\Exception\CloudWatchLogsException;
use Aws\Result;
use Multiapi\Arco\Logs\LogEvent;
use Multiapi\Arco\Logs\PollingFollower;
use Multiapi\Arco\Tests\Unit\Deploy\AwsMocks;
use PHPUnit\Framework\TestCase;

final class PollingFollowerTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function row(string $id, int $timestamp): array
    {
        return ['eventId' => $id, 'timestamp' => $timestamp, 'message' => $id];
    }

    public function test_polling_skips_missing_groups_and_prints_each_event_once(): void
    {
        $missing = fn () => AwsMocks::error(CloudWatchLogsException::class, 'FilterLogEvents', 'ResourceNotFoundException', 400);
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['events' => [self::row('e1', 999_991_000), self::row('e2', 999_992_000)]]), $missing(),
            new Result(['events' => [self::row('e1', 999_991_000), self::row('e2', 999_992_000), self::row('e3', 999_993_000)]]), $missing(),
            // e0 arrives late, with an older timestamp still inside the window.
            new Result(['events' => [self::row('e0', 999_990_500), self::row('e3', 999_993_000), self::row('e4', 999_994_000)]]), $missing(),
        );
        $sleeper = AwsMocks::noSleep(); // now() = 1,000,000 s plus the time slept
        $printed = [];

        (new PollingFollower($mocks->clients()->logs(), $sleeper))->follow(
            ['console', 'web.local'],
            999_990_000,
            null,
            function (LogEvent $event) use (&$printed): void { $printed[] = $event->id; },
            fn (): bool => $sleeper->slept >= 6,
        );

        self::assertSame(['e1', 'e2', 'e3', 'e0', 'e4'], $printed);
        self::assertSame(999_990_000, $mocks->params[0]['startTime'], 'never before the requested start');
        self::assertSame(6, $sleeper->slept, 'every 2 seconds');
        self::assertCount(0, $mocks->handler);
    }
}
