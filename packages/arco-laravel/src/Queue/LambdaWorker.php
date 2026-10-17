<?php declare(strict_types=1);

namespace Multiapi\Arco\Laravel\Queue;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Foundation\Application;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Facade;
use Multiapi\Arco\Laravel\Env;
use Throwable;

/**
 * Runs the jobs of one Lambda SQS event with Laravel's own worker and answers which messages must
 * come back (ReportBatchItemFailures). Lambda deletes every message that is not reported.
 *
 * A message is reported when its job was released (it comes back after its backoff) or did not end
 * deleted; a job out of tries fails into the app's failed-jobs store and is not reported. Each job
 * gets the time left before the Lambda deadline minus a safety margin, so a slow job times out like
 * under `queue:work` instead of the whole batch being killed.
 */
final class LambdaWorker
{
    /** Seconds kept free before the Lambda deadline to answer for the batch. */
    public const MARGIN_SECONDS = 3;
    private const DEFAULT_TRIES = 3;
    /** Seconds a job waits before it comes back while the app is down for maintenance. */
    private const MAINTENANCE_DELAY = 60;

    /** @var \Closure(array<string, mixed>): void */
    private readonly \Closure $log;
    private readonly TimedWorker $worker;
    private readonly ExceptionHandler $exceptions;
    private readonly int $tries;
    private readonly ?int $maxReceives;
    private readonly ?string $workerQueue;
    /** @var array<string, string> */
    private readonly array $queues;

    /** @param (\Closure(array<string, mixed>): void)|null $log receives one JSON log line per call */
    public function __construct(private readonly Application $app, ?\Closure $log = null)
    {
        $this->log = $log ?? self::stderr(...);
        $manager = $app->make('queue');
        $events = $app->make('events');
        $exceptions = $app->make(ExceptionHandler::class);
        if (! $manager instanceof QueueManager || ! $events instanceof Dispatcher) {
            throw new \LogicException('The queue worker needs Laravel\'s queue manager, events and exception handler.');
        }
        $this->exceptions = $exceptions;
        $tries = Env::get('ARCO_QUEUE_TRIES');
        $this->tries = $tries !== null && ctype_digit($tries) ? (int) $tries : self::DEFAULT_TRIES;
        $maxReceives = Env::get('ARCO_QUEUE_MAX_RECEIVES');
        $this->maxReceives = $maxReceives !== null && ctype_digit($maxReceives) && (int) $maxReceives > 0 ? (int) $maxReceives : null;
        $this->workerQueue = Env::get('ARCO_WORKER_QUEUE');
        $this->queues = ArcoSqsQueue::queuesFromEnv(Env::get('ARCO_QUEUES'));
        $this->worker = new TimedWorker($manager, $events, $exceptions, $this->isDown(...));
        if ($app->bound('cache')) {
            $cache = $app->make('cache');
            $store = is_object($cache) && method_exists($cache, 'driver') ? $cache->driver() : null;
            if ($store instanceof Cache) {
                $this->worker->setCache($store);
            }
        }
        // What `queue:work` does: a job that fails for good goes to the app's failed-jobs store.
        $events->listen(JobFailed::class, function (JobFailed $event): void {
            if (! $event->job instanceof ArcoSqsJob) {
                return;
            }
            $failer = $this->app->make('queue.failer');
            if ($failer instanceof FailedJobProviderInterface) {
                $failer->log($event->connectionName, $event->job->getQueue(), $event->job->getRawBody(), $event->exception);
            }
        });
    }

