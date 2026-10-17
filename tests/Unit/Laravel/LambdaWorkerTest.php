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

    protected function setUp(): void
    {
        $_SERVER += QueueTestApp::env();
        QueueTestJob::reset();
        $this->test = new QueueTestApp(sys_get_temp_dir());
        (new ArcoServiceProvider($this->test->app))->register();
    }

    protected function tearDown(): void
    {
        foreach (array_keys(QueueTestApp::env()) as $name) {
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
        $worker = new LambdaWorker($this->test->app, function (array $line): void {
            $this->logs[] = $line;
        });
        $result = $worker->handle(['Records' => $records], $remainingMs);
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

    public function test_a_job_past_the_deadline_times_out_and_the_rest_of_the_batch_still_runs(): void
    {
        $started = microtime(true);

        // 4.5 s left: the slow job gets max(1, 4 - 3) = 1 s.
        $reported = $this->handle([QueueTestJob::record('slow'), QueueTestJob::record('ok')], 4_500);

        self::assertSame(['m-slow'], $reported);
        self::assertSame(['slow', 'ok'], QueueTestJob::$handled);
        self::assertLessThan(3.5, microtime(true) - $started, 'the slow job was stopped at its timeout');
        self::assertSame(['released', 'ok'], array_column($this->logs, 'status'));
    }

    public function test_scoped_instances_are_reset_between_jobs(): void
    {
        $this->handle([QueueTestJob::record('scoped', id: 'a'), QueueTestJob::record('scoped', id: 'b')]);

        self::assertCount(2, QueueTestJob::$scoped);
        self::assertNotSame(QueueTestJob::$scoped[0], QueueTestJob::$scoped[1]);
    }

    public function test_in_maintenance_mode_jobs_are_reported_without_running(): void
    {
        $this->test->app->instance(MaintenanceMode::class, new class implements MaintenanceMode {
            public function activate(array $payload): void {}
            public function deactivate(): void {}
            public function active(): bool { return true; }
            public function data(): array { return []; }
        });

        self::assertSame(['m-ok'], $this->handle([QueueTestJob::record('ok')]));
        self::assertSame([], QueueTestJob::$handled);
        self::assertSame('maintenance', $this->logs[0]['status']);
    }

    public function test_a_broken_record_is_reported_and_the_batch_goes_on(): void
    {
        $broken = ['messageId' => 'm-broken', 'receiptHandle' => 'rh', 'attributes' => ['ApproximateReceiveCount' => '1'], 'eventSourceARN' => 'arn:aws:sqs:us-east-1:123456789012:shop-production-emails'];

        self::assertSame(['m-broken'], $this->handle([$broken, QueueTestJob::record('ok')]));
        self::assertSame(['ok'], QueueTestJob::$handled);
    }
}
