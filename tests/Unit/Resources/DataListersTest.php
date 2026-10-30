<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Resources;

use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\Sqs\Exception\SqsException;
use Multiapi\Arco\Resources\BucketLister;
use Multiapi\Arco\Resources\QueueLister;
use Multiapi\Arco\Resources\ResourceItem;
use Multiapi\Arco\Resources\ResourceType;
use Multiapi\Arco\Resources\TableLister;
use Multiapi\Arco\Tests\Unit\Deploy\AwsMocks;
use PHPUnit\Framework\TestCase;

final class DataListersTest extends TestCase
{
    private AwsMocks $mocks;

    protected function setUp(): void
    {
        $this->mocks = new AwsMocks();
    }

    private static function tags(string ...$pairs): array
    {
        $tags = [];
        foreach ($pairs as $i => $value) {
            if ($i % 2 === 0) {
                $tags[] = ['Key' => $value, 'Value' => $pairs[$i + 1]];
            }
        }
        return $tags;
    }

    public function test_buckets_with_region_and_tags(): void
    {
        $this->mocks->handler->append(
            new Result(['Buckets' => [['Name' => 'b-assets', 'CreationDate' => '2026-01-02T03:04:05+00:00']]]),
            new Result(['LocationConstraint' => null]),
            new Result(['TagSet' => self::tags('Name', 'assets', 'arco:app', 'shop')]),
        );
        $items = (new BucketLister())->list($this->mocks->clients());

        self::assertCount(1, $items);
        self::assertSame(ResourceType::Bucket, $items[0]->type);
        self::assertSame('b-assets', $items[0]->id);
        self::assertSame('b-assets', $items[0]->name);
        self::assertSame('us-east-1', $items[0]->details['region']);
        self::assertSame('2026-01-02T03:04:05+00:00', $items[0]->details['created']);
        self::assertSame(['Name' => 'assets', 'arco:app' => 'shop'], $items[0]->tags);
        self::assertFalse($items[0]->hidden);
        self::assertSame([], $items[0]->warnings);
    }

    public function test_bucket_in_other_region_is_hidden(): void
    {
        $this->mocks->handler->append(
            new Result(['Buckets' => [['Name' => 'b-eu'], ['Name' => 'b-old'], ['Name' => 'b-sa']]]),
            new Result(['LocationConstraint' => 'eu-central-1']),
            new Result(['LocationConstraint' => 'EU']),
            new Result(['LocationConstraint' => '']),
            new Result(['TagSet' => []]),
        );
        $items = (new BucketLister())->list($this->mocks->clients());

        self::assertSame(['b-eu', 'b-old', 'b-sa'], array_map(fn (ResourceItem $i) => $i->id, $items));
        self::assertTrue($items[0]->hidden);
        self::assertSame([['key' => 'resources.warning.bucket_other_region', 'params' => ['region' => 'eu-central-1']]], $items[0]->warnings);
        self::assertTrue($items[1]->hidden);
        self::assertSame('eu-west-1', $items[1]->details['region']);
        self::assertFalse($items[2]->hidden);
        self::assertSame('us-east-1', $items[2]->details['region']);
        // GetBucketTagging only for the bucket in the client's region
        self::assertSame(['ListBuckets', 'GetBucketLocation', 'GetBucketLocation', 'GetBucketLocation', 'GetBucketTagging'], $this->mocks->calls);
    }

