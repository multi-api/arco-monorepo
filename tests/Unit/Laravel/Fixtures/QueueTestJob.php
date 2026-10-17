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
            'stubborn' => self::sleepThroughTimeouts(20),
            'scoped' => self::$scoped[] = spl_object_id(Container::getInstance()->make('arco.test.scoped')),
            default => null,
        };
    }

    /** Swallows every exception, the timeout included, like a careless retry loop. */
    private static function sleepThroughTimeouts(int $seconds): void
    {
        for ($i = 0; $i < $seconds; $i++) {
            try {
                sleep(1);
            } catch (\Exception) {
            }
        }
    }

    public function failed(?\Throwable $e): void
    {
        self::$failed[] = $this->mode;
    }

    /** The SQS message body Laravel would send for this job. */
    public static function body(string $mode, ?int $timeout = null, ?int $maxTries = null): string
    {
        return (string) json_encode([
            'uuid' => "uuid-$mode",
            'displayName' => self::class,
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'maxTries' => $maxTries,
            'maxExceptions' => null,
            'failOnTimeout' => false,
            'backoff' => null,
            'timeout' => $timeout,
            'retryUntil' => null,
            'data' => ['commandName' => self::class, 'command' => serialize(new self($mode))],
        ]);
    }

    /**
     * @param array<string, string> $attributes extra record attributes (MessageGroupId, ...)
     * @param array<string, mixed> $messageAttributes
     * @return array<string, mixed> An SQS record as Lambda hands it over.
     */
    public static function record(string $mode, int $attempts = 1, ?string $id = null, ?int $timeout = null, ?int $maxTries = null, array $attributes = [], array $messageAttributes = []): array
    {
        return [
            'messageId' => $id ?? "m-$mode",
            'receiptHandle' => 'rh-' . ($id ?? $mode),
            'body' => self::body($mode, $timeout, $maxTries),
            'attributes' => ['ApproximateReceiveCount' => (string) $attempts, 'SentTimestamp' => '1700000000000', ...$attributes],
            'messageAttributes' => $messageAttributes,
            'md5OfBody' => '',
            'eventSource' => 'aws:sqs',
            'eventSourceARN' => 'arn:aws:sqs:us-east-1:123456789012:shop-production-emails',
            'awsRegion' => 'us-east-1',
        ];
    }
}
