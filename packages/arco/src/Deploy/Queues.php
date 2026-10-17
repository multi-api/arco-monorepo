<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Aws\Exception\AwsException;
use Aws\Lambda\LambdaClient;
use Aws\Sqs\SqsClient;
use Multiapi\Arco\Deploy\Domains\Aws;

/** SQS lookups around the queues of the resources stack. */
final class Queues
{
    private const MISSING = ['AWS.SimpleQueueService.NonExistentQueue', 'QueueDoesNotExist'];

    public function __construct(private readonly SqsClient $sqs, private readonly LambdaClient $lambda)
    {
    }

    /** The URL of the queue with this name in the account and region, or null when there is none. */
    public function url(string $name): ?string
    {
        try {
            return Aws::string(Aws::call($this->sqs, 'GetQueueUrl', ['QueueName' => $name])['QueueUrl'] ?? null);
        } catch (AwsException $e) {
            if (in_array($e->getAwsErrorCode(), self::MISSING, true)) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * The approximate number of visible messages, or null when it cannot be read (the queue is gone,
     * or the profile may not read it): only ever shown in warnings.
     */
    public function messageCount(string $url): ?int
    {
        try {
            $attributes = Aws::map(Aws::call($this->sqs, 'GetQueueAttributes', ['QueueUrl' => $url, 'AttributeNames' => ['ApproximateNumberOfMessages']])['Attributes'] ?? null);
        } catch (AwsException) {
            return null;
        }
        $count = $attributes['ApproximateNumberOfMessages'] ?? null;
        return is_numeric($count) ? (int) $count : null;
    }

    /** @return list<string> ARNs of the functions with an event source mapping on the queue */
    public function consumers(string $arn): array
    {
        $functions = [];
        $marker = null;
        do {
            $page = Aws::call($this->lambda, 'ListEventSourceMappings', ['EventSourceArn' => $arn] + ($marker === null ? [] : ['Marker' => $marker]));
            foreach (Aws::maps($page['EventSourceMappings'] ?? []) as $mapping) {
                $function = Aws::string($mapping['FunctionArn'] ?? null);
                if ($function !== null) {
                    $functions[] = $function;
                }
            }
            $marker = Aws::string($page['NextMarker'] ?? null);
        } while ($marker !== null);
        return $functions;
    }
}
