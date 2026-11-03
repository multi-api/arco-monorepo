<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Laravel;

use Aws\CommandInterface;
use Aws\DynamoDb\DynamoDbClient;
use Aws\Result;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Console\Application as Artisan;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Queue\Console\FlushFailedCommand;
use Illuminate\Queue\Failed\DatabaseFailedJobProvider;
use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider;
use Illuminate\Queue\Failed\DynamoDbFailedJobProvider;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\Failed\FileFailedJobProvider;
use Multiapi\Arco\Laravel\ArcoServiceProvider;
use Multiapi\Arco\Tests\Support\TempPaths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class FailedJobsCommandTest extends TestCase
{
    private const EMAILS = 'https://sqs.us-east-1.amazonaws.com/123456789012/shop-production-emails';
    private const REPORTS = 'https://sqs.us-east-1.amazonaws.com/123456789012/shop-production-reports';

    private Application $app;
    private FailedJobProviderInterface $failer;
    private ?Capsule $db = null;
    /** @var array<string, array<string, array<string, string>>> DynamoDB items by uuid */
    private array $items = [];
    /** @var list<string> the DynamoDB operations the fake served */
    private array $calls = [];
    /** How many items one BatchGetItem returns; the others come back as UnprocessedKeys. */
    private int $batchItems = 100;

    protected function setUp(): void
    {
        $this->app = new Application(sys_get_temp_dir());
        Container::setInstance($this->app);
    }

    protected function tearDown(): void
    {
        FlushFailedCommand::prohibit(false);
        Artisan::forgetBootstrappers();
        Container::setInstance(null);
    }

    /** @return iterable<string, array{string}> */
    public static function stores(): iterable
    {
        yield 'database' => ['database'];
        yield 'database-uuids' => ['database-uuids'];
        yield 'dynamodb' => ['dynamodb'];
    }

    private function useStore(string $store): void
    {
        $this->failer = match ($store) {
            'database', 'database-uuids' => $this->databaseFailer($store === 'database-uuids'),
            default => $this->dynamoFailer(),
        };
        $this->app->instance('queue.failer', $this->failer);
    }

    private function databaseFailer(bool $uuids): FailedJobProviderInterface
    {
        $this->db = new Capsule();
        $this->db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->db->getConnection()->getSchemaBuilder()->create('failed_jobs', static function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at');
        });
        $resolver = $this->db->getDatabaseManager();
        return $uuids
            ? new DatabaseUuidFailedJobProvider($resolver, 'default', 'failed_jobs')
            : new DatabaseFailedJobProvider($resolver, 'default', 'failed_jobs');
    }

    /**
     * A DynamoDbClient whose handler keeps the table in memory and returns Query results three
     * items per page, as a table past DynamoDB's 1 MB page would.
     */
    private function dynamoFailer(): FailedJobProviderInterface
    {
        $client = new DynamoDbClient([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'handler' => fn (CommandInterface $command, RequestInterface $request): PromiseInterface => Create::promiseFor($this->dynamo($command)),
        ]);
        return new DynamoDbFailedJobProvider($client, 'shop', 'shop-production-failed-jobs');
    }

    private function dynamo(CommandInterface $command): Result
    {
        $this->calls[] = $command->getName();
        $key = static function (mixed $key): string {
            self::assertIsArray($key);
            self::assertSame(['S' => 'shop'], $key['application']);
            self::assertIsArray($key['uuid']);
            self::assertIsString($key['uuid']['S']);
            return $key['uuid']['S'];
        };
        switch ($command->getName()) {
            case 'Query':
                $values = $command['ExpressionAttributeValues'];
                self::assertIsArray($values);
                self::assertSame([['S' => 'shop']], array_values($values));
                $uuids = array_keys($this->items);
                rsort($uuids);
                $start = $command['ExclusiveStartKey'] === null ? 0 : array_search($key($command['ExclusiveStartKey']), $uuids, true) + 1;
                $page = array_slice($uuids, (int) $start, 3);
                $result = ['Items' => array_map(fn (string $uuid): array => $this->items[$uuid], $page)];
                if ((int) $start + 3 < count($uuids)) {
                    $result['LastEvaluatedKey'] = ['application' => ['S' => 'shop'], 'uuid' => ['S' => $page[2]]];
                }
                return new Result($result);
            case 'BatchGetItem':
                $request = $command['RequestItems'];
                self::assertIsArray($request);
                self::assertIsArray($request['shop-production-failed-jobs']);
                $keys = $request['shop-production-failed-jobs']['Keys'];
                self::assertIsArray($keys);
                self::assertLessThanOrEqual(100, count($keys));
                $found = [];
                foreach (array_slice($keys, 0, $this->batchItems) as $k) {
                    if (isset($this->items[$key($k)])) {
                        $found[] = $this->items[$key($k)];
                    }
                }
                $left = array_slice($keys, $this->batchItems);
                return new Result(['Responses' => ['shop-production-failed-jobs' => $found], 'UnprocessedKeys' => $left === [] ? [] : ['shop-production-failed-jobs' => ['Keys' => $left]]]);
            case 'DeleteItem':
                unset($this->items[$key($command['Key'])]);
                return new Result([]);
        }
        self::fail('Unexpected DynamoDB call ' . $command->getName());
    }

    /** Adds a failed job that failed $minutes after 2026-11-03 10:00 UTC. */
    private function fail_(int $n, int $minutes, string $queue = self::EMAILS, string $exception = 'RuntimeException: boom', string $job = 'App\\Jobs\\SendEmail'): string
    {
        $uuid = sprintf('00000000-0000-4000-8000-%012d', $n);
        $payload = json_encode(['uuid' => $uuid, 'displayName' => $job, 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call'], JSON_THROW_ON_ERROR);
        $time = 1793700000 + $minutes * 60; // 2026-11-03 10:00:00 UTC
        if ($this->db !== null) {
            $this->db->getConnection()->table('failed_jobs')->insert([
                'uuid' => $uuid, 'connection' => 'sqs', 'queue' => $queue, 'payload' => $payload,
                'exception' => $exception, 'failed_at' => gmdate('Y-m-d H:i:s', $time),
            ]);
        } else {
            $this->items[$uuid] = [
                'application' => ['S' => 'shop'], 'uuid' => ['S' => $uuid], 'connection' => ['S' => 'sqs'],
                'queue' => ['S' => $queue], 'payload' => ['S' => $payload], 'exception' => ['S' => $exception],
                'failed_at' => ['N' => (string) $time], 'expires_at' => ['N' => (string) ($time + 604800)],
            ];
        }
        return $uuid;
    }

    /** @return list<string> the ids of the failed jobs left */
    private function ids(): array
    {
        $ids = array_map(static fn (mixed $job): string => is_object($job) && isset($job->id) && is_scalar($job->id) ? (string) $job->id : '', $this->failer->all());
        sort($ids);
        return $ids;
    }

    /** @param array<string, mixed> $options @return array{int, string} */
    private function artisan(array $options = []): array
    {
        (new ArcoServiceProvider($this->app))->boot();
        $artisan = new Artisan($this->app, new Dispatcher($this->app), 'test');
        $artisan->setAutoExit(false);
        $artisan->setCatchExceptions(true);
        $output = new BufferedOutput();
        $code = $artisan->run(new ArrayInput(['command' => 'arco:failed-jobs', ...$options]), $output);
        return [$code, $output->fetch()];
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    private function list(array $options = ['--json' => true]): array
    {
        [$code, $output] = $this->artisan($options);
        self::assertSame(0, $code, $output);
        $lines = explode("\n", rtrim($output, "\n"));
        self::assertCount(1, $lines, 'One JSON line and nothing else: ' . $output);
        $decoded = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        return $decoded;
    }

    #[DataProvider('stores')]
    public function test_lists_the_failed_jobs_newest_first_as_one_json_line(string $store): void
    {
        $this->useStore($store);
        $older = $this->fail_(1, 0, self::EMAILS, "RuntimeException: boom\n#0 {main}");
        $newer = $this->fail_(2, 5, self::REPORTS, 'LogicException: nope', 'App\\Jobs\\BuildReport');

        $list = $this->list();

        self::assertSame(['store', 'total', 'queues', 'jobs'], array_keys($list));
        self::assertSame($store === 'dynamodb' ? 'dynamodb' : 'database', $list['store']);
        self::assertSame(2, $list['total']);
        self::assertSame([self::EMAILS => 1, self::REPORTS => 1], $list['queues']);
        self::assertIsArray($list['jobs']);
        self::assertCount(2, $list['jobs']);
        $ids = $store === 'database' ? ['2', '1'] : [$newer, $older];
        self::assertSame(['id', 'connection', 'queue', 'job', 'failed_at', 'exception'], array_keys($list['jobs'][0]));
        self::assertSame($ids[0], $list['jobs'][0]['id']);
        self::assertSame('sqs', $list['jobs'][0]['connection']);
        self::assertSame(self::REPORTS, $list['jobs'][0]['queue']);
        self::assertSame('App\\Jobs\\BuildReport', $list['jobs'][0]['job']);
        self::assertSame('LogicException: nope', $list['jobs'][0]['exception']);
        self::assertSame($ids[1], $list['jobs'][1]['id']);
        self::assertSame("RuntimeException: boom\n#0 {main}", $list['jobs'][1]['exception']);
        self::assertIsString($list['jobs'][0]['failed_at']);
        self::assertSame(1793700300, strtotime($list['jobs'][0]['failed_at'] . ($store === 'dynamodb' ? '' : ' UTC')));
    }

    #[DataProvider('stores')]
    public function test_an_empty_store_lists_nothing_with_queues_as_an_object(string $store): void
    {
        $this->useStore($store);

        [$code, $output] = $this->artisan(['--json' => true]);

        self::assertSame(0, $code);
        self::assertSame('{"store":"' . ($store === 'dynamodb' ? 'dynamodb' : 'database') . '","total":0,"queues":{},"jobs":[]}' . "\n", $output);
    }

    public function test_without_options_it_lists(): void
    {
        $this->useStore('database');
        $this->fail_(1, 0);

        self::assertSame(1, $this->list([])['total']);
    }

    #[DataProvider('stores')]
    public function test_the_list_keeps_the_200_newest_but_counts_every_job(string $store): void
    {
        $this->useStore($store);
        for ($n = 1; $n <= 205; $n++) {
            $this->fail_($n, $n, $n % 5 === 0 ? self::REPORTS : self::EMAILS);
        }

        $list = $this->list();

        self::assertSame(205, $list['total']);
        self::assertSame([self::EMAILS => 164, self::REPORTS => 41], $list['queues']);
        self::assertIsArray($list['jobs']);
        self::assertCount(200, $list['jobs']);
        $first = $list['jobs'][0];
        $last = $list['jobs'][199];
        self::assertIsArray($first);
        self::assertIsArray($last);
        $id = static fn (int $n): string => $store === 'database' ? (string) $n : sprintf('00000000-0000-4000-8000-%012d', $n);
        self::assertSame($id(205), $first['id']);
        self::assertSame($id(6), $last['id']);
    }

    #[DataProvider('stores')]
    public function test_each_exception_is_cut_to_8_kb_on_a_character_boundary(string $store): void
    {
        $this->useStore($store);
        $this->fail_(1, 0, exception: 'a' . str_repeat('é', 5000)); // 10001 bytes; é is 2 bytes

        $list = $this->list();

        self::assertIsArray($list['jobs']);
        self::assertIsArray($list['jobs'][0]);
        $exception = $list['jobs'][0]['exception'];
        self::assertIsString($exception);
        self::assertSame(8191, strlen($exception));
        self::assertTrue(mb_check_encoding($exception, 'UTF-8'));
        self::assertSame('a' . str_repeat('é', 4095), $exception);
    }

    #[DataProvider('stores')]
    public function test_queue_filters_the_list(string $store): void
    {
        $this->useStore($store);
        $this->fail_(1, 0, self::EMAILS);
        $this->fail_(2, 1, self::REPORTS);
        $this->fail_(3, 2, self::EMAILS);

        $list = $this->list(['--json' => true, '--queue' => self::EMAILS]);

        self::assertSame(2, $list['total']);
        self::assertSame([self::EMAILS => 2], $list['queues']);
        self::assertIsArray($list['jobs']);
        self::assertSame([self::EMAILS, self::EMAILS], array_column($list['jobs'], 'queue'));
    }

    public function test_the_job_name_falls_back_to_the_payload_job_then_to_nothing(): void
    {
        $this->useStore('database');
        self::assertNotNull($this->db);
        foreach ([['job' => 'App\\Legacy@handle'], []] as $n => $payload) {
            $this->db->getConnection()->table('failed_jobs')->insert([
                'uuid' => "u$n", 'connection' => 'sqs', 'queue' => self::EMAILS, 'payload' => json_encode($payload),
                'exception' => 'x', 'failed_at' => '2026-11-03 10:0' . $n . ':00',
            ]);
        }

        $list = $this->list();

        self::assertIsArray($list['jobs']);
        self::assertSame(['', 'App\\Legacy@handle'], array_column($list['jobs'], 'job'));
    }

    #[DataProvider('stores')]
    public function test_flush_forgets_every_job_one_by_one(string $store): void
    {
        $this->useStore($store);
        for ($n = 1; $n <= 7; $n++) {
            $this->fail_($n, $n);
        }

        [$code, $output] = $this->artisan(['--flush' => true]);

        self::assertSame(0, $code, $output);
        self::assertSame("7 failed jobs forgotten.\n", $output);
        self::assertSame([], $this->ids());
        if ($store === 'dynamodb') {
            self::assertSame(7, count(array_keys($this->calls, 'DeleteItem', true)));
        }
    }

    #[DataProvider('stores')]
    public function test_flush_with_a_queue_forgets_only_that_queue(string $store): void
    {
        $this->useStore($store);
        $this->fail_(1, 0, self::EMAILS);
        $kept = $this->fail_(2, 1, self::REPORTS);
        $this->fail_(3, 2, self::EMAILS);

        [$code, $output] = $this->artisan(['--flush' => true, '--queue' => self::EMAILS]);

        self::assertSame(0, $code, $output);
        self::assertSame("2 failed jobs forgotten.\n", $output);
        self::assertSame([$store === 'database' ? '2' : $kept], $this->ids());
    }

    #[DataProvider('stores')]
    public function test_flushing_an_empty_store_forgets_nothing(string $store): void
    {
        $this->useStore($store);

        self::assertSame([0, "0 failed jobs forgotten.\n"], $this->artisan(['--flush' => true]));
    }

    public function test_flush_with_json_prints_the_count_as_json(): void
    {
        $this->useStore('database');
        $this->fail_(1, 0);

        self::assertSame([0, "{\"flushed\":1}\n"], $this->artisan(['--flush' => true, '--json' => true]));
        self::assertSame([], $this->ids());
    }

    public function test_one_forgotten_job_reads_in_the_singular(): void
    {
        $this->useStore('dynamodb');
        $this->fail_(1, 0);

        self::assertSame([0, "1 failed job forgotten.\n"], $this->artisan(['--flush' => true]));
    }

    #[DataProvider('stores')]
    public function test_a_prohibited_flush_forgets_nothing(string $store): void
    {
        $this->useStore($store);
        $id = $this->fail_(1, 0);
        FlushFailedCommand::prohibit();

        [$code, $output] = $this->artisan(['--flush' => true]);

        self::assertSame(1, $code);
        self::assertStringContainsString('This command is prohibited from running in this environment.', $output);
        self::assertSame([$store === 'database' ? '1' : $id], $this->ids());
    }

    public function test_a_store_error_fails_the_flush_and_says_how_many_were_forgotten(): void
    {
        $this->useStore('dynamodb');
        $this->fail_(1, 0);
        $this->fail_(2, 1);
        $client = new DynamoDbClient([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'handler' => function (CommandInterface $command, RequestInterface $request): PromiseInterface {
                if ($command->getName() === 'DeleteItem' && count(array_keys($this->calls, 'DeleteItem', true)) === 1) {
                    return Create::rejectionFor(new \Aws\DynamoDb\Exception\DynamoDbException('Access denied', $command));
                }
                return Create::promiseFor($this->dynamo($command));
            },
        ]);
        $this->failer = new DynamoDbFailedJobProvider($client, 'shop', 'shop-production-failed-jobs');
        $this->app->instance('queue.failer', $this->failer);

        [$code, $output] = $this->artisan(['--flush' => true]);

        self::assertSame(1, $code);
        self::assertStringContainsString('1 failed job forgotten before an error: Access denied', $output);
        self::assertCount(1, $this->ids());
    }

    public function test_dynamodb_keys_left_unprocessed_are_asked_again(): void
    {
        $this->useStore('dynamodb');
        $this->batchItems = 30;
        for ($n = 1; $n <= 150; $n++) {
            $this->fail_($n, $n);
        }

        $list = $this->list();

        self::assertSame(150, $list['total']);
        self::assertIsArray($list['jobs']);
        self::assertCount(150, $list['jobs']);
        // 150 keys in two chunks (100, 50), each 30 at a time: 4 + 2 calls.
        self::assertSame(6, count(array_keys($this->calls, 'BatchGetItem', true)));
    }

    public function test_another_store_is_read_through_its_all(): void
    {
        $this->failer = new FileFailedJobProvider(TempPaths::path('arco-failed-jobs-', '.json'));
        $this->app->instance('queue.failer', $this->failer);
        $this->failer->log('sqs', self::EMAILS, json_encode(['uuid' => 'a', 'displayName' => 'App\\Jobs\\A'], JSON_THROW_ON_ERROR), new \RuntimeException('boom'));
        $this->failer->log('sqs', self::REPORTS, json_encode(['uuid' => 'b', 'displayName' => 'App\\Jobs\\B'], JSON_THROW_ON_ERROR), new \RuntimeException('bang'));

        $list = $this->list(['--json' => true, '--queue' => self::REPORTS]);

        self::assertSame('file', $list['store']);
        self::assertSame(1, $list['total']);
        self::assertIsArray($list['jobs']);
        self::assertSame(['b'], array_column($list['jobs'], 'id'));
        self::assertSame(['App\\Jobs\\B'], array_column($list['jobs'], 'job'));

        self::assertSame([0, "1 failed job forgotten.\n"], $this->artisan(['--flush' => true, '--queue' => self::REPORTS]));
        self::assertSame(['a'], $this->ids());
    }

    public function test_the_provider_registers_the_command_even_outside_lambda(): void
    {
        unset($_SERVER['ARCO']);
        (new ArcoServiceProvider($this->app))->boot();
        $artisan = new Artisan($this->app, new Dispatcher($this->app), 'test');

        self::assertTrue($artisan->has('arco:failed-jobs'));
        self::assertFalse($artisan->get('arco:failed-jobs')->isHidden());
    }
}
