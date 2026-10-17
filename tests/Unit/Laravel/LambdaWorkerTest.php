<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Laravel;

use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Facade;
use Multiapi\Arco\Laravel\ArcoServiceProvider;
use Multiapi\Arco\Laravel\Queue\LambdaWorker;
use Multiapi\Arco\Tests\Unit\Laravel\Fixtures\QueueTestApp;
use Multiapi\Arco\Tests\Unit\Laravel\Fixtures\QueueTestJob;
use PHPUnit\Framework\TestCase;

final class LambdaWorkerTest extends TestCase
{
    private QueueTestApp $test;
    /** @var list<array<string, mixed>> */
    private array $logs = [];
    private ?LambdaWorker $worker = null;

    protected function setUp(): void
    {
        $_SERVER += QueueTestApp::env();
        QueueTestJob::reset();
        $this->test = new QueueTestApp(sys_get_temp_dir());
        (new ArcoServiceProvider($this->test->app))->register();
    }

    protected function tearDown(): void
    {
        foreach ([...array_keys(QueueTestApp::env()), 'ARCO_QUEUE_MAX_RECEIVES'] as $name) {
            unset($_SERVER[$name]);
        }
        TrustProxies::flushState();
        Container::setInstance(null);
        Facade::clearResolvedInstances();
    }

    /**
     * @param list<array<string, mixed>> $records
     * @return list<string> The reported message ids.
     */
    private function handle(array $records, int $remainingMs = 60_000): array
    {
        $this->worker = new LambdaWorker($this->test->app, function (array $line): void {
            $this->logs[] = $line;
        });
        $result = $this->worker->handle(['Records' => $records], $remainingMs);
        self::assertSame(['batchItemFailures'], array_keys($result));
        return array_map(fn (array $item) => $item['itemIdentifier'], $result['batchItemFailures']);
    }

    public function test_a_successful_job_is_not_reported_and_not_deleted_through_sqs(): void
    {
        self::assertSame([], $this->handle([QueueTestJob::record('ok')]));

        self::assertSame(['ok'], QueueTestJob::$handled);
        self::assertSame([], $this->test->calls, 'Lambda deletes successful messages itself');
        self::assertSame('job', $this->logs[0]['arco']);
        self::assertSame('emails', $this->logs[0]['queue']);
        self::assertSame(QueueTestJob::class, $this->logs[0]['job']);
        self::assertSame('ok', $this->logs[0]['status']);
        self::assertIsInt($this->logs[0]['ms']);
    }

    public function test_a_job_that_throws_with_tries_left_comes_back_and_is_not_deleted(): void
    {
        self::assertSame(['m-throw'], $this->handle([QueueTestJob::record('throw', attempts: 1)]));

        self::assertSame([], QueueTestJob::$failed);
        self::assertSame([], $this->test->callsTo('DeleteMessage'));
        $visibility = $this->test->callsTo('ChangeMessageVisibility');
        self::assertCount(1, $visibility);
        self::assertSame(0, $visibility[0]['VisibilityTimeout']);
        self::assertSame('released', $this->logs[0]['status']);
        self::assertSame('boom', $this->test->exceptions->reported[0]->getMessage());
    }

    public function test_a_released_job_comes_back_after_its_delay(): void
    {
        self::assertSame(['m-release'], $this->handle([QueueTestJob::record('release')]));

        $visibility = $this->test->callsTo('ChangeMessageVisibility');
        self::assertCount(1, $visibility);
        self::assertSame(['QueueUrl' => QueueTestApp::EMAILS_URL, 'ReceiptHandle' => 'rh-release', 'VisibilityTimeout' => 30], array_intersect_key($visibility[0], array_flip(['QueueUrl', 'ReceiptHandle', 'VisibilityTimeout'])));
        self::assertSame([], $this->test->callsTo('DeleteMessage'));
    }

    public function test_a_failed_visibility_change_is_logged_and_the_job_is_still_reported(): void
    {
        $this->test->sqsFails = true;

        self::assertSame(['m-release'], $this->handle([QueueTestJob::record('release')]));

        self::assertSame(['job_release_failed'], array_values(array_diff(array_column($this->logs, 'arco'), ['job'])));
    }