    public function test_bucket_without_tags_is_not_an_error(): void
    {
        $this->mocks->handler->append(
            new Result(['Buckets' => [['Name' => 'b-a'], ['Name' => 'b-b'], ['Name' => 'b-c']]]),
            new Result(['LocationConstraint' => null]),
            AwsMocks::error(S3Exception::class, 'GetBucketTagging', 'NoSuchTagSet', 404),
            new Result(['LocationConstraint' => null]),
            AwsMocks::error(S3Exception::class, 'GetBucketTagging', 'AccessDenied', 403),
            AwsMocks::error(S3Exception::class, 'GetBucketLocation', 'AccessDenied', 403),
            new Result(['TagSet' => self::tags('k', 'v')]),
        );
        $items = (new BucketLister())->list($this->mocks->clients(overrides: ['retries' => 0]));

        self::assertCount(3, $items);
        self::assertSame([], $items[0]->tags);
        self::assertSame([], $items[1]->tags);
        self::assertSame('us-east-1', $items[1]->details['region']);
        self::assertNull($items[2]->details['region']);
        self::assertFalse($items[2]->hidden);
        self::assertSame(['k' => 'v'], $items[2]->tags);
        // A denied GetBucketLocation does not pass for this region: the bucket says its region is unknown.
        self::assertSame([['key' => 'resources.warning.bucket_region_unknown', 'params' => []]], $items[2]->warnings);
        self::assertSame([], $items[0]->warnings);
    }

    public function test_queue_fifo_dlq_and_is_dlq(): void
    {
        $this->mocks->handler->append(
            new Result(['QueueUrls' => ['https://sqs.us-east-1.amazonaws.com/123/q1.fifo', 'https://sqs.us-east-1.amazonaws.com/123/q1-dlq']]),
            new Result(['Attributes' => [
                'QueueArn' => 'arn:aws:sqs:us-east-1:123:q1.fifo', 'FifoQueue' => 'true', 'VisibilityTimeout' => '45',
                'RedrivePolicy' => json_encode(['deadLetterTargetArn' => 'arn:aws:sqs:us-east-1:123:q1-dlq', 'maxReceiveCount' => 3]),
            ]]),
            new Result(['Tags' => ['Name' => 'jobs']]),
            new Result(['Attributes' => ['QueueArn' => 'arn:aws:sqs:us-east-1:123:q1-dlq', 'VisibilityTimeout' => '30']]),
            new Result([]),
        );
        [$dlq, $q1] = (new QueueLister())->list($this->mocks->clients());

        self::assertSame('q1.fifo', $q1->name);
        self::assertSame('https://sqs.us-east-1.amazonaws.com/123/q1.fifo', $q1->id);
        self::assertSame(ResourceType::Queue, $q1->type);
        self::assertSame('https://sqs.us-east-1.amazonaws.com/123/q1.fifo', $q1->details['url']);
        self::assertSame('arn:aws:sqs:us-east-1:123:q1.fifo', $q1->details['arn']);
        self::assertTrue($q1->details['fifo']);
        self::assertSame(45, $q1->details['visibilityTimeout']);
        self::assertSame('arn:aws:sqs:us-east-1:123:q1-dlq', $q1->details['dlq']);
        self::assertSame(['Name' => 'jobs'], $q1->tags);
        self::assertSame([], $q1->warnings);
        self::assertSame('q1-dlq', $dlq->name);
        self::assertFalse($dlq->details['fifo']);
        self::assertNull($dlq->details['dlq']);
        self::assertSame([['key' => 'resources.warning.queue_is_dlq', 'params' => []]], $dlq->warnings);
    }

    public function test_queue_deleted_while_listing_is_skipped(): void
    {
        $this->mocks->handler->append(
            new Result(['QueueUrls' => ['https://sqs/1/gone', 'https://sqs/1/gone-tags', 'https://sqs/1/kept']]),
            AwsMocks::error(SqsException::class, 'GetQueueAttributes', 'AWS.SimpleQueueService.NonExistentQueue', 400),
            new Result(['Attributes' => ['QueueArn' => 'arn:gone-tags']]),
            AwsMocks::error(SqsException::class, 'ListQueueTags', 'QueueDoesNotExist', 400),
            new Result(['Attributes' => ['QueueArn' => 'arn:kept']]),
            new Result([]),
        );
        $items = (new QueueLister())->list($this->mocks->clients(overrides: ['retries' => 0]));

        self::assertSame(['https://sqs/1/kept'], array_map(fn (ResourceItem $i) => $i->id, $items));
    }

    public function test_other_queue_errors_fail_the_type(): void
    {
        $this->mocks->handler->append(
            new Result(['QueueUrls' => ['https://sqs/1/q']]),
            AwsMocks::error(SqsException::class, 'GetQueueAttributes', 'AccessDenied', 403),
        );
        $this->expectException(SqsException::class);
        (new QueueLister())->list($this->mocks->clients(overrides: ['retries' => 0]));
    }

