<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy;

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Aws\CloudWatchLogs\Exception\CloudWatchLogsException;
use Aws\CommandInterface;
use Aws\Result;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Multiapi\Arco\Deploy\Sleeper;

/**
 * A CloudWatch log group that behaves like the real API, and the clock of the verifier:
 * - events become visible only after their ingestion delay;
 * - FilterLogEvents walks the streams a few at a time, so a busy group returns many empty pages
 *   with a nextToken; logStreamNames limits the search;
 * - DescribeLogStreams lists streams by last event time, most recent first;
 * - every call takes $latency seconds, and calls can be throttled.
 */
final class FakeLogGroup extends Sleeper
{
    public float $clock = 0.0;
    /** @var list<array<string, mixed>> parameters of every call, with the operation under '@op' */
    public array $queries = [];
    public ?string $failWith = null;
    public bool $missing = false;
    /** @var list<array{stream: string, timestamp: int, message: string, visibleAt: int}> */
    private array $events = [];

    public function __construct(private readonly int $streamsPerPage = 2, private readonly float $latency = 0.0)
    {
    }

    public function sleep(int $seconds): void
    {
        $this->clock += $seconds;
    }

    public function now(): float
    {
        return $this->clock;
    }

    /** @param array<string, mixed> $message */
    public function add(string $stream, int $timestamp, array $message, int $visibleAfterSeconds = 0): self
    {
        $this->events[] = ['stream' => $stream, 'timestamp' => $timestamp, 'message' => (string) json_encode($message), 'visibleAt' => $visibleAfterSeconds];
        return $this;
    }

    /** Instances of the previous revision, each in its own stream, written at $timestamp. */
    public function noise(int $streams, int $timestamp = 1000): self
    {
        for ($i = 0; $i < $streams; $i++) {
            $this->add("a-old-$i", $timestamp, ['type' => 'platform.initStart', 'record' => ['revisionId' => 'rev-1', 'instanceId' => "old-$i"]]);
            $this->add("a-old-$i", $timestamp + 1, ['type' => 'platform.initReport', 'record' => ['status' => 'success', 'instanceId' => "old-$i", 'metrics' => ['totalDurationMs' => 50.0]]]);
            $this->add("a-old-$i", $timestamp + 2, ['type' => 'app', 'message' => 'request']);
        }
        return $this;
    }

    /** @return list<array<string, mixed>> */
    public function queriesOf(string $operation): array
    {
        return array_values(array_filter($this->queries, fn (array $q) => $q['@op'] === $operation));
    }

    public function client(): CloudWatchLogsClient
    {
        return new CloudWatchLogsClient([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => false,
            'retries' => 0,
            'handler' => fn (CommandInterface $command) => $this->handle($command),
        ]);
    }

    private function handle(CommandInterface $command): PromiseInterface
    {
        $params = $command->toArray();
        $this->queries[] = ['@op' => $command->getName()] + $params;
        $this->clock += $this->latency;
        if ($this->missing || $this->failWith !== null) {
            $code = $this->missing ? 'ResourceNotFoundException' : (string) $this->failWith;
            return Create::rejectionFor(new CloudWatchLogsException($code, $command, ['code' => $code]));
        }

        return Create::promiseFor(match ($command->getName()) {
            'GetLogEvents' => new Result(['events' => array_slice(array_values(array_map(
                fn (array $e) => ['message' => $e['message'], 'timestamp' => $e['timestamp']],
                array_filter($this->events, fn (array $e) => $e['stream'] === $params['logStreamName'] && $e['visibleAt'] <= $this->clock),
            )), -(int) ($params['limit'] ?? 30))]),
            'DescribeLogStreams' => $this->describe($params),
            default => $this->filter($params),
        });
    }

    /** @param array<string, mixed> $params */
    private function describe(array $params): Result
    {
        $streams = [];
        foreach ($this->events as $e) {
            $streams[$e['stream']] ??= ['logStreamName' => $e['stream'], 'creationTime' => $e['timestamp']];
            $streams[$e['stream']]['creationTime'] = min($streams[$e['stream']]['creationTime'], $e['timestamp']);
            if ($e['visibleAt'] <= $this->clock) {
                $streams[$e['stream']]['lastEventTimestamp'] = max($streams[$e['stream']]['lastEventTimestamp'] ?? 0, $e['timestamp']);
            }
        }
        $streams = array_values($streams);
        usort($streams, fn (array $a, array $b) => ($b['lastEventTimestamp'] ?? 0) <=> ($a['lastEventTimestamp'] ?? 0));
        return new Result(['logStreams' => array_slice($streams, 0, (int) ($params['limit'] ?? 50))]);
    }

    /** @param array<string, mixed> $params */
    private function filter(array $params): Result
    {
        $visible = array_values(array_filter($this->events, fn (array $e) => $e['visibleAt'] <= $this->clock
            && $e['timestamp'] >= ($params['startTime'] ?? 0)
            && self::matches($params['filterPattern'] ?? null, $e['message'])));
        $streams = array_values(array_unique(array_column($this->events, 'stream')));
        sort($streams);
        if (isset($params['logStreamNames'])) {
            $streams = array_values(array_intersect($streams, $params['logStreamNames']));
        }

        $offset = (int) ($params['nextToken'] ?? 0);
        $page = array_slice($streams, $offset, $this->streamsPerPage);
        $events = array_values(array_filter($visible, fn (array $e) => in_array($e['stream'], $page, true)));
        $result = ['events' => array_map(fn (array $e) => ['logStreamName' => $e['stream'], 'timestamp' => $e['timestamp'], 'message' => $e['message']], $events)];
        if ($offset + $this->streamsPerPage < count($streams)) {
            $result['nextToken'] = (string) ($offset + $this->streamsPerPage);
        }
        return new Result($result);
    }

    /** Understands the JSON patterns the verifier uses: type, revisionId and OR-ed instanceIds. */
    private static function matches(?string $pattern, string $message): bool
    {
        if ($pattern === null) {
            return true;
        }
        $decoded = json_decode($message, true);
        if (! is_array($decoded)) {
            return false;
        }
        if (preg_match('/\$\.type = "([^"]+)"/', $pattern, $m) && ($decoded['type'] ?? null) !== $m[1]) {
            return false;
        }
        if (preg_match('/\$\.record\.revisionId = "([^"]+)"/', $pattern, $m) && ($decoded['record']['revisionId'] ?? null) !== $m[1]) {
            return false;
        }
        if (preg_match_all('/\$\.record\.instanceId = "([^"]+)"/', $pattern, $m) && ! in_array($decoded['record']['instanceId'] ?? null, $m[1], true)) {
            return false;
        }
        return true;
    }
}
