<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Laravel;

use Illuminate\Container\Container;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\SqsQueue;
use Illuminate\Support\Facades\Facade;
use Multiapi\Arco\Laravel\ArcoServiceProvider;
use Multiapi\Arco\Laravel\Queue\ArcoSqsQueue;
use Multiapi\Arco\Tests\Unit\Laravel\Fixtures\QueueTestApp;
use PHPUnit\Framework\TestCase;

final class ArcoSqsQueueTest extends TestCase
{
    private const ENV = ['ARCO', 'ARCO_QUEUES', 'ARCO_WORKER_QUEUE', 'ARCO_QUEUE_TRIES', 'AWS_ACCESS_KEY_ID', 'AWS_SESSION_TOKEN'];

    protected function tearDown(): void
    {
        foreach (self::ENV as $name) {
            unset($_SERVER[$name]);
        }
        TrustProxies::flushState();
        Container::setInstance(null);
        Facade::clearResolvedInstances();
    }

    private function queue(QueueTestApp $test): SqsQueue
    {
        (new ArcoServiceProvider($test->app))->register();
        $manager = $test->app->make('queue');
        self::assertInstanceOf(QueueManager::class, $manager);
        $queue = $manager->connection('sqs');
        self::assertInstanceOf(SqsQueue::class, $queue);
        return $queue;
    }

    public function test_logical_names_map_to_their_queue_urls(): void
    {
        $_SERVER += QueueTestApp::env();
        $queue = $this->queue(new QueueTestApp(sys_get_temp_dir()));

        self::assertInstanceOf(ArcoSqsQueue::class, $queue);
        self::assertSame(QueueTestApp::EMAILS_URL, $queue->getQueue('emails'));
        self::assertSame(QueueTestApp::DEFAULT_URL, $queue->getQueue(null));
        self::assertSame('https://sqs.eu-west-1.amazonaws.com/1/other', $queue->getQueue('https://sqs.eu-west-1.amazonaws.com/1/other'));
        self::assertSame(QueueTestApp::EMAILS_URL, $queue->getQueue(QueueName::Emails));
        self::assertSame(QueueTestApp::PREFIX . '/unknown', $queue->getQueue('unknown'), 'an unknown name keeps Laravel\'s prefix behaviour');
    }

    public function test_a_dispatched_job_reaches_the_physical_queue(): void
    {
        $_SERVER += QueueTestApp::env();
        $test = new QueueTestApp(sys_get_temp_dir());

        $this->queue($test)->pushRaw('{"job":"x","data":[]}', 'emails');

        self::assertSame(QueueTestApp::EMAILS_URL, $test->callsTo('SendMessage')[0]['QueueUrl'] ?? null);
    }

    private const ORDERS_URL = QueueTestApp::PREFIX . '/shop-production-orders.fifo';

    private static function fifoEnv(): void
    {
        $_SERVER += QueueTestApp::env();
        $_SERVER['ARCO_QUEUES'] = (string) json_encode(['default' => QueueTestApp::DEFAULT_URL, 'orders' => self::ORDERS_URL], JSON_UNESCAPED_SLASHES);
    }

    public function test_a_raw_payload_to_a_fifo_queue_gets_a_group_and_a_deduplication_id(): void
    {
        // queue:retry, and every push on Laravel 11, send without FIFO options.
        self::fifoEnv();
        $test = new QueueTestApp(sys_get_temp_dir());

        $this->queue($test)->pushRaw('{"uuid":"u-1","job":"x","data":[]}', 'orders');

        $sent = $test->callsTo('SendMessage')[0] ?? [];
        self::assertSame(self::ORDERS_URL, $sent['QueueUrl'] ?? null);
        self::assertSame('orders', $sent['MessageGroupId'] ?? null);
        self::assertSame('u-1', $sent['MessageDeduplicationId'] ?? null);
    }

    public function test_a_fifo_send_drops_a_delay_and_keeps_a_group_laravel_set(): void
    {
        self::fifoEnv();
        $test = new QueueTestApp(sys_get_temp_dir());
        $queue = $this->queue($test);

        $queue->pushRaw('{"job":"x","data":[]}', 'orders', ['DelaySeconds' => 30]);
        $queue->pushRaw('{"job":"x","data":[]}', 'orders', ['MessageGroupId' => 'tenant-1', 'MessageDeduplicationId' => 'd-1']);

        [$delayed, $grouped] = $test->callsTo('SendMessage');
        self::assertArrayNotHasKey('DelaySeconds', $delayed, 'FIFO queues refuse a per-message delay');
        self::assertSame('orders', $delayed['MessageGroupId']);
        self::assertIsString($delayed['MessageDeduplicationId']);
        self::assertNotSame('', $delayed['MessageDeduplicationId'], 'a payload without uuid gets a random id');
        self::assertSame(['tenant-1', 'd-1'], [$grouped['MessageGroupId'], $grouped['MessageDeduplicationId']]);

        $queue->pushRaw('{"job":"x","data":[]}', self::ORDERS_URL);
        self::assertSame('shop-production-orders.fifo', $test->callsTo('SendMessage')[2]['MessageGroupId'] ?? null, 'a URL gives its queue name, not the whole URL');
    }

