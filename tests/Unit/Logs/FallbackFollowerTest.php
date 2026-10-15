<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Logs;

use Aws\CloudWatchLogs\Exception\CloudWatchLogsException;
use Aws\Command;
use Aws\Exception\EventStreamDataException;
use Multiapi\Arco\Logs\FallbackFollower;
use Multiapi\Arco\Logs\Follower;
use Multiapi\Arco\Logs\LogEvent;
use Multiapi\Arco\Logs\StreamInterrupted;
use Multiapi\Arco\Tests\Unit\Deploy\AwsMocks;
use PHPUnit\Framework\TestCase;

final class FallbackFollowerTest extends TestCase
{
    /** @var list<string> */
    private array $log = [];

    private function follower(string $name, ?\Throwable $error = null): Follower
    {
        return new class ($name, $error, $this->log) implements Follower {
            /** @param list<string> $log */
            public function __construct(private readonly string $name, private readonly ?\Throwable $error, private array &$log)
            {
            }

            public function follow(array $groups, int $fromMs, ?string $pattern, callable $onEvent, callable $stop): void
            {
                $this->log[] = $this->name;
                if ($this->error !== null) {
                    throw $this->error;
                }
            }
        };
    }

    /** @param list<string> $existing */
    private function followWith(Follower $live, array $existing = ['console']): void
    {
        (new FallbackFollower($live, $this->follower('polling'), $existing, function (string $key): void { $this->log[] = $key; }))
            ->follow(['console'], 0, null, fn () => null, fn (): bool => true);
    }

    public function test_falls_back_to_polling_when_live_tail_is_denied(): void
    {
        $this->followWith($this->follower('live', AwsMocks::error(CloudWatchLogsException::class, 'StartLiveTail', 'AccessDeniedException', 400)));

        self::assertSame(['live', 'logs.live_tail_unavailable', 'polling'], $this->log);
    }

    public function test_falls_back_to_polling_when_live_tail_cannot_be_reached(): void
    {
        // A slow TLS handshake or a network blip: the SDK reports a connection error without an AWS error code.
        $error = new CloudWatchLogsException('Connection timed out', new Command('StartLiveTail'), ['connection_error' => true]);

        $this->followWith($this->follower('live', $error));

        self::assertSame(['live', 'logs.live_tail_unreachable', 'polling'], $this->log);
    }

    public function test_uses_live_tail_only_when_a_followed_group_exists(): void
    {
        $fallback = new FallbackFollower($this->follower('live'), $this->follower('polling'), ['console'], fn (string $key) => null);

        self::assertTrue($fallback->usesLiveTail(['web', 'console']));
        self::assertFalse($fallback->usesLiveTail(['web']));
    }

    public function test_a_session_timeout_ends_the_follow(): void
    {
        $this->followWith($this->follower('live', new EventStreamDataException('SessionTimeoutException', 'timed out')));

        self::assertSame(['live', 'logs.session_ended'], $this->log);
    }

    public function test_without_existing_groups_polling_starts_directly(): void
    {
        $this->followWith($this->follower('live'), []);

        self::assertSame(['polling'], $this->log);
    }

    public function test_other_errors_are_not_hidden(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->followWith($this->follower('live', new \RuntimeException('network down')));
    }

    /**
     * @param list<LogEvent> $events emitted in order
     */
    private function emitting(string $name, array $events, ?\Throwable $error = null): Follower
    {
        return new class ($name, $events, $error, $this->log) implements Follower {
            /**
             * @param list<LogEvent> $events
             * @param list<string> $log
             */
            public function __construct(private readonly string $name, private readonly array $events, private readonly ?\Throwable $error, private array &$log)
            {
            }

            public function follow(array $groups, int $fromMs, ?string $pattern, callable $onEvent, callable $stop): void
            {
                $this->log[] = $this->name;
                foreach ($this->events as $event) {
                    $onEvent($event);
                }
                if ($this->error !== null) {
                    throw $this->error;
                }
            }
        };
    }

    /** @return list<string> messages printed */
    private function runEmitting(Follower $live, Follower $polling, bool $stopped = false): array
    {
        $printed = [];
        (new FallbackFollower($live, $polling, ['console'], function (string $key): void { $this->log[] = $key; }))
            ->follow(['console'], 0, null, function (LogEvent $event) use (&$printed): void { $printed[] = $event->message; }, fn (): bool => $stopped);
        return $printed;
    }

    public function test_a_stalled_stream_falls_back_to_polling_without_printing_events_twice(): void
    {
        // Live Tail events carry no eventId; FilterLogEvents returns the same events with one.
        $live = LogEvent::fromAws(['logGroupIdentifier' => 'arn:aws:logs:us-east-1:1:log-group:console', 'logStreamName' => 's', 'timestamp' => 5, 'message' => 'seen live'], '');
        $again = LogEvent::fromAws(['eventId' => '36000', 'logStreamName' => 's', 'timestamp' => 5, 'message' => 'seen live'], 'console');
        $new = LogEvent::fromAws(['eventId' => '36001', 'logStreamName' => 's', 'timestamp' => 6, 'message' => 'new'], 'console');

        $printed = $this->runEmitting(
            $this->emitting('live', [$live], StreamInterrupted::stalled(30)),
            $this->emitting('polling', [$again, $new]),
        );

        self::assertSame(['seen live', 'new'], $printed);
        self::assertSame(['live', 'logs.live_tail_interrupted', 'polling'], $this->log);
    }

    public function test_a_stream_that_ends_without_a_stop_falls_back_to_polling(): void
    {
        $this->runEmitting($this->emitting('live', []), $this->emitting('polling', []));

        self::assertSame(['live', 'logs.live_tail_interrupted', 'polling'], $this->log);
    }

    public function test_a_stop_request_ends_the_follow_quietly(): void
    {
        $this->runEmitting($this->emitting('live', [], StreamInterrupted::stopped()), $this->emitting('polling', []), stopped: true);

        self::assertSame(['live'], $this->log);
    }
}
