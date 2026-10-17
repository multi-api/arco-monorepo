<?php declare(strict_types=1);

namespace Multiapi\Arco\Laravel\Queue;

use Aws\Sqs\SqsClient;
use Illuminate\Container\Container;
use Illuminate\Queue\Jobs\SqsJob;

/**
 * An SQS message handed over by Lambda's event source mapping. Lambda deletes the messages of a batch
 * that are not reported as failures, so delete() only marks the job; a released job is reported, and its
 * visibility is changed (best effort) so it comes back after its delay.
 */
final class ArcoSqsJob extends SqsJob
{
    /** SQS refuses a visibility timeout above 12 hours. */
    private const MAX_VISIBILITY = 43200;

    /** @var \Closure(array<string, mixed>): void */
    private readonly \Closure $log;

    /**
     * @param array<string, mixed> $job the message in SQS API form (MessageId, ReceiptHandle, Body, Attributes)
     * @param array<mixed> $overflow Laravel 13's overflow storage options
     * @param \Closure(array<string, mixed>): void $log
     */
    public function __construct(Container $container, SqsClient $sqs, array $job, string $connectionName, string $queue, array $overflow, \Closure $log)
    {
        parent::__construct($container, $sqs, $job, $connectionName, $queue, $overflow);
        $this->log = $log;
    }

    /**
     * Builds the job from a record of a Lambda SQS event.
     *
     * @param array<mixed> $record
     * @param array<mixed> $overflow
     * @param \Closure(array<string, mixed>): void $log
     */
    public static function fromRecord(Container $container, SqsClient $sqs, array $record, string $connectionName, string $queue, array $overflow, \Closure $log): self
    {
        foreach (['messageId', 'receiptHandle', 'body'] as $key) {
            if (! isset($record[$key]) || ! is_string($record[$key])) {
                throw new \InvalidArgumentException("The SQS record has no $key.");
            }
        }
        $attributes = is_array($record['attributes'] ?? null) ? $record['attributes'] : [];

        return new self($container, $sqs, [
            'MessageId' => $record['messageId'],
            'ReceiptHandle' => $record['receiptHandle'],
            'Body' => $record['body'],
            'Attributes' => ['ApproximateReceiveCount' => '1', ...$attributes],
            'MessageAttributes' => is_array($record['messageAttributes'] ?? null) ? $record['messageAttributes'] : [],
        ], $connectionName, $queue, $overflow, $log);
    }

    /** Marks the job deleted; Lambda deletes the message when the batch answer does not report it. */
    public function delete()
    {
        $this->deleted = true;
    }

    /**
     * Marks the job released (it is reported, so SQS hands it over again) and asks SQS to hide it for
     * $delay seconds. If that call fails the message comes back after the queue's visibility timeout.
     *
     * @param \DateTimeInterface|\DateInterval|int $delay
     */
    public function release($delay = 0)
    {
        $this->released = true;
        $seconds = max(0, min(self::MAX_VISIBILITY, $this->secondsUntil($delay)));
        try {
            $this->changeMessageVisibilityInSqs($seconds);
        } catch (\Throwable $e) {
            ($this->log)(['arco' => 'job_release_failed', 'messageId' => $this->getJobId(), 'delay' => $seconds, 'error' => $e->getMessage()]);
        }
    }
}