    public function test_a_standard_queue_send_is_left_as_it_is(): void
    {
        $_SERVER += QueueTestApp::env();
        $test = new QueueTestApp(sys_get_temp_dir());

        $this->queue($test)->pushRaw('{"uuid":"u-1","job":"x","data":[]}', 'emails', ['DelaySeconds' => 30]);

        $sent = $test->callsTo('SendMessage')[0] ?? [];
        self::assertSame(30, $sent['DelaySeconds'] ?? null);
        self::assertArrayNotHasKey('MessageGroupId', $sent);
        self::assertArrayNotHasKey('MessageDeduplicationId', $sent);
    }

    public function test_queueable_options_see_the_mapped_fifo_url_of_a_logical_name(): void
    {
        // Laravel 12 checks `.fifo` on the name it is given, without mapping it.
        self::fifoEnv();
        $queue = $this->queue(new QueueTestApp(sys_get_temp_dir()));
        self::assertInstanceOf(ArcoSqsQueue::class, $queue);

        $options = $queue->getQueueableOptions('App\\Jobs\\Ship', 'orders', '{"job":"x"}', 30);

        self::assertArrayNotHasKey('DelaySeconds', $options);
        self::assertArrayHasKey('MessageGroupId', $options);
        self::assertArrayHasKey('MessageDeduplicationId', $options);
    }

    public function test_a_delayed_job_on_a_fifo_queue_is_sent_without_its_delay(): void
    {
        self::fifoEnv();
        $test = new QueueTestApp(sys_get_temp_dir());

        $this->queue($test)->later(30, 'App\\Jobs\\Ship', [], 'orders');

        $sent = $test->callsTo('SendMessage')[0] ?? [];
        self::assertSame(self::ORDERS_URL, $sent['QueueUrl'] ?? null);
        self::assertArrayNotHasKey('DelaySeconds', $sent);
        self::assertArrayHasKey('MessageGroupId', $sent);
    }

    public function test_on_laravel_11_a_delayed_job_on_a_fifo_queue_goes_through_push(): void
    {
        // Laravel 11's later() sends DelaySeconds itself and never calls pushRaw with options.
        self::fifoEnv();
        $test = new QueueTestApp(sys_get_temp_dir());
        $this->queue($test);
        $queue = new class (new \Aws\Sqs\SqsClient(['region' => 'us-east-1', 'version' => 'latest', 'credentials' => false, 'handler' => fn () => \GuzzleHttp\Promise\Create::promiseFor(new \Aws\Result([]))]), QueueTestApp::DEFAULT_URL, QueueTestApp::PREFIX, '', false, [], ['orders' => self::ORDERS_URL, 'emails' => QueueTestApp::EMAILS_URL]) extends ArcoSqsQueue {
            /** @var list<array{string, mixed}> */
            public array $pushed = [];

            protected function laravelSetsQueueableOptions(): bool
            {
                return false;
            }

            public function push($job, $data = '', $queue = null)
            {
                $this->pushed[] = [$job, $queue];
                return 'id';
            }
        };
        $queue->setContainer($test->app);

        self::assertSame('id', $queue->later(30, 'App\\Jobs\\Ship', [], 'orders'));
        self::assertSame([['App\\Jobs\\Ship', 'orders']], $queue->pushed);
    }

    public function test_without_arco_queues_the_stock_connector_stays(): void
    {
        $_SERVER['ARCO'] = '1';
        $queue = $this->queue(new QueueTestApp(sys_get_temp_dir()));

        self::assertNotInstanceOf(ArcoSqsQueue::class, $queue);
    }

    public function test_the_session_token_reaches_the_sqs_client(): void
    {
        $_SERVER += QueueTestApp::env();
        $_SERVER['AWS_ACCESS_KEY_ID'] = 'AKIATEST';
        $_SERVER['AWS_SESSION_TOKEN'] = 'session';
        $test = new QueueTestApp(sys_get_temp_dir());

        $queue = $this->queue($test);

        self::assertSame('session', $test->app->make('config')->get('queue.connections.sqs.token'));
        self::assertSame('session', $queue->getSqs()->getCredentials()->wait()->getSecurityToken());
    }
}

enum QueueName: string
{
    case Emails = 'emails';
}
