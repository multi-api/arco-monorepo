<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Integration;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\DynamoDbStore;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\QueueServiceProvider;
use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Deploy\DeployPipeline;
use Multiapi\Arco\Deploy\ResourcesStack;
use Multiapi\Arco\Laravel\ArcoServiceProvider;
use Multiapi\Arco\Tests\Support\SiblingContainers;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Process\Process;

/**
 * The cache and failed-jobs tables exactly as the resources stack creates them (KeySchema,
 * AttributeDefinitions and TimeToLiveSpecification from ResourcesStack::template()), in DynamoDB Local,
 * used by Laravel's own DynamoDB cache store and failed-job provider as arco-laravel configures them
 * from the variables the deploy sets. The app's config/cache.php copied Bref's id/ttl attributes: on a
 * table Arco created, arco-laravel overrides them (E6). The TTL the template enables must name the
 * attribute Laravel writes, or DynamoDB never deletes the items (Review Focus 5).
 */
#[Group('integration')]
final class DynamoDbTablesTest extends TestCase
{
    private const IMAGE = 'amazon/dynamodb-local:2.6.1';
    private const REGION = 'us-east-1';
    private ?string $container = null;
    /** @var array<string, string> */
    private array $variables = [];

    protected function setUp(): void
    {
        $docker = new Process(['docker', 'info']);
        $docker->run();
        if (! $docker->isSuccessful()) {
            self::markTestSkipped('Docker is not available.');
        }
    }

    protected function tearDown(): void
    {
        if ($this->container !== null) {
            (new Process(['docker', 'rm', '-f', $this->container]))->run();
        }
        foreach (array_keys($this->variables) as $name) {
            unset($_SERVER[$name]);
        }
    }

    public function test_laravel_uses_the_tables_of_the_template_as_arco_laravel_configures_it(): void
    {
        $raw = App::make('shop')->region(self::REGION)->cache()->failedJobsTable()->environment('ci')->toArray();
        $config = ConfigResolver::resolve($raw, 'ci');
        $resources = ResourcesStack::template($config)['Resources'];
        self::assertIsArray($resources);
        $cacheTable = $resources[ResourcesStack::tableLogicalId('cache')]['Properties'];
        $failedTable = $resources[ResourcesStack::tableLogicalId('failed-jobs')]['Properties'];

        $endpoint = $this->startDynamoDbLocal();
        $dynamo = new DynamoDbClient(['region' => self::REGION, 'version' => 'latest', 'endpoint' => $endpoint, 'credentials' => self::credentials()]);
        $this->waitFor($dynamo);
        $this->createTable($dynamo, $cacheTable);
        $this->createTable($dynamo, $failedTable);

        // What the function gets: the deploy's variables and Lambda's temporary credentials.
        $this->environment([
            ...DeployPipeline::tableVariables($config),
            'ARCO' => '1',
            'AWS_REGION' => self::REGION,
            'AWS_ACCESS_KEY_ID' => self::credentials()['key'],
            'AWS_SECRET_ACCESS_KEY' => self::credentials()['secret'],
            'AWS_SESSION_TOKEN' => self::credentials()['token'],
        ]);
        self::assertSame($cacheTable['TableName'], $this->variables['ARCO_CACHE_TABLE'] ?? null);
        self::assertSame($failedTable['TableName'], $this->variables['ARCO_FAILED_JOBS_TABLE'] ?? null);

        $app = new Application(sys_get_temp_dir());
        $app->instance('config', new Repository([
            'app' => ['name' => 'shop'],
            // The app's config/cache.php, as copied from Bref: id/ttl, and the stock DYNAMODB_ENDPOINT
            // pointed at DynamoDB Local.
            'cache' => ['default' => 'dynamodb', 'prefix' => '', 'stores' => ['dynamodb' => [
                'driver' => 'dynamodb',
                'key' => self::credentials()['key'],
                'secret' => self::credentials()['secret'],
                'region' => self::REGION,
                'table' => 'cache',
                'attributes' => ['key' => 'id', 'value' => 'value', 'expiration' => 'ttl'],
                'endpoint' => $endpoint,
            ]]],
            // Stock config/queue.php, plus the endpoint of DynamoDB Local.
            'queue' => ['default' => 'sync', 'failed' => ['driver' => 'dynamodb', 'database' => 'sqlite', 'table' => 'failed_jobs', 'endpoint' => $endpoint]],
        ]));
        (new ArcoServiceProvider($app))->register();
        (new QueueServiceProvider($app))->register();

        // The cache store.
        $cache = (new CacheManager($app))->store('dynamodb');
        $store = $cache->getStore();
        self::assertInstanceOf(DynamoDbStore::class, $store);
        $cache->put('greeting', 'hola', 600);
        self::assertSame('hola', $cache->get('greeting'));
        $expiration = (new ReflectionProperty(DynamoDbStore::class, 'expirationAttribute'))->getValue($store);
        self::assertSame($cacheTable['TimeToLiveSpecification']['AttributeName'], $expiration, 'the cache table TTL names the attribute the store writes');
        $item = $dynamo->getItem(['TableName' => $cacheTable['TableName'], 'Key' => ['key' => ['S' => 'greeting']]])['Item'];
        self::assertIsArray($item);
        self::assertArrayHasKey($expiration, $item);
        self::assertGreaterThan(time(), (int) $item[$expiration]['N']);

        // The failed-job provider.
        $failer = $app->make('queue.failer');
        self::assertInstanceOf(FailedJobProviderInterface::class, $failer);
        $failer->log('sqs', 'emails', (string) json_encode(['uuid' => 'job-1', 'displayName' => 'App\\Jobs\\SendEmail']), new \RuntimeException('boom'));
        self::assertSame('job-1', $failer->find('job-1')?->id);
        $failed = $dynamo->getItem(['TableName' => $failedTable['TableName'], 'Key' => ['application' => ['S' => 'shop'], 'uuid' => ['S' => 'job-1']]])['Item'];
        self::assertIsArray($failed);
        self::assertSame(ResourcesStack::TTL_ATTRIBUTE, $failedTable['TimeToLiveSpecification']['AttributeName']);
        self::assertArrayHasKey($failedTable['TimeToLiveSpecification']['AttributeName'], $failed, 'the failed-jobs table TTL names the attribute the provider writes');
        self::assertGreaterThan(time(), (int) $failed['expires_at']['N']);

        // The TTL DynamoDB has on each table is the template's.
        foreach ([$cacheTable, $failedTable] as $table) {
            $ttl = $dynamo->describeTimeToLive(['TableName' => $table['TableName']])['TimeToLiveDescription'];
            self::assertSame(ResourcesStack::TTL_ATTRIBUTE, $ttl['AttributeName'] ?? null);
        }
    }

