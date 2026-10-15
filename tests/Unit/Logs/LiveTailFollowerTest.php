<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Logs;

use ArrayIterator;
use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Aws\Result;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Multiapi\Arco\Logs\FullReadStream;
use Multiapi\Arco\Logs\LiveTailFollower;
use Multiapi\Arco\Logs\LogEvent;
use Multiapi\Arco\Logs\StreamInterrupted;
use Multiapi\Arco\Tests\Unit\Deploy\AwsMocks;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class LiveTailFollowerTest extends TestCase
{
    private const GROUP = '/aws/lambda/web/shop-production';

    /** @var array<string, mixed> http options the inner handler received */
    private array $httpOptions = [];

    /** A real client reading $body through FullReadStream, as `arco logs --follow` does. */
    private function client(TrickleStream $body): CloudWatchLogsClient
    {
        $http = function (RequestInterface $request, array $options) use ($body) {
            $this->httpOptions = $options;
            return Create::promiseFor(new Response(200, ['Content-Type' => 'application/vnd.amazon.eventstream'], $body));
        };
        return new CloudWatchLogsClient([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => ['key' => 'AKIDEXAMPLE', 'secret' => 'secret'],
            'http_handler' => FullReadStream::wrap($http),
        ]);
    }

    /**
     * @param (callable(): bool)|null $stop
     * @param list<LogEvent> $events
     */
    private function follow(TrickleStream $body, float $idleSeconds, array &$events, ?callable $stop = null): void
    {
        (new LiveTailFollower($this->client($body), 'us-east-1', '111111111111', [self::GROUP], $idleSeconds))->follow(
            [self::GROUP],
            0,
            null,
            function (LogEvent $event) use (&$events): void { $events[] = $event; },
            $stop ?? fn (): bool => false,
        );
    }

    public function test_the_stream_has_a_read_timeout_and_the_guard_options_stay_out_of_guzzle(): void
    {
        $events = [];
        $this->follow(new TrickleStream(EventStreamFrames::session(), 65536), 30, $events);

        self::assertCount(2, $events);
        self::assertSame(LiveTailFollower::READ_TIMEOUT_SECONDS, $this->httpOptions['read_timeout'] ?? null);
        self::assertArrayNotHasKey(FullReadStream::STOP_OPTION, $this->httpOptions);
        self::assertArrayNotHasKey(FullReadStream::IDLE_OPTION, $this->httpOptions);
    }

    public function test_a_stalled_stream_ends_after_the_idle_deadline(): void
    {
        $events = [];
        try {
            $this->follow(new TrickleStream(EventStreamFrames::session(), 7, TrickleStream::STALL), 0.05, $events);
            self::fail('A stalled stream must not hang.');
        } catch (StreamInterrupted $e) {
            self::assertFalse($e->stopped);
        }
        self::assertCount(2, $events, 'events before the stall are kept');
    }

    public function test_read_timeouts_count_as_silence_until_the_idle_deadline(): void
    {
        $body = new TrickleStream(EventStreamFrames::session(), 65536, TrickleStream::TIME_OUT);
        $events = [];

        $this->expectException(StreamInterrupted::class);
        try {
            $this->follow($body, 0.05, $events);
        } finally {
            self::assertGreaterThan(1, $body->emptyReads, 'a read timeout is retried, not fatal');
        }
    }

    public function test_a_stop_request_ends_a_stalled_stream_cleanly(): void
    {
        $events = [];
        $started = microtime(true);

        // Idle deadline far away: only the stop check can end this follow.
        $this->follow(new TrickleStream(EventStreamFrames::session(), 7, TrickleStream::STALL), 300, $events, function () use (&$events): bool {
            return count($events) >= 2;
        });

        self::assertCount(2, $events);
        self::assertLessThan(5, microtime(true) - $started);
    }

    public function test_tails_the_existing_groups_and_emits_their_events(): void
    {
        $group = 'arn:aws:logs:us-east-1:111111111111:log-group:/aws/lambda/shop-production-console';
        $mocks = new AwsMocks();
        $mocks->handler->append(new Result(['responseStream' => new ArrayIterator([
            ['sessionStart' => ['sessionId' => 's-1']],
            ['sessionUpdate' => ['sessionResults' => [['logGroupIdentifier' => $group, 'logStreamName' => 's', 'timestamp' => 1, 'message' => 'one']]]],
            ['sessionUpdate' => ['sessionResults' => []]],
        ])]));
        $events = [];

        (new LiveTailFollower($mocks->clients()->logs(), 'us-east-1', '111111111111', ['/aws/lambda/shop-production-console']))->follow(
            ['/aws/lambda/web/shop-production.local', '/aws/lambda/shop-production-console'],
            0,
            '"Order"',
            function (LogEvent $event) use (&$events): void { $events[] = $event; },
            fn (): bool => false,
        );

        self::assertSame([$group], $mocks->params[0]['logGroupIdentifiers'], 'missing groups would fail the whole session');
        self::assertSame('"Order"', $mocks->params[0]['logEventFilterPattern']);
        self::assertCount(1, $events);
        self::assertSame('/aws/lambda/shop-production-console', $events[0]->group);
    }
}