    public function test_table_deleted_while_listing_is_skipped(): void
    {
        $table = fn (string $name) => new Result(['Table' => ['TableName' => $name, 'TableArn' => 'arn:' . $name, 'KeySchema' => [], 'AttributeDefinitions' => []]]);
        $gone = fn (string $operation) => AwsMocks::error(DynamoDbException::class, $operation, 'ResourceNotFoundException', 400);
        $this->mocks->handler->append(
            new Result(['TableNames' => ['a', 'b', 'c', 'kept']]),
            $gone('DescribeTable'),
            $table('b'), $gone('DescribeTimeToLive'),
            $table('c'), new Result(['TimeToLiveDescription' => []]), $gone('ListTagsOfResource'),
            $table('kept'), new Result(['TimeToLiveDescription' => []]), new Result(['Tags' => []]),
        );
        $items = (new TableLister())->list($this->mocks->clients(overrides: ['retries' => 0]));

        self::assertSame(['kept'], array_map(fn (ResourceItem $i) => $i->id, $items));
    }

    public function test_other_table_errors_fail_the_type(): void
    {
        $this->mocks->handler->append(
            new Result(['TableNames' => ['t']]),
            AwsMocks::error(DynamoDbException::class, 'DescribeTable', 'AccessDeniedException', 400),
        );
        $this->expectException(DynamoDbException::class);
        (new TableLister())->list($this->mocks->clients(overrides: ['retries' => 0]));
    }

    public function test_table_keys_ttl_billing(): void
    {
        $this->mocks->handler->append(
            new Result(['TableNames' => ['orders']]),
            new Result(['Table' => [
                'TableName' => 'orders', 'TableArn' => 'arn:aws:dynamodb:us-east-1:123:table/orders',
                'KeySchema' => [['AttributeName' => 'PK', 'KeyType' => 'HASH'], ['AttributeName' => 'SK', 'KeyType' => 'RANGE']],
                'AttributeDefinitions' => [['AttributeName' => 'SK', 'AttributeType' => 'S'], ['AttributeName' => 'PK', 'AttributeType' => 'S']],
                'BillingModeSummary' => ['BillingMode' => 'PAY_PER_REQUEST'],
            ]]),
            new Result(['TimeToLiveDescription' => ['TimeToLiveStatus' => 'ENABLED', 'AttributeName' => 'ttl']]),
            new Result(['Tags' => self::tags('Name', 'orders')]),
        );
        $items = (new TableLister())->list($this->mocks->clients());

        self::assertCount(1, $items);
        self::assertSame(ResourceType::Table, $items[0]->type);
        self::assertSame('orders', $items[0]->id);
        self::assertSame('orders', $items[0]->name);
        self::assertSame('arn:aws:dynamodb:us-east-1:123:table/orders', $items[0]->details['arn']);
        self::assertSame([['name' => 'PK', 'type' => 'S', 'role' => 'HASH'], ['name' => 'SK', 'type' => 'S', 'role' => 'RANGE']], $items[0]->details['keys']);
        self::assertSame('ttl', $items[0]->details['ttl']);
        self::assertSame('PAY_PER_REQUEST', $items[0]->details['billing']);
        self::assertSame(['Name' => 'orders'], $items[0]->tags);
        self::assertSame('arn:aws:dynamodb:us-east-1:123:table/orders', $this->mocks->params[3]['ResourceArn']);
    }

    public function test_table_defaults_to_provisioned_without_ttl(): void
    {
        $this->mocks->handler->append(
            new Result(['TableNames' => ['t']]),
            new Result(['Table' => ['TableName' => 't', 'TableArn' => 'arn:t', 'KeySchema' => [['AttributeName' => 'id', 'KeyType' => 'HASH']], 'AttributeDefinitions' => [['AttributeName' => 'id', 'AttributeType' => 'N']]]]),
            new Result(['TimeToLiveDescription' => ['TimeToLiveStatus' => 'DISABLED']]),
            new Result(['Tags' => []]),
        );
        $items = (new TableLister())->list($this->mocks->clients());

        self::assertSame('PROVISIONED', $items[0]->details['billing']);
        self::assertNull($items[0]->details['ttl']);
    }

