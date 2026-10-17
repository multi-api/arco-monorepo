<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Laravel\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/** A queued job whose behaviour is chosen by $mode; it records what happened in static arrays. */
final class QueueTestJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    /** @var list<string> */
    public static array $handled = [];
    /** @var list<string> */
    public static array $failed = [];
    /** @var list<int> */
    public static array $scoped = [];

    public function __construct(public string $mode)
    {
    }

    public static function reset(): void
    {
        self::$handled = [];
        self::$failed = [];
        self::$scoped = [];
    }

    public function handle(): void
    {
        self::$handled[] = $this->mode;
        match ($this->mode) {
            'throw' => throw new \RuntimeException('boom'),
            'release' => $this->release(30),
            'slow' => sleep(5),
            'scoped' => self::$scoped[] = spl_object_id(Container::getInstance()->make('arco.test.scoped')),
            default => null,
        };
    }

    public function failed(?\Throwable $e): void
    {
        self::$failed[] = $this->mode;
    }

    /** The SQS message body Laravel would send for this job. */
    public static function body(string $mode): string
    {
        return (string) json_encode([
            'uuid' => "uuid-$mode",
            'displayName' => self::class,
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'maxTries' => null,
            'maxExceptions' => null,
            'failOnTimeout' => false,
            'backoff' => null,
            'timeout' => null,
            'retryUntil' => null,
            'data' => ['commandName' => self::class, 'command' => serialize(new self($mode))],
        ]);
    }

    /** @return array<string, mixed> An SQS record as Lambda hands it over. */
    public static function record(string $mode, int $attempts = 1, ?string $id = null): array
    {
        return [
            'messageId' => $id ?? "m-$mode",
            'receiptHandle' => 'rh-' . ($id ?? $mode),
            'body' => self::body($mode),
            'attributes' => ['ApproximateReceiveCount' => (string) $attempts, 'SentTimestamp' => '1700000000000'],
            'messageAttributes' => [],
            'md5OfBody' => '',
            'eventSource' => 'aws:sqs',
            'eventSourceARN' => 'arn:aws:sqs:us-east-1:123456789012:shop-production-emails',
            'awsRegion' => 'us-east-1',
        ];
    }
}
