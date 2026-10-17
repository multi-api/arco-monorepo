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

    /** @var \Closure(array<string, mixed>): void */
    private readonly \Closure $log;
    private readonly TimedWorker $worker;
    private readonly ExceptionHandler $exceptions;

    /** @param (\Closure(array<string, mixed>): void)|null $log receives one JSON log line per call */
    public function __construct(private readonly Application $app, ?\Closure $log = null)
    {
        $this->log = $log ?? static function (array $line): void {
            file_put_contents('php://stderr', json_encode($line, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
        };
        $manager = $app->make('queue');
        $events = $app->make('events');
        $exceptions = $app->make(ExceptionHandler::class);
        if (! $manager instanceof QueueManager || ! $events instanceof Dispatcher) {
            throw new \LogicException('The queue worker needs Laravel\'s queue manager, events and exception handler.');
        }
        $this->exceptions = $exceptions;
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

    /**
     * @param array<mixed> $event a Lambda SQS event ({"Records": [...]})
     * @param int $remainingMs time left before the invocation's deadline
     * @return array{batchItemFailures: list<array{itemIdentifier: string}>}
     */
    public function handle(array $event, int $remainingMs): array
    {
        $records = $event['Records'] ?? null;
        if (! is_array($records)) {
            throw new \InvalidArgumentException('The event is not an SQS event: it has no Records.');
        }
        $started = hrtime(true);
        [$connection, $queue] = $this->connection();
        $failures = [];
        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }
            $leftMs = $remainingMs - intdiv(hrtime(true) - $started, 1_000_000);
            $timeout = max(1, (int) floor($leftMs / 1000) - self::MARGIN_SECONDS);
            if ($this->processRecord($record, $connection, $queue, $timeout) && is_string($record['messageId'] ?? null)) {
                $failures[] = ['itemIdentifier' => $record['messageId']];
            }
        }
        return ['batchItemFailures' => $failures];
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
                $status = 'maintenance';
            } else {
                try {
                    $this->worker->processWithin($connection, $job, $this->options($timeout), $timeout);
                } catch (Throwable $e) {
                    $this->exceptions->report($e);
                }
                $status = match (true) {
                    $job->isDeleted() => $job->hasFailed() ? 'failed' : 'ok',
                    $job->isReleased() => 'released',
                    default => 'failed',
                };
            }
            $reported = $status === 'maintenance' || ! $job->isDeleted();
        } catch (Throwable $e) {
            $this->exceptions->report($e);
            $status = 'failed';
            $reported = true;
        }
        ($this->log)([
            'arco' => 'job',
            'queue' => $this->queueName($record),
            'job' => self::jobName($job),
            'messageId' => is_string($record['messageId'] ?? null) ? $record['messageId'] : null,
            'ms' => intdiv(hrtime(true) - $started, 1_000_000),
            'status' => $status,
        ]);
        return $reported;
    }

    private function options(int $timeout): WorkerOptions
    {
        $tries = Env::get('ARCO_QUEUE_TRIES');
        $maxTries = $tries !== null && ctype_digit($tries) ? (int) $tries : self::DEFAULT_TRIES;
        // The backoff comes from the job ($backoff / backoff()); none means the message is visible again at once.
        return new WorkerOptions(name: 'arco', backoff: 0, timeout: $timeout, sleep: 0, maxTries: $maxTries);
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
        $worker = Env::get('ARCO_WORKER_QUEUE');
        $queues = ArcoSqsQueue::queuesFromEnv(Env::get('ARCO_QUEUES'));
        if ($worker !== null && isset($queues[$worker])) {
            return $queues[$worker];
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
        return Env::get('ARCO_WORKER_QUEUE') ?? ($arn === null ? null : substr($arn, (int) strrpos($arn, ':') + 1));
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
