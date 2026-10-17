<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Aws\Lambda\Exception\LambdaException;
use Aws\Lambda\LambdaClient;
use Multiapi\Arco\Arco;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\QueueConfig;
use Multiapi\Arco\Deploy\Domains\Aws;
use Closure;

/**
 * The queue workers: one <app>-<env>-queue-<name> classic Lambda function per queue, running the
 * console zip through the worker runtime (ARCO_WORKER_QUEUE), and the SQS event source mapping that
 * feeds it from the function's live alias. A worker has no async-invoke retry setting: SQS
 * redelivery is how a failed job comes back.
 */
final class WorkerFunctions
{
    public const EPHEMERAL_STORAGE_MB = ConsoleFunction::EPHEMERAL_STORAGE_MB;
    public const HANDLER = 'arco.worker';

    private readonly LambdaFunction $function;

    public function __construct(private readonly LambdaClient $lambda, Sleeper $sleeper, private readonly Queues $queues)
    {
        $this->function = new LambdaFunction($lambda, $sleeper, 'deploy.worker_missing', 'deploy.worker_update_failed');
    }

    public function function(): LambdaFunction
    {
        return $this->function;
    }

    /**
     * The worker's environment: the deploy's variables (with the console's ARCO_BINARY_* when it
     * downloads FrankenPHP) plus the queue the worker serves and the guards that depend on it.
     * ARCO_QUEUE_MAX_RECEIVES (the dead-letter queue's maxReceiveCount) is only known for queues
     * Arco created with a dead-letter queue.
     *
     * @param array<string, string> $variables
     * @return array<string, string>
     */
    public static function environment(QueueConfig $queue, array $variables): array
    {
        $environment = [...$variables, 'ARCO_WORKER_QUEUE' => $queue->name, 'ARCO_QUEUE_TRIES' => (string) $queue->tries];
        if ($queue->existing === null && $queue->dlq) {
            $environment['ARCO_QUEUE_MAX_RECEIVES'] = (string) ($queue->tries + ResourcesStack::EXTRA_RECEIVES);
        }
        return $environment;
    }

    /**
     * @param array<string, string> $variables
     * @return array<string, mixed>
     */
    public static function settings(AppConfig $config, QueueConfig $queue, string $roleArn, array $variables): array
    {
        $settings = [
            'Role' => $roleArn,
            'Description' => "Arco worker for queue $queue->name (Arco " . Arco::version() . ')',
            'MemorySize' => $queue->memory,
            'EphemeralStorage' => ['Size' => self::EPHEMERAL_STORAGE_MB],
            'Timeout' => $queue->timeout,
            'Environment' => ['Variables' => array_filter($variables, fn (string $value) => $value !== '')],
        ];
        if ($config->kmsKey !== null) {
            $settings['KMSKeyArn'] = $config->kmsKey;
        }
        return $settings;
    }

    /**
     * Creates or updates the queue's worker with the console zip and publishes a version.
     *
     * @param array<string, string> $variables deploy variables plus ARCO_BINARY_*
     * @param array<string, string> $tags
     * @return string the published version
     */
    public function deploy(AppConfig $config, QueueConfig $queue, string $bucket, string $key, ?string $versionId, string $roleArn, array $variables, array $tags, bool $roleJustCreated = false): string
    {
        $code = ['S3Bucket' => $bucket, 'S3Key' => $key];
        if ($versionId !== null) {
            $code['S3ObjectVersion'] = $versionId;
        }
        return $this->function->deploy(
            $config->workerFunctionName($queue->name),
            self::HANDLER,
            $code,
            self::settings($config, $queue, $roleArn, self::environment($queue, $variables)),
            $tags,
            $roleJustCreated,
        );
    }

