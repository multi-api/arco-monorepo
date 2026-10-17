<?php declare(strict_types=1);

namespace Multiapi\Arco\Laravel\Queue;

use Aws\Sqs\SqsClient;
use Illuminate\Queue\SqsQueue;

/**
 * Laravel's SQS queue, with the logical queue names Arco deploys (`emails`) mapped to their physical
 * queues (`https://sqs.<region>.amazonaws.com/<account>/<app>-<env>-emails`) through ARCO_QUEUES. A URL
 * passes through, and any other name keeps Laravel's prefix behaviour. Works the same on Laravel 11–13.
 */
class ArcoSqsQueue extends SqsQueue
{
    /**
     * @param array<string, string> $arcoQueues logical name => queue URL
     * @param array<mixed> $overflow Laravel 13's overflow storage options (ignored by older versions)
     */
    public function __construct(
        SqsClient $sqs,
        string $default,
        string $prefix,
        string $suffix,
        bool $dispatchAfterCommit,
        private readonly array $overflow,
        private readonly array $arcoQueues,
    ) {
        parent::__construct($sqs, $default, $prefix, $suffix, $dispatchAfterCommit, $overflow);
    }

    /** @return array<string, string> */
    public static function queuesFromEnv(?string $json): array
    {
        $decoded = $json === null ? null : json_decode($json, true);
        $queues = [];
        foreach (is_array($decoded) ? $decoded : [] as $name => $url) {
            if (is_string($url) && $url !== '') {
                $queues[(string) $name] = $url;
            }
        }
        return $queues;
    }

    /**
     * @param \UnitEnum|string|null $queue
     * @return string
     */
    public function getQueue($queue)
    {
        $name = match (true) {
            $queue instanceof \BackedEnum => (string) $queue->value,
            $queue instanceof \UnitEnum => $queue->name,
            default => $queue,
        };
        if ($name === null || $name === '') {
            $name = $this->default;
        }

        return parent::getQueue($this->arcoQueues[$name] ?? $name);
    }

    /**
     * Laravel 13 resolves names here too (Queue::forward, FIFO detection); older versions never call it.
     *
     * @param string $queue
     * @return string
     */
    protected function resolveQueue($queue)
    {
        $resolved = parent::resolveQueue($queue);
        return $this->arcoQueues[$resolved] ?? $resolved;
    }

    /** @return array<mixed> */
    public function overflowOptions(): array
    {
        return $this->overflow;
    }
}
