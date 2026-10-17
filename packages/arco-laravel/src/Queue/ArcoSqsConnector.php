<?php declare(strict_types=1);

namespace Multiapi\Arco\Laravel\Queue;

use Illuminate\Queue\Connectors\SqsConnector;
use Illuminate\Queue\SqsQueue;

/**
 * Replaces Laravel's `sqs` connector when Arco deploys queues: Laravel builds the client (credentials,
 * session token, options) exactly as usual, and the queue maps logical names through ARCO_QUEUES.
 */
final class ArcoSqsConnector extends SqsConnector
{
    /** @param array<string, string> $queues logical name => queue URL */
    public function __construct(private readonly array $queues)
    {
    }

    /**
     * @param array<mixed> $config
     * @return ArcoSqsQueue
     */
    public function connect(array $config)
    {
        $stock = parent::connect($config);
        if (! $stock instanceof SqsQueue) {
            throw new \LogicException('Laravel\'s SQS connector did not build an SQS queue.');
        }
        $overflow = $config['overflow'] ?? [];

        return new ArcoSqsQueue(
            $stock->getSqs(),
            self::string($config['queue'] ?? null),
            self::string($config['prefix'] ?? null),
            self::string($config['suffix'] ?? null),
            (bool) ($config['after_commit'] ?? false),
            is_array($overflow) ? $overflow : [],
            $this->queues,
        );
    }

    private static function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
