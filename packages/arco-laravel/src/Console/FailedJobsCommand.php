<?php declare(strict_types=1);

namespace Multiapi\Arco\Laravel\Console;

use Aws\DynamoDb\DynamoDbClient;
use Closure;
use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Queue\Console\FlushFailedCommand;
use Illuminate\Queue\Failed\DatabaseFailedJobProvider;
use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider;
use Illuminate\Queue\Failed\DynamoDbFailedJobProvider;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Carbon;
use stdClass;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Lists the app's failed jobs with their exception, or forgets them, on any failed-jobs store: Laravel's
 * `queue:failed --json` has no exception and `queue:flush` throws on DynamoDB. Arco Cloud's queue panel runs it
 * on the console function and parses the JSON line, so its keys follow the 1.x promise (only added).
 *
 * The database stores are read with two queries (counts by queue, then the newest rows), and DynamoDB page by
 * page (only ids, queues and dates) with the newest items fetched in batches, so a large store neither loads
 * every exception nor stops at DynamoDB's first 1 MB page as the failer's own all() does.
 */
final class FailedJobsCommand extends Command
{
    /** The newest failed jobs the list keeps; `total` and `queues` still count every one. */
    public const LIMIT = 200;
    /** Bytes kept of each exception, so the console function's response stays far under Lambda's 6 MB. */
    public const EXCEPTION_BYTES = 8192;
    /** Laravel's own wording, which Arco Cloud looks for. */
    private const PROHIBITED = 'This command is prohibited from running in this environment.';

    protected $signature = 'arco:failed-jobs
        {--json : Print the failed jobs (newest first, at most 200) as one JSON line}
        {--queue= : Only the failed jobs of this queue (the full SQS queue URL with Arco)}
        {--flush : Forget the failed jobs, one by one (also on DynamoDB)}';
    protected $description = 'List the failed jobs with their exception, or forget them on any failed-jobs store';

    public function handle(): int
    {
        $failer = $this->laravel->make('queue.failer');
        if (! $failer instanceof FailedJobProviderInterface) {
            $this->components->error('No failed-jobs store is configured (queue.failer).');
            return self::FAILURE;
        }
        $queue = $this->option('queue');
        $queue = is_string($queue) && $queue !== '' ? $queue : null;
        return $this->option('flush') ? $this->flush($failer, $queue) : $this->list($failer, $queue);
    }

    private function list(FailedJobProviderInterface $failer, ?string $queue): int
    {
        [$queues, $jobs] = match (true) {
            $failer instanceof DatabaseFailedJobProvider, $failer instanceof DatabaseUuidFailedJobProvider => self::fromDatabase($failer, $queue),
            $failer instanceof DynamoDbFailedJobProvider => self::fromDynamoDb($failer, $queue),
            default => self::fromAll($failer, $queue),
        };
        ksort($queues, SORT_STRING);
        $this->raw(self::json([
            'store' => self::store($failer),
            'total' => array_sum($queues),
            // An object even when empty or when a queue is named "0".
            'queues' => (object) $queues,
            'jobs' => array_map(self::job(...), $jobs),
        ]));
        return self::SUCCESS;
    }

    private function flush(FailedJobProviderInterface $failer, ?string $queue): int
    {
        if (self::prohibited()) {
            $this->components->warn(self::PROHIBITED);
            return self::FAILURE;
        }
        $forgotten = 0;
        try {
            foreach (self::ids($failer, $queue) as $id) {
                if ($failer->forget($id) !== false) {
                    $forgotten++;
                }
            }
        } catch (Throwable $e) {
            $this->components->error(self::forgotten($forgotten) . ' before an error: ' . $e->getMessage());
            return self::FAILURE;
        }
        $this->raw($this->option('json') ? self::json(['flushed' => $forgotten]) : self::forgotten($forgotten) . '.');
        return self::SUCCESS;
    }

    private function raw(string $line): void
    {
        $this->getOutput()->writeln($line, OutputInterface::OUTPUT_RAW);
    }

    /** @param array<string, mixed> $value */
    private static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private static function forgotten(int $count): string
    {
        return $count === 1 ? '1 failed job forgotten' : "$count failed jobs forgotten";
    }

    private static function store(FailedJobProviderInterface $failer): string
    {
        return match (true) {
            $failer instanceof DynamoDbFailedJobProvider => 'dynamodb',
            $failer instanceof DatabaseFailedJobProvider, $failer instanceof DatabaseUuidFailedJobProvider => 'database',
            default => strtolower((string) preg_replace('/FailedJobProvider$/', '', class_basename($failer))),
        };
    }

    /** `FlushFailedCommand::prohibit()`, which `queue:flush` honours (Laravel 11.x and later). */
    private static function prohibited(): bool
    {
        if (! property_exists(FlushFailedCommand::class, 'prohibitedFromRunning')) {
            return false;
        }
        return (bool) Closure::bind(static fn (): mixed => FlushFailedCommand::$prohibitedFromRunning, null, FlushFailedCommand::class)();
    }