    public function test_a_job_out_of_tries_fails_into_the_failer_and_is_not_reported(): void
    {
        self::assertSame([], $this->handle([QueueTestJob::record('throw', attempts: 3)]));

        self::assertSame(['throw'], QueueTestJob::$failed);
        self::assertCount(1, $this->test->failer->logged);
        self::assertSame(['connection' => 'sqs', 'queue' => QueueTestApp::EMAILS_URL], array_intersect_key($this->test->failer->logged[0], ['connection' => 1, 'queue' => 1]));
        self::assertSame('failed', $this->logs[0]['status']);
    }

    public function test_a_job_past_its_time_limit_times_out_and_the_rest_of_the_batch_still_runs(): void
    {
        $started = microtime(true);

        // The job's own 1 s timeout is shorter than the time left minus the margin.
        $reported = $this->handle([QueueTestJob::record('slow', timeout: 1), QueueTestJob::record('ok')]);

        self::assertSame(['m-slow'], $reported);
        self::assertSame(['slow', 'ok'], QueueTestJob::$handled);
        self::assertLessThan(3.5, microtime(true) - $started, 'the slow job was stopped at its timeout');
        self::assertSame(['released', 'ok'], array_column($this->logs, 'status'));
        self::assertTrue($this->worker?->timedOut(), 'the process restarts after an invocation with a timeout');
    }

    public function test_the_time_limit_is_the_time_left_minus_the_margin(): void
    {
        $started = microtime(true);

        // 4.5 s left: max(1, 4 - 3) = 1 s.
        self::assertSame(['m-slow'], $this->handle([QueueTestJob::record('slow')], 4_500));

        self::assertLessThan(3.5, microtime(true) - $started);
    }

    public function test_records_are_not_started_without_the_margin_plus_one_second_left(): void
    {
        self::assertSame(['m-ok', 'a'], $this->handle([QueueTestJob::record('ok'), QueueTestJob::record('ok', id: 'a')], 2_000));

        self::assertSame([], QueueTestJob::$handled);
        self::assertSame(['skipped', 'skipped'], array_column($this->logs, 'status'));
        self::assertFalse($this->worker?->timedOut());
    }

    public function test_the_last_delivery_before_the_dlq_fails_into_the_failer_instead_of_coming_back(): void
    {
        $_SERVER['ARCO_QUEUE_MAX_RECEIVES'] = '5';

        // The job allows 10 tries, more than the queue's 3: Laravel would release it into the DLQ.
        self::assertSame([], $this->handle([QueueTestJob::record('throw', attempts: 4, maxTries: 10)]));

        self::assertSame(['throw'], QueueTestJob::$failed);
        self::assertCount(1, $this->test->failer->logged);
        self::assertSame('failed', $this->logs[0]['status']);
    }

    public function test_earlier_deliveries_with_tries_left_are_released_as_usual(): void
    {
        $_SERVER['ARCO_QUEUE_MAX_RECEIVES'] = '5';

        self::assertSame(['m-throw'], $this->handle([QueueTestJob::record('throw', attempts: 2, maxTries: 10)]));

        self::assertSame([], QueueTestJob::$failed);
        self::assertSame([], $this->test->failer->logged);
    }

    public function test_without_a_dlq_limit_the_job_is_released_as_usual(): void
    {
        self::assertSame(['m-throw'], $this->handle([QueueTestJob::record('throw', attempts: 4, maxTries: 10)]));

        self::assertSame([], QueueTestJob::$failed);
    }

    public function test_scoped_instances_are_reset_between_jobs(): void
    {
        $this->handle([QueueTestJob::record('scoped', id: 'a'), QueueTestJob::record('scoped', id: 'b')]);

        self::assertCount(2, QueueTestJob::$scoped);
        self::assertNotSame(QueueTestJob::$scoped[0], QueueTestJob::$scoped[1]);
    }