    /**
     * Points the worker's live alias at $version, then creates or updates its event source mapping.
     * Before a mapping is created on a queue Arco does not own, another consumer is looked for.
     *
     * @param Closure(string, array<string, string|int>): void $onStep
     */
    public function activate(AppConfig $config, QueueConfig $queue, string $version, Closure $onStep): void
    {
        $name = $config->workerFunctionName($queue->name);
        $this->function->point($name, $version);
        $onStep('deploy.worker_ready', ['queue' => $queue->name, 'function' => $name, 'version' => $version]);
        $alias = $this->function->aliasArn($name) ?? throw DeployException::make('deploy.worker_missing', ['function' => $name]);

        $settings = [
            'BatchSize' => $queue->batchSize,
            'FunctionResponseTypes' => ['ReportBatchItemFailures'],
            'Enabled' => true,
        ];
        $existing = $this->mappings(['FunctionName' => $alias, 'EventSourceArn' => $queue->arn($config)]);
        if ($existing !== []) {
            // An empty ScalingConfig lifts a maximum concurrency removed from arco.php.
            $this->lambda->updateEventSourceMapping([
                'UUID' => $existing[0],
                ...$settings,
                'ScalingConfig' => $queue->concurrency === null ? [] : ['MaximumConcurrency' => $queue->concurrency],
            ]);
        } else {
            if ($queue->existing !== null) {
                $others = $this->otherConsumers($config, $queue, $name);
                if ($others !== []) {
                    $onStep('deploy.queue_other_consumer', ['queue' => $queue->name, 'functions' => implode(', ', $others)]);
                }
            }
            $this->lambda->createEventSourceMapping([
                'FunctionName' => $alias,
                'EventSourceArn' => $queue->arn($config),
                ...$settings,
                ...($queue->concurrency === null ? [] : ['ScalingConfig' => ['MaximumConcurrency' => $queue->concurrency]]),
            ]);
        }
        $onStep('deploy.worker_mapping', ['queue' => $queue->name, 'function' => $name, 'batch' => $queue->batchSize]);
    }

    /** @return list<string> names of the other functions consuming the queue */
    private function otherConsumers(AppConfig $config, QueueConfig $queue, string $worker): array
    {
        $others = [];
        foreach ($this->queues->consumers($queue->arn($config)) as $arn) {
            $function = explode(':', $arn)[6] ?? $arn;
            if ($function !== $worker) {
                $others[$function] = $function;
            }
        }
        return array_values($others);
    }

    /**
     * @param array<string, mixed> $filter
     * @return list<string> UUIDs of the event source mappings matching the filter
     */
    private function mappings(array $filter): array
    {
        $uuids = [];
        $marker = null;
        do {
            $page = Aws::call($this->lambda, 'ListEventSourceMappings', $filter + ($marker === null ? [] : ['Marker' => $marker]));
            foreach (Aws::maps($page['EventSourceMappings'] ?? []) as $mapping) {
                $uuid = Aws::string($mapping['UUID'] ?? null);
                if ($uuid !== null) {
                    $uuids[] = $uuid;
                }
            }
            $marker = Aws::string($page['NextMarker'] ?? null);
        } while ($marker !== null);
        return $uuids;
    }

    /** Deletes the event source mappings of the worker's live alias, then the function itself (a missing one is fine). */
    public function remove(AppConfig $config, string $queue): void
    {
        $name = $config->workerFunctionName($queue);
        try {
            $uuids = $this->mappings(['FunctionName' => $name . ':' . LambdaFunction::ALIAS]);
        } catch (LambdaException $e) {
            // No such function or alias: no mappings (a worker never deployed, or deleted halfway).
            if ($e->getAwsErrorCode() !== 'ResourceNotFoundException') {
                throw $e;
            }
            $uuids = [];
        }
        foreach ($uuids as $uuid) {
            try {
                $this->lambda->deleteEventSourceMapping(['UUID' => $uuid]);
            } catch (LambdaException $e) {
                if ($e->getAwsErrorCode() !== 'ResourceNotFoundException') {
                    throw $e;
                }
            }
        }
        $this->function->delete($name);
    }

    /**
     * The queue names of this environment's workers that exist in AWS: functions named like a worker
     * AND tagged with this app and environment (a similar name of another app is never touched).
     *
     * @param array<string, string> $tags
     * @return list<string>
     */
    public function existing(AppConfig $config, array $tags): array
    {
        $prefix = $config->functionName() . '-queue-';
        $queues = [];
        $marker = null;
        do {
            $page = Aws::call($this->lambda, 'ListFunctions', $marker === null ? [] : ['Marker' => $marker]);
            foreach (Aws::maps($page['Functions'] ?? []) as $function) {
                $name = Aws::string($function['FunctionName'] ?? null);
                if ($name !== null && str_starts_with($name, $prefix) && strlen($name) > strlen($prefix)) {
                    $queues[] = substr($name, strlen($prefix));
                }
            }
            $marker = Aws::string($page['NextMarker'] ?? null);
        } while ($marker !== null);

        return array_values(array_filter($queues, function (string $queue) use ($config, $tags): bool {
            $found = Aws::map(Aws::call($this->lambda, 'GetFunction', ['FunctionName' => $config->workerFunctionName($queue)])['Tags'] ?? null);
            foreach ($tags as $key => $value) {
                if (($found[$key] ?? null) !== $value) {
                    return false;
                }
            }
            return true;
        }));
    }
}
