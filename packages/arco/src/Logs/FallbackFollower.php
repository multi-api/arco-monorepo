<?php declare(strict_types=1);

namespace Multiapi\Arco\Logs;

use Aws\Exception\AwsException;
use Aws\Exception\EventStreamDataException;

/** Live Tail first; polling when Live Tail cannot be used or its stream stalls or ends early. */
final class FallbackFollower implements Follower
{
    private const FALLBACK_CODES = ['AccessDeniedException', 'LimitExceededException', 'InvalidOperationException', 'ResourceNotFoundException', 'SessionStreamingException'];
    /** Live events remembered so polling does not print them again; pruned past the polling window. */
    private const PRUNE_EVERY = 1000;

    /**
     * @param list<string> $existing
     * @param \Closure(string): void $notify receives a translation key
     */
    public function __construct(
        private readonly Follower $live,
        private readonly Follower $polling,
        private readonly array $existing,
        private readonly \Closure $notify,
    ) {
    }

    public function follow(array $groups, int $fromMs, ?string $pattern, callable $onEvent, callable $stop): void
    {
        if (array_intersect($groups, $this->existing) === []) {
            $this->polling->follow($groups, $fromMs, $pattern, $onEvent, $stop);
            return;
        }

        /** @var array<string, int> $printed content key => timestamp of the events Live Tail printed */
        $printed = [];
        $live = static function (LogEvent $event) use (&$printed, $onEvent): void {
            $printed[$event->contentKey()] = $event->timestamp;
            if (count($printed) % self::PRUNE_EVERY === 0) {
                $oldest = max($printed) - 2 * PollingFollower::WINDOW_MS;
                $printed = array_filter($printed, static fn (int $timestamp) => $timestamp >= $oldest);
            }
            $onEvent($event);
        };

        try {
            $this->live->follow($groups, $fromMs, $pattern, $live, $stop);
            if ($stop()) {
                return;
            }
            $notice = 'logs.live_tail_interrupted';
        } catch (StreamInterrupted) {
            if ($stop()) {
                return;
            }
            $notice = 'logs.live_tail_interrupted';
        } catch (EventStreamDataException $e) {
            if ($e->getAwsErrorCode() === 'SessionTimeoutException') {
                ($this->notify)('logs.session_ended');
                return;
            }
            if (! in_array($e->getAwsErrorCode(), self::FALLBACK_CODES, true)) {
                throw $e;
            }
            $notice = 'logs.live_tail_unavailable';
        } catch (AwsException $e) {
            if (! in_array($e->getAwsErrorCode(), self::FALLBACK_CODES, true)) {
                throw $e;
            }
            $notice = 'logs.live_tail_unavailable';
        }

        ($this->notify)($notice);
        // Polling looks back a minute: skip what Live Tail already printed (FilterLogEvents adds an eventId).
        $this->polling->follow($groups, $fromMs, $pattern, static function (LogEvent $event) use ($printed, $onEvent): void {
            if (! isset($printed[$event->contentKey()])) {
                $onEvent($event);
            }
        }, $stop);
    }
}
