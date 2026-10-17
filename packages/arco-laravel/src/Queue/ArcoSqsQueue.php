<?php declare(strict_types=1);

namespace Multiapi\Arco\Laravel\Queue;

use Aws\Sqs\SqsClient;
use Illuminate\Queue\SqsQueue;

/**
 * Laravel's SQS queue, with the logical queue names Arco deploys (`emails`) mapped to their physical
 * queues (`https://sqs.<region>.amazonaws.com/<account>/<app>-<env>-emails`) through ARCO_QUEUES. A URL
 * passes through, and any other name keeps Laravel's prefix behaviour. Works the same on Laravel 11–13.
 *
 * FIFO queues: every send gets a message group and a deduplication id, and no per-message delay,
 * whatever the Laravel version does (Laravel 11 sets none; Laravel 12 only for a name that itself
 * ends in `.fifo`; `queue:retry` pushes raw payloads without options on every version).
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

    /**
     * Laravel 12 and 13 decide FIFO options from the queue name they are given: give them the queue's URL.
     *
     * @param mixed $job
     * @param \UnitEnum|string|null $queue
     * @param string $payload
     * @param \DateTimeInterface|\DateInterval|int|null $delay
     * @return array{DelaySeconds?: int, MessageGroupId?: string, MessageDeduplicationId?: string}
     */
    public function getQueueableOptions($job, $queue, $payload, $delay = null): array
    {
        return parent::getQueueableOptions($job, $this->getQueue($queue), $payload, $delay);
    }

    /**
     * @param string $payload
     * @param \UnitEnum|string|null $queue
     * @param array<string, mixed> $options
     * @return mixed
     */
    public function pushRaw($payload, $queue = null, array $options = [])
    {
        if (str_ends_with($this->getQueue($queue), '.fifo')) {
            unset($options['DelaySeconds']);
            if (! isset($options['MessageGroupId'])) {
                $options['MessageGroupId'] = $this->logicalName($queue);
                $options['MessageDeduplicationId'] ??= self::payloadUuid($payload) ?? bin2hex(random_bytes(16));
            }
        }
        return parent::pushRaw($payload, $queue, $options);
    }

    /**
     * Laravel 11 sends DelaySeconds itself in later(), which a FIFO queue refuses: there, a delayed job
     * for a FIFO queue is pushed without its delay (FIFO queues take no per-message delay).
     *
     * @param \DateTimeInterface|\DateInterval|int $delay
     * @param string $job
     * @param mixed $data
     * @param \UnitEnum|string|null $queue
     * @return mixed
     */
    public function later($delay, $job, $data = '', $queue = null)
    {
        if (! $this->laravelSetsQueueableOptions() && str_ends_with($this->getQueue($queue), '.fifo')) {
            return $this->push($job, $data, $queue);
        }
        return parent::later($delay, $job, $data, $queue);
    }

    /** Whether the installed Laravel builds FIFO options in push() and later() (12 and up). */
    protected function laravelSetsQueueableOptions(): bool
    {
        // PHPStan sees Laravel 13; the app may run Laravel 11, which has no getQueueableOptions().
        return method_exists(SqsQueue::class, 'getQueueableOptions'); // @phpstan-ignore function.alreadyNarrowedType
    }

    /**
     * The queue's name as the message group: the logical name, or the queue's own name for a URL
     * (a message group id is at most 128 characters).
     *
     * @param \UnitEnum|string|null $queue
     */
    private function logicalName($queue): string
    {
        $name = match (true) {
            $queue instanceof \BackedEnum => (string) $queue->value,
            $queue instanceof \UnitEnum => $queue->name,
            default => (string) $queue,
        };
        $name = $name === '' ? $this->default : $name;
        return filter_var($name, FILTER_VALIDATE_URL) === false ? $name : basename($name);
    }

    private static function payloadUuid(string $payload): ?string
    {
        $decoded = json_decode($payload, true);
        $uuid = is_array($decoded) ? ($decoded['uuid'] ?? null) : null;
        return is_string($uuid) && $uuid !== '' ? $uuid : null;
    }

    /** @return array<mixed> */
    public function overflowOptions(): array
    {
        return $this->overflow;
    }
}
