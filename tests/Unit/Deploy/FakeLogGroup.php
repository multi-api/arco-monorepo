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
 * A CloudWatch log group that behaves like the real API, and the verifier's clock (epoch seconds):
 * - an event becomes visible only once the clock passes its timestamp plus its ingestion lag
 *   (10 to 36 s were seen on real redeploys);
 * - FilterLogEvents walks the streams a few at a time, so a busy group returns empty pages with a
 *   nextToken; logStreamNames and logStreamNamePrefix limit the search;
 * - every call takes $latency seconds, and calls can fail.
 */
final class FakeLogGroup extends Sleeper
{
    public float $clock;
    /** @var list<array<string, mixed>> parameters of every call, with the operation under '@op' */
    public array $queries = [];
    public ?string $failWith = null;
    public bool $missing = false;
    /** @var list<array{stream: string, timestamp: int, message: string, visibleAt: float}> */
    private array $events = [];

    public function __construct(float $start, private readonly int $streamsPerPage = 2, private readonly float $latency = 0.0)
    {
        $this->clock = $start;
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
    public function add(string $stream, int $timestampMs, array $message, float $lagSeconds = 0.0): self
    {
        $this->events[] = ['stream' => $stream, 'timestamp' => $timestampMs, 'message' => (string) json_encode($message), 'visibleAt' => $timestampMs / 1000 + $lagSeconds];
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
            'FilterLogEvents' => $this->filter($params),
            default => throw new \LogicException('Unexpected ' . $command->getName()),
        });
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
        if (isset($params['logStreamNamePrefix'])) {
            $streams = array_values(array_filter($streams, fn (string $s) => str_starts_with($s, $params['logStreamNamePrefix'])));
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