    /**
     * @param array{id: string, connection: mixed, queue: mixed, payload: mixed, exception: mixed, failed_at: string} $job
     * @return array{id: string, connection: string, queue: string, job: string, failed_at: string, exception: string}
     */
    private static function job(array $job): array
    {
        $payload = is_string($job['payload']) ? json_decode($job['payload'], true) : null;
        $name = is_array($payload) ? ($payload['displayName'] ?? $payload['job'] ?? '') : '';
        return [
            'id' => $job['id'],
            'connection' => self::text($job['connection']),
            'queue' => self::text($job['queue']),
            'job' => self::text($name),
            'failed_at' => $job['failed_at'],
            'exception' => mb_strcut(self::text($job['exception']), 0, self::EXCEPTION_BYTES, 'UTF-8'),
        ];
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /** @return list<string> the ids of the matching failed jobs */
    private static function ids(FailedJobProviderInterface $failer, ?string $queue): array
    {
        if ($failer instanceof DatabaseFailedJobProvider || $failer instanceof DatabaseUuidFailedJobProvider) {
            $column = $failer instanceof DatabaseUuidFailedJobProvider ? 'uuid' : 'id';
            return array_values(array_map(self::text(...), self::rows($failer, $queue)->pluck($column)->all()));
        }
        if ($failer instanceof DynamoDbFailedJobProvider) {
            return array_column(self::dynamoKeys($failer, $queue), 'id');
        }
        return array_column(self::matching($failer, $queue), 'id');
    }

    /** @return Builder the failed_jobs table, only $queue's rows when given */
    private static function rows(DatabaseFailedJobProvider|DatabaseUuidFailedJobProvider $failer, ?string $queue): Builder
    {
        $table = $failer->getTable();
        return $queue === null ? $table : $table->where('queue', $queue);
    }

    /** @return array{array<string, int>, list<array{id: string, connection: mixed, queue: mixed, payload: mixed, exception: mixed, failed_at: string}>} */
    private static function fromDatabase(DatabaseFailedJobProvider|DatabaseUuidFailedJobProvider $failer, ?string $queue): array
    {
        $queues = [];
        foreach (self::rows($failer, $queue)->select('queue')->selectRaw('count(*) as aggregate')->groupBy('queue')->get() as $row) {
            $queues[self::text($row->queue ?? null)] = (int) self::text($row->aggregate ?? 0);
        }
        $column = $failer instanceof DatabaseUuidFailedJobProvider ? 'uuid' : 'id';
        $jobs = [];
        foreach (self::rows($failer, $queue)->orderByDesc('failed_at')->orderByDesc('id')->limit(self::LIMIT)->get() as $row) {
            $jobs[] = [
                'id' => self::text($row->{$column} ?? null),
                'connection' => $row->connection ?? null,
                'queue' => $row->queue ?? null,
                'payload' => $row->payload ?? null,
                'exception' => $row->exception ?? null,
                'failed_at' => self::text($row->failed_at ?? null),
            ];
        }
        return [$queues, $jobs];
    }

    /** @return array{array<string, int>, list<array{id: string, connection: mixed, queue: mixed, payload: mixed, exception: mixed, failed_at: string}>} */
    private static function fromDynamoDb(DynamoDbFailedJobProvider $failer, ?string $queue): array
    {
        $keys = self::dynamoKeys($failer, $queue);
        $queues = [];
        foreach ($keys as $key) {
            $queues[$key['queue']] = ($queues[$key['queue']] ?? 0) + 1;
        }
        usort($keys, static fn (array $a, array $b): int => [$b['failed_at'], $b['id']] <=> [$a['failed_at'], $a['id']]);
        $newest = array_column(array_slice($keys, 0, self::LIMIT), 'id');

        [$client, $table, $application] = self::dynamo($failer);
        $items = [];
        // 100 keys per call; DynamoDB hands back the keys past its 16 MB response (or under throttling) as
        // UnprocessedKeys, asked again until none are left, backing off after a call that returned nothing.
        foreach (array_chunk($newest, 100) as $ids) {
            for ($idle = 0; $ids !== [] && $idle < 5;) {
                $result = $client->batchGetItem(['RequestItems' => [$table => [
                    'Keys' => array_map(static fn (string $id): array => ['application' => ['S' => $application], 'uuid' => ['S' => $id]], $ids),
                ]]]);
                $responses = $result['Responses'];
                $found = self::items(is_array($responses) ? $responses[$table] ?? [] : []);
                foreach ($found as $item) {
                    $items[self::attribute($item, 'uuid')] = $item;
                }
                $unprocessed = $result['UnprocessedKeys'];
                $left = is_array($unprocessed) && is_array($unprocessed[$table] ?? null) ? $unprocessed[$table]['Keys'] ?? [] : [];
                $ids = array_map(static fn (array $key): string => self::attribute($key, 'uuid'), self::items($left));
                if ($found === [] && $ids !== []) {
                    usleep(100_000 * 2 ** $idle++);
                }
            }
        }

        $jobs = [];
        foreach ($newest as $id) {
            // Gone since the query (forgotten, or expired by its TTL): left out.
            if (isset($items[$id])) {
                $item = $items[$id];
                $jobs[] = [
                    'id' => $id,
                    'connection' => self::attribute($item, 'connection'),
                    'queue' => self::attribute($item, 'queue'),
                    'payload' => self::attribute($item, 'payload'),
                    'exception' => self::attribute($item, 'exception'),
                    // As DynamoDbFailedJobProvider::all() writes it.
                    'failed_at' => Carbon::createFromTimestamp((int) self::attribute($item, 'failed_at'), date_default_timezone_get())->format(DateTimeInterface::ISO8601),
                ];
            }
        }
        return [$queues, $jobs];
    }

    /**
     * Every matching item of the app's partition, page by page, with only what sorting and counting need.
     *
     * @return list<array{id: string, queue: string, failed_at: int}>
     */
    private static function dynamoKeys(DynamoDbFailedJobProvider $failer, ?string $queue): array
    {
        [$client, $table, $application] = self::dynamo($failer);
        $pages = $client->getPaginator('Query', [
            'TableName' => $table,
            'KeyConditionExpression' => '#application = :application',
            'ProjectionExpression' => '#uuid, #queue, #failed_at',
            'ExpressionAttributeNames' => ['#application' => 'application', '#uuid' => 'uuid', '#queue' => 'queue', '#failed_at' => 'failed_at'],
            'ExpressionAttributeValues' => [':application' => ['S' => $application]],
        ]);
        $keys = [];
        foreach ($pages as $page) {
            foreach (self::items($page['Items'] ?? []) as $item) {
                $key = ['id' => self::attribute($item, 'uuid'), 'queue' => self::attribute($item, 'queue'), 'failed_at' => (int) self::attribute($item, 'failed_at')];
                if ($queue === null || $key['queue'] === $queue) {
                    $keys[] = $key;
                }
            }
        }
        return $keys;
    }

    /**
     * The provider's client, table and application name, which it keeps protected.
     *
     * @return array{DynamoDbClient, string, string}
     */
    private static function dynamo(DynamoDbFailedJobProvider $failer): array
    {
        /** @var array{mixed, mixed, mixed} $parts */
        $parts = Closure::bind(fn (): array => [$this->dynamo, $this->table, $this->applicationName], $failer, DynamoDbFailedJobProvider::class)();
        [$client, $table, $application] = $parts;
        if (! $client instanceof DynamoDbClient) {
            throw new \RuntimeException('The DynamoDB failed-jobs store has no DynamoDB client.');
        }
        return [$client, self::text($table), self::text($application)];
    }

    /** @return list<array<mixed>> */
    private static function items(mixed $items): array
    {
        return is_array($items) ? array_values(array_filter($items, is_array(...))) : [];
    }

    /** @param array<mixed> $item a DynamoDB item: the attribute's S or N value */
    private static function attribute(array $item, string $name): string
    {
        $value = $item[$name] ?? null;
        return is_array($value) ? self::text($value['S'] ?? $value['N'] ?? null) : '';
    }

    /**
     * Any other store (file, null, the app's own): through its all().
     *
     * @return array{array<string, int>, list<array{id: string, connection: mixed, queue: mixed, payload: mixed, exception: mixed, failed_at: string}>}
     */
    private static function fromAll(FailedJobProviderInterface $failer, ?string $queue): array
    {
        $jobs = self::matching($failer, $queue);
        $queues = [];
        foreach ($jobs as $job) {
            $name = self::text($job['queue']);
            $queues[$name] = ($queues[$name] ?? 0) + 1;
        }
        usort($jobs, static fn (array $a, array $b): int => [strtotime($b['failed_at']) ?: 0, $b['id']] <=> [strtotime($a['failed_at']) ?: 0, $a['id']]);
        return [$queues, array_slice($jobs, 0, self::LIMIT)];
    }

    /** @return list<array{id: string, connection: mixed, queue: mixed, payload: mixed, exception: mixed, failed_at: string}> */
    private static function matching(FailedJobProviderInterface $failer, ?string $queue): array
    {
        $jobs = [];
        foreach ($failer->all() as $job) {
            $job = $job instanceof stdClass ? (array) $job : (is_array($job) ? $job : []);
            if (! isset($job['id']) || ($queue !== null && ($job['queue'] ?? null) !== $queue)) {
                continue;
            }
            $jobs[] = [
                'id' => self::text($job['id']),
                'connection' => $job['connection'] ?? null,
                'queue' => $job['queue'] ?? null,
                'payload' => $job['payload'] ?? null,
                'exception' => $job['exception'] ?? null,
                'failed_at' => self::text($job['failed_at'] ?? null),
            ];
        }
        return $jobs;
    }
}
