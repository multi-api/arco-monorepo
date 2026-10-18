<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\E2e;

use Aws\Command;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\Result;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Multiapi\Arco\Aws\AwsClients;

/**
 * An in-memory AWS account for AwsE2eCleanup: S3 buckets (with object versions), DynamoDB tables, SQS
 * queues and SSM parameters with their tags, answered through the SDK's handler, so nothing leaves
 * the process. Every call is recorded by operation name.
 */
final class FakeAwsAccount
{
    public const REGION = 'us-east-1';

    /** @var list<string> */
    public array $calls = [];
    /** @var array<string, array{tags: array<string, string>|null, versions: list<array{Key: string, VersionId: string}>}> */
    public array $buckets = [];
    /** @var array<string, array<string, string>> table name => tags */
    public array $tables = [];
    /** @var array<string, array<string, string>> queue name => tags */
    public array $queues = [];
    /** @var array<string, array<string, string>> parameter name => tags */
    public array $parameters = [];

    public function __construct(public string $account = '111122223333')
    {
    }

    public function clients(): AwsClients
    {
        return new AwsClients(null, self::REGION, [
            'handler' => fn (CommandInterface $command): PromiseInterface => $this->handle($command),
            'credentials' => false,
            'validate' => false,
        ]);
    }

    /** @param array<string, string>|null $tags null: the bucket has no tag set */
    public function bucket(string $name, ?array $tags, int $objects = 0): void
    {
        $versions = [];
        for ($i = 0; $i < $objects; $i++) {
            $versions[] = ['Key' => "file-$i.txt", 'VersionId' => "v$i"];
        }
        $this->buckets[$name] = ['tags' => $tags, 'versions' => $versions];
    }

    /** @return list<string> operations that are not reads */
    public function mutatingCalls(): array
    {
        return array_values(array_filter($this->calls, static fn (string $op): bool => preg_match('/^(List|Get|Describe)/', $op) !== 1));
    }