    /** @param array<string, mixed> $line */
    public static function stderr(array $line): void
    {
        file_put_contents('php://stderr', json_encode($line, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
    }

    /** Whether a job of the last invocation hit its time limit: the process should then restart. */
    public function timedOut(): bool
    {
        return $this->worker->timedOut();
    }

    /**
     * @param array<mixed> $event a Lambda SQS event ({"Records": [...]})
     * @param int $remainingMs time left before the invocation's deadline
     * @param (\Closure(array{batchItemFailures: list<array{itemIdentifier: string}>}): void)|null $backstop
     *        called one second before the deadline if the batch is still running (a job that swallowed
     *        its timeout), with every record not known to have succeeded; it must answer and end the process
     * @return array{batchItemFailures: list<array{itemIdentifier: string}>}
     */
    public function handle(array $event, int $remainingMs, ?\Closure $backstop = null): array
    {
        $records = $event['Records'] ?? null;
        if (! is_array($records)) {
            throw new \InvalidArgumentException('The event is not an SQS event: it has no Records.');
        }
        $started = hrtime(true);
        $startedAt = microtime(true);
        [$connection, $queue] = $this->connection();
        $ids = [];
        foreach ($records as $record) {
            if (is_array($record) && is_string($record['messageId'] ?? null)) {
                $ids[] = $record['messageId'];
            }
        }
        /** @var array<string, bool> $outcome messageId => reported */
        $outcome = [];
        $this->worker->startInvocation($startedAt + ($remainingMs - 1000) / 1000, $backstop === null ? null : static function () use ($backstop, $ids, &$outcome): void {
            $backstop(['batchItemFailures' => array_map(
                static fn (string $id) => ['itemIdentifier' => $id],
                array_values(array_filter($ids, static fn (string $id) => $outcome[$id] ?? true)),
            )]);
        });
        try {
            foreach ($records as $record) {
                if (! is_array($record)) {
                    continue;
                }
                $leftMs = $remainingMs - intdiv(hrtime(true) - $started, 1_000_000);
                $reported = $leftMs < (self::MARGIN_SECONDS + 1) * 1000
                    ? $this->skip($record)
                    : $this->processRecord($record, $connection, $queue, max(1, (int) floor($leftMs / 1000) - self::MARGIN_SECONDS));
                if (is_string($record['messageId'] ?? null)) {
                    $outcome[$record['messageId']] = $reported;
                }
            }
        } finally {
            $this->worker->endInvocation();
        }
        $failures = [];
        foreach ($ids as $id) {
            if ($outcome[$id] ?? true) {
                $failures[] = ['itemIdentifier' => $id];
            }
        }
        return ['batchItemFailures' => $failures];
    }

    /**
     * Too little time is left to run the job safely: it comes back untouched.
     *
     * @param array<mixed> $record
     */
    private function skip(array $record): bool
    {
        $this->logJob($record, null, 0, 'skipped');
        return true;
    }

    /**
     * @param array<mixed> $record
     * @return bool whether the message must be reported (come back)
     */
    private function processRecord(array $record, string $connection, ArcoSqsQueue $queue, int $timeout): bool
    {
        $started = hrtime(true);
        $job = null;
        try {
            $this->resetScope();
            $job = ArcoSqsJob::fromRecord($this->app, $queue->getSqs(), $record, $connection, $this->queueUrl($record, $queue), $queue->overflowOptions(), $this->log);
            if ($this->isDown()) {
                [$status, $reported] = $this->whileDown($job, $record);
            } else {
                try {
                    $this->worker->processWithin($connection, $job, $this->options($timeout), $timeout);
                } catch (Throwable $e) {
                    $this->exceptions->report($e);
                }
                $this->failOnLastDelivery($job);
                $status = match (true) {
                    $job->isDeleted() => $job->hasFailed() ? 'failed' : 'ok',
                    $job->isReleased() => 'released',
                    default => 'failed',
                };
                $reported = ! $job->isDeleted();
            }
        } catch (Throwable $e) {
            $this->exceptions->report($e);
            $status = 'failed';
            $reported = true;
        }
        $this->logJob($record, $job, intdiv(hrtime(true) - $started, 1_000_000), $status);
        return $reported;
    }

    /** @param array<mixed> $record */
    private function logJob(array $record, ?ArcoSqsJob $job, int $ms, string $status): void
    {
        ($this->log)([
            'arco' => 'job',
            'queue' => $this->queueName($record),
            'job' => self::jobName($job),
            'messageId' => is_string($record['messageId'] ?? null) ? $record['messageId'] : null,
            'ms' => $ms,
            'status' => $status,
        ]);
    }

    /**
     * Whether this delivery is the last one before SQS moves the message to the dead-letter queue
     * (ARCO_QUEUE_MAX_RECEIVES, its maxReceiveCount; without it there is no such guard).
     */
    private function lastDelivery(ArcoSqsJob $job): bool
    {
        return $this->maxReceives !== null && $job->attempts() >= $this->maxReceives - 1;
    }

    /**
     * A job that would come back once more would land in the DLQ, where Laravel never sees it (a job
     * with more $tries than the queue, retryUntil, or middleware that keeps releasing): fail it now,
     * through Laravel's own path (failed(), the failed-jobs store), so it is never lost silently.
     */
    private function failOnLastDelivery(ArcoSqsJob $job): void
    {
        if ($job->isDeleted() || $job->hasFailed() || ! $this->lastDelivery($job)) {
            return;
        }
        try {
            $job->fail(MaxAttemptsExceededException::forJob($job));
        } catch (Throwable $e) {
            $this->exceptions->report($e);
        }
    }

    /**
     * The app is down: the job does not run, and its delivery must not count as an attempt.
     *
     * - Standard queue: every delivery is replaced by a fresh copy delayed by a minute (the receive
     *   count starts over) and the original is not reported, so Lambda deletes it.
     * - FIFO queue (no per-message delay): the job comes back a minute later (visibility); once the
     *   delivery count reaches the job's tries, or the last delivery before the DLQ, a fresh copy in the
     *   same message group replaces it.
     *
     * If the copy cannot be sent, the job comes back a minute later as it is.
     *
     * @param array<mixed> $record
     * @return array{0: string, 1: bool} status, reported
     */
    private function whileDown(ArcoSqsJob $job, array $record): array
    {
        $fifo = str_ends_with($job->getQueue(), '.fifo');
        if ($fifo && ! $this->outOfTries($job) && ! $this->lastDelivery($job)) {
            $job->release(self::MAINTENANCE_DELAY);
            return ['maintenance', true];
        }
        /** @var array{QueueUrl: string, MessageBody: string, DelaySeconds?: int, MessageAttributes?: array<string, array{DataType: string, StringValue?: string, BinaryValue?: string}>, MessageGroupId?: string, MessageDeduplicationId?: string} $message */
        $message = ['QueueUrl' => $job->getQueue(), 'MessageBody' => $job->getRawBody()];
        $messageAttributes = self::messageAttributes($record['messageAttributes'] ?? null);
        if ($messageAttributes !== []) {
            $message['MessageAttributes'] = $messageAttributes;
        }
        if ($fifo) {
            $attributes = is_array($record['attributes'] ?? null) ? $record['attributes'] : [];
            $message['MessageGroupId'] = is_string($attributes['MessageGroupId'] ?? null) ? $attributes['MessageGroupId'] : 'arco';
            $message['MessageDeduplicationId'] = bin2hex(random_bytes(16));
        } else {
            $message['DelaySeconds'] = self::MAINTENANCE_DELAY;
        }
        try {
            $job->getSqs()->sendMessage($message);
        } catch (Throwable $e) {
            ($this->log)(['arco' => 'job_requeue_failed', 'messageId' => $job->getJobId(), 'error' => $e->getMessage()]);
            $job->release(self::MAINTENANCE_DELAY);
            return ['maintenance', true];
        }
        return ['maintenance_requeued', false];
    }

    /** Whether Laravel would fail this job, unrun, at its next delivery (its own tries, else the queue's). */
    private function outOfTries(ArcoSqsJob $job): bool
    {
        $own = $job->maxTries();
        $tries = is_numeric($own) ? (int) $own : $this->tries;
        return $tries > 0 && $job->attempts() >= $tries;
    }

    /**
     * Lambda's message attributes ({stringValue, binaryValue (base64), dataType}) in SQS API form.
     *
     * @return array<string, array{DataType: string, StringValue?: string, BinaryValue?: string}>
     */
    private static function messageAttributes(mixed $attributes): array
    {
        $converted = [];
        foreach (is_array($attributes) ? $attributes : [] as $name => $attribute) {
            if (! is_array($attribute) || ! is_string($attribute['dataType'] ?? null)) {
                continue;
            }
            $value = ['DataType' => $attribute['dataType']];
            if (is_string($attribute['stringValue'] ?? null)) {
                $value['StringValue'] = $attribute['stringValue'];
            } elseif (is_string($attribute['binaryValue'] ?? null)) {
                $value['BinaryValue'] = (string) base64_decode($attribute['binaryValue'], true);
            } else {
                continue;
            }
            $converted[(string) $name] = $value;
        }
        return $converted;
    }

    private function options(int $timeout): WorkerOptions
    {
        // The backoff comes from the job ($backoff / backoff()); none means the message is visible again at once.
        return new WorkerOptions(name: 'arco', backoff: 0, timeout: $timeout, sleep: 0, maxTries: $this->tries);
    }

    /** What Laravel's worker does between jobs, so no state leaks from one job into the next. */
    private function resetScope(): void
    {
        if ($this->app->resolved('log')) {
            $log = $this->app->make('log');
            if (is_object($log) && method_exists($log, 'flushSharedContext')) {
                $log->flushSharedContext();
            }
            if (is_object($log) && method_exists($log, 'withoutContext')) {
                $log->withoutContext();
            }
        }
        if ($this->app->resolved('db')) {
            $db = $this->app->make('db');
            $connections = is_object($db) && method_exists($db, 'getConnections') ? $db->getConnections() : [];
            foreach (is_array($connections) ? $connections : [] as $connection) {
                if (is_object($connection) && method_exists($connection, 'resetTotalQueryDuration')) {
                    $connection->resetTotalQueryDuration();
                }
                if (is_object($connection) && method_exists($connection, 'allowQueryDurationHandlersToRunAgain')) {
                    $connection->allowQueryDurationHandlersToRunAgain();
                }
            }
        }
        $this->app->forgetScopedInstances();
        Facade::clearResolvedInstances();
        memory_reset_peak_usage();
    }

    private function isDown(): bool
    {
        return $this->app->bound(MaintenanceMode::class) && $this->app->isDownForMaintenance();
    }

    /**
     * The SQS connection to run jobs on: the default connection when it is SQS (QUEUE_CONNECTION=sqs,
     * which Arco sets), else the one named `sqs`.
     *
     * @return array{0: string, 1: ArcoSqsQueue}
     */
    private function connection(): array
    {
        $config = $this->app->make('config');
        $default = $config instanceof Config ? $config->get('queue.default') : null;
        $driver = $config instanceof Config && is_string($default) ? $config->get("queue.connections.$default.driver") : null;
        $name = is_string($default) && $driver === 'sqs' ? $default : 'sqs';
        $manager = $this->app->make('queue');
        $queue = $manager instanceof QueueManager ? $manager->connection($name) : null;
        if (! $queue instanceof ArcoSqsQueue) {
            throw new \RuntimeException("The queue connection [$name] is not Arco's SQS connection: ARCO and ARCO_QUEUES must be set.");
        }
        return [$name, $queue];
    }

    /** @param array<mixed> $record */
    private function queueUrl(array $record, ArcoSqsQueue $queue): string
    {
        if ($this->workerQueue !== null && isset($this->queues[$this->workerQueue])) {
            return $this->queues[$this->workerQueue];
        }
        $arn = explode(':', is_string($record['eventSourceARN'] ?? null) ? $record['eventSourceARN'] : '', 6);
        if (count($arn) === 6 && $arn[2] === 'sqs') {
            $domain = $arn[1] === 'aws-cn' ? 'amazonaws.com.cn' : 'amazonaws.com';
            return "https://sqs.$arn[3].$domain/$arn[4]/$arn[5]";
        }
        return $queue->getQueue(null);
    }

    /** @param array<mixed> $record */
    private function queueName(array $record): ?string
    {
        $arn = is_string($record['eventSourceARN'] ?? null) ? $record['eventSourceARN'] : null;
        return $this->workerQueue ?? ($arn === null ? null : substr($arn, (int) strrpos($arn, ':') + 1));
    }

    private static function jobName(?ArcoSqsJob $job): ?string
    {
        if ($job === null) {
            return null;
        }
        try {
            return $job->resolveName();
        } catch (Throwable) {
            return null;
        }
    }
}
