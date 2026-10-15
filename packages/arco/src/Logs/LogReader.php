<?php declare(strict_types=1);

namespace Multiapi\Arco\Logs;

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Aws\CloudWatchLogs\Exception\CloudWatchLogsException;

/** Reads a time range of several log groups with FilterLogEvents and merges them by timestamp. */
final class LogReader
{
    public const MAX_EVENTS = 10_000;
    private const MAX_PAGES = 100;

    public function __construct(private readonly CloudWatchLogsClient $logs)
    {
    }

    /**
     * Missing groups (never written to yet) are skipped.
     *
     * @param list<string> $groups
     * @return array{events: list<LogEvent>, existing: list<string>, truncated: bool}
     */
    public function read(array $groups, int $startMs, ?int $endMs, ?string $pattern, int $max = self::MAX_EVENTS): array
    {
        $events = [];
        $existing = [];
        $truncated = false;
        foreach ($groups as $group) {
            try {
                [$groupEvents, $groupTruncated] = $this->readGroup($group, $startMs, $endMs, $pattern, $max - count($events));
            } catch (CloudWatchLogsException $e) {
                if ($e->getAwsErrorCode() === 'ResourceNotFoundException') {
                    continue;
                }
                throw $e;
            }
            $existing[] = $group;
            array_push($events, ...$groupEvents);
            $truncated = $truncated || $groupTruncated;
        }
        usort($events, fn (LogEvent $a, LogEvent $b) => $a->timestamp <=> $b->timestamp);
        return ['events' => $events, 'existing' => $existing, 'truncated' => $truncated];
    }

    /** @return array{0: list<LogEvent>, 1: bool} */
    private function readGroup(string $group, int $startMs, ?int $endMs, ?string $pattern, int $max): array
    {
        $events = [];
        $token = null;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $params = ['logGroupName' => $group, 'startTime' => $startMs];
            if ($endMs !== null) {
                $params['endTime'] = $endMs;
            }
            if ($pattern !== null) {
                $params['filterPattern'] = $pattern;
            }
            if ($token !== null) {
                $params['nextToken'] = $token;
            }
            $result = $this->logs->filterLogEvents($params);
            $rows = $result['events'] ?? [];
            foreach (is_array($rows) ? $rows : [] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                if (count($events) >= $max) {
                    return [$events, true];
                }
                $events[] = LogEvent::fromAws(Arrays::keyed($row), $group);
            }
            $next = $result['nextToken'] ?? null;
            if (! is_string($next) || $next === '') {
                return [$events, false];
            }
            $token = $next;
        }
        return [$events, true];
    }
}