    /** @return array{key: string, secret: string, token: string} */
    private static function credentials(): array
    {
        return ['key' => 'ASIAARCOTEST', 'secret' => 'secret', 'token' => 'session-token'];
    }

    /** @param array<string, string> $variables */
    private function environment(array $variables): void
    {
        foreach ($variables as $name => $value) {
            $_SERVER[$name] = $value;
            $this->variables[$name] = $value;
        }
    }

    /** Starts DynamoDB Local next to the test process and returns its endpoint. */
    private function startDynamoDbLocal(): string
    {
        $this->container = 'arco-dynamodb-it-' . bin2hex(random_bytes(3));
        $network = SiblingContainers::network();
        $options = $network === null ? ['-p', '127.0.0.1::8000'] : ['--network', $network];
        $run = new Process(['docker', 'run', '-d', '--name', $this->container, ...$options, self::IMAGE, '-jar', 'DynamoDBLocal.jar', '-inMemory', '-sharedDb'], null, null, null, 300);
        $run->run();
        self::assertTrue($run->isSuccessful(), $run->getErrorOutput());
        if ($network !== null) {
            return "http://{$this->container}:8000";
        }
        $port = new Process(['docker', 'port', $this->container, '8000/tcp']);
        $port->mustRun();
        return 'http://' . trim(explode("\n", trim($port->getOutput()))[0]);
    }

    private function waitFor(DynamoDbClient $dynamo): void
    {
        $deadline = microtime(true) + 60;
        while (true) {
            try {
                $dynamo->listTables();
                return;
            } catch (\Throwable $e) {
                if (microtime(true) > $deadline) {
                    $logs = new Process(['docker', 'logs', (string) $this->container]);
                    $logs->run();
                    self::fail('DynamoDB Local did not start: ' . $e->getMessage() . "\n" . $logs->getOutput() . $logs->getErrorOutput());
                }
                usleep(250_000);
            }
        }
    }

    /** Creates a table with the key, attributes and TTL of the template's properties. @param array<string, mixed> $properties */
    private function createTable(DynamoDbClient $dynamo, array $properties): void
    {
        $dynamo->createTable([
            'TableName' => $properties['TableName'],
            'BillingMode' => $properties['BillingMode'],
            'KeySchema' => $properties['KeySchema'],
            'AttributeDefinitions' => $properties['AttributeDefinitions'],
        ]);
        try {
            $dynamo->updateTimeToLive(['TableName' => $properties['TableName'], 'TimeToLiveSpecification' => $properties['TimeToLiveSpecification']]);
        } catch (DynamoDbException $e) {
            self::fail('UpdateTimeToLive: ' . $e->getMessage());
        }
    }
}
