<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Logs;

use Aws\Api\Parser\Exception\ParserException;
use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Aws\Exception\EventStreamDataException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Multiapi\Arco\Logs\FullReadStream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Milestone 2, Task 1: can the AWS SDK for PHP consume a CloudWatch Live Tail event stream?
 * The decision and its evidence are in docs/maintainers/logs-follow.md.
 */
final class LiveTailSpikeTest extends TestCase
{
    private const GROUP = 'arn:aws:logs:us-east-1:111111111111:log-group:/aws/lambda/web/shop-production';

    /** @var array<string, mixed> */
    private array $request = [];

    private function client(StreamInterface $body, bool $wrap = true): CloudWatchLogsClient
    {
        $http = function (RequestInterface $request, array $options) use ($body) {
            $this->request = [
                'host' => $request->getUri()->getHost(),
                'target' => $request->getHeaderLine('X-Amz-Target'),
                'stream' => $options['stream'] ?? null,
                'body' => json_decode((string) $request->getBody(), true),
            ];
            return Create::promiseFor(new Response(200, ['Content-Type' => 'application/vnd.amazon.eventstream'], $body));
        };
        return new CloudWatchLogsClient([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => ['key' => 'AKIDEXAMPLE', 'secret' => 'secret'],
            'http_handler' => $wrap ? FullReadStream::wrap($http) : $http,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function events(CloudWatchLogsClient $client): array
    {
        $events = [];
        foreach ($client->startLiveTail(['logGroupIdentifiers' => [self::GROUP]])['responseStream'] as $event) {
            $events[] = $event;
        }
        return $events;
    }

    public function test_a_recorded_session_parses_into_start_and_updates(): void
    {
        $events = $this->events($this->client(new TrickleStream(EventStreamFrames::session(), 65536)));

        self::assertSame('stream-logs.us-east-1.amazonaws.com', $this->request['host']);
        self::assertSame('Logs_20140328.StartLiveTail', $this->request['target']);
        self::assertTrue($this->request['stream'], 'the SDK must stream the body, not buffer the whole session');
        self::assertSame([self::GROUP], $this->request['body']['logGroupIdentifiers']);
        self::assertSame(['sessionStart', 'sessionUpdate', 'sessionUpdate'], array_map(fn (array $e) => (string) array_key_first($e), $events));
        $results = $events[1]['sessionUpdate']['sessionResults'];
        self::assertCount(2, $results);
        self::assertSame('{"message":"first","level_name":"INFO"}', $results[0]['message']);
        self::assertSame(1760000000000, $results[0]['timestamp']);
        self::assertSame(self::GROUP, $results[0]['logGroupIdentifier']);
        self::assertSame([], $events[2]['sessionUpdate']['sessionResults'] ?? []);
    }

    public function test_events_are_yielded_before_the_stream_ends(): void
    {
        $body = new TrickleStream(EventStreamFrames::session(), 65536);
        $iterator = $this->client($body)->startLiveTail(['logGroupIdentifiers' => [self::GROUP]])['responseStream'];

        $iterator->rewind();

        self::assertArrayHasKey('sessionStart', $iterator->current());
        self::assertLessThan(strlen(EventStreamFrames::session()), $body->delivered, 'the first event must not wait for the whole session');
    }

    public function test_short_reads_are_parsed_through_full_read_stream(): void
    {
        $events = $this->events($this->client(new TrickleStream(EventStreamFrames::session(), 3)));

        self::assertCount(3, $events);
        self::assertCount(2, $events[1]['sessionUpdate']['sessionResults']);
    }

    public function test_short_reads_without_the_wrapper_break_the_message_checksum(): void
    {
        // The SDK reads each message's trailing CRC with a single read(4); a network body may return fewer bytes.
        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Message checksum mismatch.');

        $this->events($this->client(new TrickleStream(EventStreamFrames::session(), 3), wrap: false));
    }

    public function test_a_session_timeout_surfaces_as_an_event_stream_exception(): void
    {
        $body = EventStreamFrames::event('sessionStart', ['sessionId' => 's-1']) . EventStreamFrames::exception('SessionTimeoutException', 'Session timed out');

        try {
            $this->events($this->client(new TrickleStream($body, 65536)));
            self::fail('The timeout must be thrown while iterating.');
        } catch (EventStreamDataException $e) {
            self::assertSame('SessionTimeoutException', $e->getAwsErrorCode());
            self::assertSame('Session timed out', $e->getAwsErrorMessage());
        }
    }
}