    private function maintenance(): void
    {
        $this->test->app->instance(MaintenanceMode::class, new class implements MaintenanceMode {
            public function activate(array $payload): void {}
            public function deactivate(): void {}
            public function active(): bool { return true; }
            public function data(): array { return []; }
        });
    }

    public function test_in_maintenance_mode_jobs_come_back_a_minute_later_without_running(): void
    {
        $this->maintenance();
        $_SERVER['ARCO_QUEUE_MAX_RECEIVES'] = '5';

        self::assertSame(['m-ok'], $this->handle([QueueTestJob::record('ok', attempts: 3)]));

        self::assertSame([], QueueTestJob::$handled);
        self::assertSame(60, $this->test->callsTo('ChangeMessageVisibility')[0]['VisibilityTimeout'] ?? null);
        self::assertSame([], $this->test->callsTo('SendMessage'));
        self::assertSame('maintenance', $this->logs[0]['status']);
    }

    public function test_in_maintenance_mode_the_last_delivery_is_sent_again_as_a_fresh_message(): void
    {
        $this->maintenance();
        $_SERVER['ARCO_QUEUE_MAX_RECEIVES'] = '5';
        $attributes = ['tenant' => ['stringValue' => 'acme', 'stringListValues' => [], 'binaryListValues' => [], 'dataType' => 'String']];

        self::assertSame([], $this->handle([QueueTestJob::record('ok', attempts: 4, messageAttributes: $attributes)]), 'the original is deleted by Lambda');

        self::assertSame([], QueueTestJob::$handled);
        $sent = $this->test->callsTo('SendMessage');
        self::assertCount(1, $sent);
        self::assertSame(QueueTestApp::EMAILS_URL, $sent[0]['QueueUrl']);
        self::assertSame(QueueTestJob::body('ok'), $sent[0]['MessageBody']);
        self::assertSame(['tenant' => ['DataType' => 'String', 'StringValue' => 'acme']], $sent[0]['MessageAttributes']);
        self::assertArrayNotHasKey('MessageGroupId', $sent[0]);
        self::assertSame('maintenance_requeued', $this->logs[0]['status']);
    }

    public function test_in_maintenance_mode_a_fifo_copy_keeps_its_group_with_a_new_deduplication_id(): void
    {
        $this->maintenance();
        $_SERVER['ARCO_QUEUE_MAX_RECEIVES'] = '5';
        $_SERVER['ARCO_QUEUES'] = (string) json_encode(['emails' => QueueTestApp::EMAILS_URL . '.fifo'], JSON_UNESCAPED_SLASHES);

        $this->handle([QueueTestJob::record('ok', attempts: 4, attributes: ['MessageGroupId' => 'g1', 'MessageDeduplicationId' => 'd1'])]);

        $sent = $this->test->callsTo('SendMessage')[0];
        self::assertSame(QueueTestApp::EMAILS_URL . '.fifo', $sent['QueueUrl']);
        self::assertSame('g1', $sent['MessageGroupId']);
        self::assertIsString($sent['MessageDeduplicationId']);
        self::assertNotSame('d1', $sent['MessageDeduplicationId']);
    }

    public function test_in_maintenance_mode_a_failed_copy_is_reported_and_logged(): void
    {
        $this->maintenance();
        $_SERVER['ARCO_QUEUE_MAX_RECEIVES'] = '5';
        $this->test->sqsFails = true;

        self::assertSame(['m-ok'], $this->handle([QueueTestJob::record('ok', attempts: 4)]));

        self::assertContains('job_requeue_failed', array_column($this->logs, 'arco'));
    }

    public function test_a_broken_record_is_reported_and_the_batch_goes_on(): void
    {
        $broken = ['messageId' => 'm-broken', 'receiptHandle' => 'rh', 'attributes' => ['ApproximateReceiveCount' => '1'], 'eventSourceARN' => 'arn:aws:sqs:us-east-1:123456789012:shop-production-emails'];

        self::assertSame(['m-broken'], $this->handle([$broken, QueueTestJob::record('ok')]));
        self::assertSame(['ok'], QueueTestJob::$handled);
    }
}