    private function handle(CommandInterface $command): PromiseInterface
    {
        $name = $command->getName();
        $this->calls[] = $name;
        /** @var array<string, mixed> $args */
        $args = $command->toArray();
        try {
            return Create::promiseFor(new Result($this->answer($name, $args)));
        } catch (AwsException $e) {
            return Create::rejectionFor($e);
        }
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function answer(string $operation, array $args): array
    {
        $string = static fn (string $key): string => is_string($args[$key] ?? null) ? $args[$key] : '';
        switch ($operation) {
            case 'GetCallerIdentity':
                return ['Account' => $this->account, 'Arn' => "arn:aws:iam::{$this->account}:user/e2e"];

            case 'GetResources':
                return ['ResourceTagMappingList' => $this->tagged($args)];

            case 'ListBuckets':
                $prefix = $string('Prefix');
                $names = array_filter(array_keys($this->buckets), static fn (string $b): bool => str_starts_with($b, $prefix));
                return ['Buckets' => array_values(array_map(static fn (string $b): array => ['Name' => $b], $names))];
            case 'GetBucketTagging':
                $bucket = $this->buckets[$string('Bucket')] ?? throw self::error($operation, 'NoSuchBucket', 404);
                if ($bucket['tags'] === null) {
                    throw self::error($operation, 'NoSuchTagSet', 404);
                }
                return ['TagSet' => self::tagList($bucket['tags'])];
            case 'ListObjectVersions':
                $bucket = $this->buckets[$string('Bucket')] ?? throw self::error($operation, 'NoSuchBucket', 404);
                // One page at a time, two versions per page, to exercise the loop.
                return ['Versions' => array_slice($bucket['versions'], 0, 2), 'IsTruncated' => count($bucket['versions']) > 2];
            case 'DeleteObjects':
                $bucket = $string('Bucket');
                $delete = is_array($args['Delete'] ?? null) ? $args['Delete'] : [];
                $objects = is_array($delete['Objects'] ?? null) ? $delete['Objects'] : [];
                $gone = array_map(static fn (mixed $o): string => is_array($o) && is_string($o['VersionId'] ?? null) ? $o['VersionId'] : '', $objects);
                $this->buckets[$bucket]['versions'] = array_values(array_filter($this->buckets[$bucket]['versions'], static fn (array $v): bool => ! in_array($v['VersionId'], $gone, true)));
                return ['Deleted' => $objects];
            case 'DeleteBucket':
                $bucket = $string('Bucket');
                if (($this->buckets[$bucket]['versions'] ?? []) !== []) {
                    throw self::error($operation, 'BucketNotEmpty', 409);
                }
                unset($this->buckets[$bucket]);
                return [];

            case 'ListTables':
                return ['TableNames' => array_keys($this->tables)];
            case 'DescribeTable':
                $table = $string('TableName');
                isset($this->tables[$table]) || throw self::error($operation, 'ResourceNotFoundException', 400);
                return ['Table' => ['TableName' => $table, 'TableArn' => 'arn:aws:dynamodb:' . self::REGION . ":{$this->account}:table/$table"]];
            case 'ListTagsOfResource':
                $arn = $string('ResourceArn');
                $table = substr($arn, (int) strrpos($arn, '/') + 1);
                return ['Tags' => self::tagList($this->tables[$table] ?? throw self::error($operation, 'ResourceNotFoundException', 400))];
            case 'DeleteTable':
                unset($this->tables[$string('TableName')]);
                return [];

            case 'ListQueues':
                $prefix = $string('QueueNamePrefix');
                $names = array_filter(array_keys($this->queues), static fn (string $q): bool => str_starts_with($q, $prefix));
                return ['QueueUrls' => array_values(array_map($this->queueUrl(...), $names))];
            case 'GetQueueUrl':
                $queue = $string('QueueName');
                isset($this->queues[$queue]) || throw self::error($operation, 'AWS.SimpleQueueService.NonExistentQueue', 400);
                return ['QueueUrl' => $this->queueUrl($queue)];
            case 'ListQueueTags':
                return ['Tags' => $this->queues[basename($string('QueueUrl'))] ?? throw self::error($operation, 'AWS.SimpleQueueService.NonExistentQueue', 400)];
            case 'DeleteQueue':
                unset($this->queues[basename($string('QueueUrl'))]);
                return [];

            case 'DescribeParameters':
                $wanted = [];
                foreach (is_array($args['ParameterFilters'] ?? null) ? $args['ParameterFilters'] : [] as $filter) {
                    if (is_array($filter) && ($filter['Key'] ?? null) === 'Name' && is_array($filter['Values'] ?? null)) {
                        $wanted = $filter['Values'];
                    }
                }
                $found = array_values(array_filter(array_keys($this->parameters), static fn (string $p): bool => in_array($p, $wanted, true)));
                return ['Parameters' => array_map(static fn (string $p): array => ['Name' => $p], $found)];
            case 'DeleteParameter':
                $parameter = $string('Name');
                isset($this->parameters[$parameter]) || throw self::error($operation, 'ParameterNotFound', 400);
                unset($this->parameters[$parameter]);
                return [];
        }
        throw new \LogicException("FakeAwsAccount does not answer $operation.");
    }

    /**
     * Resources whose tags carry every value the TagFilters ask for (ARNs as AWS writes them).
     *
     * @param array<string, mixed> $args
     * @return list<array{ResourceARN: string, Tags: list<array{Key: string, Value: string}>}>
     */
    private function tagged(array $args): array
    {
        $filters = [];
        foreach (is_array($args['TagFilters'] ?? null) ? $args['TagFilters'] : [] as $filter) {
            if (is_array($filter) && is_string($filter['Key'] ?? null)) {
                $filters[$filter['Key']] = is_array($filter['Values'] ?? null) ? $filter['Values'] : [];
            }
        }
        $matches = static function (array $tags) use ($filters): bool {
            foreach ($filters as $key => $values) {
                if (! isset($tags[$key]) || ($values !== [] && ! in_array($tags[$key], $values, true))) {
                    return false;
                }
            }
            return true;
        };
        $region = self::REGION;
        $out = [];
        foreach ($this->buckets as $name => $bucket) {
            if ($bucket['tags'] !== null && $matches($bucket['tags'])) {
                $out[] = ['ResourceARN' => "arn:aws:s3:::$name", 'Tags' => self::tagList($bucket['tags'])];
            }
        }
        foreach ($this->tables as $name => $tags) {
            if ($matches($tags)) {
                $out[] = ['ResourceARN' => "arn:aws:dynamodb:$region:{$this->account}:table/$name", 'Tags' => self::tagList($tags)];
            }
        }
        foreach ($this->queues as $name => $tags) {
            if ($matches($tags)) {
                $out[] = ['ResourceARN' => "arn:aws:sqs:$region:{$this->account}:$name", 'Tags' => self::tagList($tags)];
            }
        }
        return $out;
    }

    private function queueUrl(string $name): string
    {
        return 'https://sqs.' . self::REGION . ".amazonaws.com/{$this->account}/$name";
    }

    /**
     * @param array<string, string> $tags
     * @return list<array{Key: string, Value: string}>
     */
    private static function tagList(array $tags): array
    {
        return array_map(static fn (string $k, string $v): array => ['Key' => $k, 'Value' => $v], array_keys($tags), array_values($tags));
    }

    private static function error(string $operation, string $code, int $status): AwsException
    {
        return new AwsException($code, new Command($operation), ['code' => $code, 'message' => $code, 'response' => new Response($status)]);
    }
}
