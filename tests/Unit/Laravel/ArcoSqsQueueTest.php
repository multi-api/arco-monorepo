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