    public function test_buckets_queues_tables_paginate(): void
    {
        $this->mocks->handler->append(
            new Result(['Buckets' => [['Name' => 'b1']], 'ContinuationToken' => 'bt']),
            new Result(['Buckets' => [['Name' => 'b2']]]),
            new Result(['LocationConstraint' => null]), new Result(['TagSet' => []]),
            new Result(['LocationConstraint' => null]), new Result(['TagSet' => []]),
        );
        $buckets = (new BucketLister())->list($this->mocks->clients());
        self::assertSame(['b1', 'b2'], array_map(fn (ResourceItem $i) => $i->id, $buckets));
        self::assertSame('bt', $this->mocks->params[1]['ContinuationToken']);

        $this->mocks->handler->append(
            new Result(['QueueUrls' => ['https://sqs/1/qa'], 'NextToken' => 'qt']),
            new Result(['QueueUrls' => ['https://sqs/1/qb']]),
            new Result(['Attributes' => ['QueueArn' => 'arn:qa']]), new Result([]),
            new Result(['Attributes' => ['QueueArn' => 'arn:qb']]), new Result([]),
        );
        $queues = (new QueueLister())->list($this->mocks->clients());
        self::assertSame(['https://sqs/1/qa', 'https://sqs/1/qb'], array_map(fn (ResourceItem $i) => $i->id, $queues));
        self::assertSame('qt', $this->mocks->params[array_search('ListQueues', $this->mocks->calls, true) + 1]['NextToken']);

        $table = fn (string $name) => new Result(['Table' => ['TableName' => $name, 'TableArn' => 'arn:' . $name, 'KeySchema' => [], 'AttributeDefinitions' => []]]);
        $this->mocks->handler->append(
            new Result(['TableNames' => ['ta'], 'LastEvaluatedTableName' => 'ta']),
            new Result(['TableNames' => ['tb']]),
            $table('ta'), new Result(['TimeToLiveDescription' => []]), new Result(['Tags' => [], 'NextToken' => 'tg']), new Result(['Tags' => self::tags('k', 'v')]),
            $table('tb'), new Result(['TimeToLiveDescription' => []]), new Result(['Tags' => []]),
        );
        $before = count($this->mocks->calls);
        $tables = (new TableLister())->list($this->mocks->clients());
        self::assertSame(['ta', 'tb'], array_map(fn (ResourceItem $i) => $i->id, $tables));
        self::assertSame('ta', $this->mocks->params[$before + 1]['ExclusiveStartTableName']);
        self::assertSame('tg', $this->mocks->params[$before + 5]['NextToken']);
        self::assertSame(['k' => 'v'], $tables[0]->tags);
    }

    public function test_calls_are_read_only(): void
    {
        $this->mocks->handler->append(
            new Result(['Buckets' => [['Name' => 'b']]]), new Result(['LocationConstraint' => null]), new Result(['TagSet' => []]),
            new Result(['QueueUrls' => ['https://sqs/1/q']]), new Result(['Attributes' => ['QueueArn' => 'arn:q']]), new Result([]),
            new Result(['TableNames' => ['t']]), new Result(['Table' => ['TableName' => 't', 'TableArn' => 'arn:t']]), new Result([]), new Result([]),
        );
        (new BucketLister())->list($this->mocks->clients());
        (new QueueLister())->list($this->mocks->clients());
        (new TableLister())->list($this->mocks->clients());

        self::assertSame([], array_values(array_diff($this->mocks->calls, [
            'ListBuckets', 'GetBucketLocation', 'GetBucketTagging', 'ListQueues', 'GetQueueAttributes', 'ListQueueTags',
            'ListTables', 'DescribeTable', 'DescribeTimeToLive', 'ListTagsOfResource',
        ])));
        self::assertCount(10, $this->mocks->calls);
    }
}
