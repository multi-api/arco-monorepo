<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy;

use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Deploy\ResourcesStack;
use PHPUnit\Framework\TestCase;

final class ResourcesStackTest extends TestCase
{
    private function config(): AppConfig
    {
        $raw = App::make('shop')->region('us-east-1')
            ->queue('emails', timeout: 120, tries: 5)
            ->queue('high-priority', fifo: true, dlq: false)
            ->queue('legacy', existing: 'arn:aws:sqs:us-east-1:123456789012:legacy', dlq: false)
            ->environment('production', fn ($e) => $e->account('123456789012'))
            ->toArray();
        return ConfigResolver::resolve($raw, 'production');
    }

    public function test_name(): void
    {
        self::assertSame('arco-shop-production-resources', ResourcesStack::name($this->config()));
    }

    public function test_logical_ids_are_unique_per_queue_name(): void
    {
        self::assertSame('QueueEmails', ResourcesStack::logicalId('emails'));
        self::assertSame('QueueHighXpriority', ResourcesStack::logicalId('high-priority'));
        self::assertNotSame(ResourcesStack::logicalId('a-1'), ResourcesStack::logicalId('a1'));
        self::assertSame('QueueEmailsDlq', ResourcesStack::dlqLogicalId('emails'));
    }

    public function test_template_with_a_standard_queue_with_dlq_a_fifo_queue_without_and_an_existing_queue_left_out(): void
    {
        $template = ResourcesStack::template($this->config());

        $tags = fn (string $resource) => [
            ['Key' => 'arco:app', 'Value' => 'shop'],
            ['Key' => 'arco:environment', 'Value' => 'production'],
            ['Key' => 'arco:resource', 'Value' => $resource],
        ];
        self::assertSame([
            'QueueEmails' => [
                'Type' => 'AWS::SQS::Queue',
                'DeletionPolicy' => 'Retain',
                'UpdateReplacePolicy' => 'Retain',
                'Properties' => [
                    'QueueName' => 'shop-production-emails',
                    'VisibilityTimeout' => 720,
                    'RedrivePolicy' => [
                        'deadLetterTargetArn' => ['Fn::GetAtt' => ['QueueEmailsDlq', 'Arn']],
                        'maxReceiveCount' => 7,
                    ],
                    'Tags' => $tags('queue:emails'),
                ],
            ],
            'QueueEmailsDlq' => [
                'Type' => 'AWS::SQS::Queue',
                'DeletionPolicy' => 'Retain',
                'UpdateReplacePolicy' => 'Retain',
                'Properties' => [
                    'QueueName' => 'shop-production-emails-dlq',
                    'MessageRetentionPeriod' => 1209600,
                    'Tags' => $tags('queue:emails:dlq'),
                ],
            ],
            'QueueHighXpriority' => [
                'Type' => 'AWS::SQS::Queue',
                'DeletionPolicy' => 'Retain',
                'UpdateReplacePolicy' => 'Retain',
                'Properties' => [
                    'QueueName' => 'shop-production-high-priority.fifo',
                    'VisibilityTimeout' => 360,
                    'FifoQueue' => true,
                    'Tags' => $tags('queue:high-priority'),
                ],
            ],
        ], $template['Resources']);
        self::assertSame([
            'QueueEmailsUrl' => ['Value' => ['Ref' => 'QueueEmails']],
            'QueueEmailsArn' => ['Value' => ['Fn::GetAtt' => ['QueueEmails', 'Arn']]],
            'QueueHighXpriorityUrl' => ['Value' => ['Ref' => 'QueueHighXpriority']],
            'QueueHighXpriorityArn' => ['Value' => ['Fn::GetAtt' => ['QueueHighXpriority', 'Arn']]],
        ], $template['Outputs']);
        self::assertSame('2010-09-09', $template['AWSTemplateFormatVersion']);
    }

    public function test_a_fifo_queue_gets_a_fifo_dlq(): void
    {
        $raw = App::make('shop')->region('us-east-1')->queue('orders', fifo: true)->environment('production')->toArray();
        $resources = ResourcesStack::template(ConfigResolver::resolve($raw, 'production'))['Resources'];

        self::assertIsArray($resources['QueueOrdersDlq']['Properties'] ?? null);
        self::assertSame('shop-production-orders-dlq.fifo', $resources['QueueOrdersDlq']['Properties']['QueueName']);
        self::assertTrue($resources['QueueOrdersDlq']['Properties']['FifoQueue']);
    }

    public function test_created_queues_leave_existing_ones_out(): void
    {
        self::assertSame(['emails', 'high-priority'], array_map(fn ($q) => $q->name, ResourcesStack::createdQueues($this->config())));
    }
    private function storageConfig(): AppConfig
    {
        $raw = App::make('shop')->region('us-east-1')
            ->storage('uploads')
            ->storage('media', public: ['public/', 'avatars/'], expire: ['tmp/' => 1, 'exports/' => 30], cors: ['https://www.multiapi.cl', 'http://localhost:3000'])
            ->storage('legacy', existing: 'legacy-bucket')
            ->cache()
            ->failedJobsTable()
            ->environment('production', fn ($e) => $e->account('111111111111'))
            ->toArray();
        return ConfigResolver::resolve($raw, 'production');
    }

    /** @return list<array{Key: string, Value: string}> */
    private static function tags(string $resource): array
    {
        return [
            ['Key' => 'arco:app', 'Value' => 'shop'],
            ['Key' => 'arco:environment', 'Value' => 'production'],
            ['Key' => 'arco:resource', 'Value' => $resource],
        ];
    }

    public function test_bucket_and_table_logical_ids_never_collide_with_queue_ids(): void
    {
        self::assertSame('StorageUploads', ResourcesStack::bucketLogicalId('uploads'));
        self::assertSame('StorageUserXfiles', ResourcesStack::bucketLogicalId('user-files'));
        self::assertSame('TableCache', ResourcesStack::tableLogicalId('cache'));
        self::assertSame('TableFailedXjobs', ResourcesStack::tableLogicalId('failed-jobs'));
        self::assertNotSame(ResourcesStack::logicalId('uploads'), ResourcesStack::bucketLogicalId('uploads'));
    }

    public function test_template_of_a_private_bucket(): void
    {
        $resources = ResourcesStack::template($this->storageConfig())['Resources'];

        self::assertSame([
            'Type' => 'AWS::S3::Bucket',
            'DeletionPolicy' => 'Retain',
            'UpdateReplacePolicy' => 'Retain',
            'Properties' => [
                'BucketName' => 'shop-production-uploads-111111111111',
                'BucketEncryption' => ['ServerSideEncryptionConfiguration' => [['ServerSideEncryptionByDefault' => ['SSEAlgorithm' => 'AES256']]]],
                'PublicAccessBlockConfiguration' => ['BlockPublicAcls' => true, 'BlockPublicPolicy' => true, 'IgnorePublicAcls' => true, 'RestrictPublicBuckets' => true],
                'Tags' => self::tags('storage:uploads'),
            ],
        ], $resources['StorageUploads']);
        self::assertArrayNotHasKey('StorageUploadsPolicy', $resources, 'nothing public without public prefixes');
        self::assertArrayNotHasKey('StorageLegacy', $resources, 'an existing bucket is not in the template');
    }

    public function test_template_of_a_public_bucket_with_expire_and_cors(): void
    {
        $template = ResourcesStack::template($this->storageConfig());
        $resources = $template['Resources'];

        self::assertSame([
            'Type' => 'AWS::S3::Bucket',
            'DeletionPolicy' => 'Retain',
            'UpdateReplacePolicy' => 'Retain',
            'Properties' => [
                'BucketName' => 'shop-production-media-111111111111',
                'BucketEncryption' => ['ServerSideEncryptionConfiguration' => [['ServerSideEncryptionByDefault' => ['SSEAlgorithm' => 'AES256']]]],
                'PublicAccessBlockConfiguration' => ['BlockPublicAcls' => true, 'BlockPublicPolicy' => false, 'IgnorePublicAcls' => true, 'RestrictPublicBuckets' => false],
                'LifecycleConfiguration' => ['Rules' => [
                    ['Id' => 'arco-expire-1', 'Prefix' => 'tmp/', 'ExpirationInDays' => 1, 'Status' => 'Enabled'],
                    ['Id' => 'arco-expire-2', 'Prefix' => 'exports/', 'ExpirationInDays' => 30, 'Status' => 'Enabled'],
                ]],
                'CorsConfiguration' => ['CorsRules' => [[
                    'AllowedOrigins' => ['https://www.multiapi.cl', 'http://localhost:3000'],
                    'AllowedMethods' => ['GET', 'PUT', 'POST', 'HEAD'],
                    'AllowedHeaders' => ['*'],
                    'MaxAge' => 3000,
                ]]],
                'Tags' => self::tags('storage:media'),
            ],
        ], $resources['StorageMedia']);
        self::assertSame([
            'Type' => 'AWS::S3::BucketPolicy',
            'DependsOn' => 'StorageMedia',
            'Properties' => [
                'Bucket' => 'shop-production-media-111111111111',
                'PolicyDocument' => [
                    'Version' => '2012-10-17',
                    'Statement' => [
                        ['Sid' => 'ArcoPublicRead1', 'Effect' => 'Allow', 'Principal' => '*', 'Action' => 's3:GetObject', 'Resource' => 'arn:aws:s3:::shop-production-media-111111111111/public/*'],
                        ['Sid' => 'ArcoPublicRead2', 'Effect' => 'Allow', 'Principal' => '*', 'Action' => 's3:GetObject', 'Resource' => 'arn:aws:s3:::shop-production-media-111111111111/avatars/*'],
                    ],
                ],
            ],
        ], $resources['StorageMediaPolicy']);
        self::assertSame(['Value' => ['Ref' => 'StorageMedia']], $template['Outputs']['StorageMediaName']);
        self::assertSame(['Value' => ['Fn::GetAtt' => ['StorageMedia', 'Arn']]], $template['Outputs']['StorageMediaArn']);
    }

    public function test_template_of_the_cache_and_failed_jobs_tables(): void
    {
        $template = ResourcesStack::template($this->storageConfig());
        $resources = $template['Resources'];

        self::assertSame([
            'Type' => 'AWS::DynamoDB::Table',
            'DeletionPolicy' => 'Retain',
            'UpdateReplacePolicy' => 'Retain',
            'Properties' => [
                'TableName' => 'shop-production-cache',
                'BillingMode' => 'PAY_PER_REQUEST',
                'AttributeDefinitions' => [['AttributeName' => 'key', 'AttributeType' => 'S']],
                'KeySchema' => [['AttributeName' => 'key', 'KeyType' => 'HASH']],
                'TimeToLiveSpecification' => ['AttributeName' => 'expires_at', 'Enabled' => true],
                'Tags' => self::tags('cache'),
            ],
        ], $resources['TableCache']);
        self::assertSame([
            'Type' => 'AWS::DynamoDB::Table',
            'DeletionPolicy' => 'Retain',
            'UpdateReplacePolicy' => 'Retain',
            'Properties' => [
                'TableName' => 'shop-production-failed-jobs',
                'BillingMode' => 'PAY_PER_REQUEST',
                'AttributeDefinitions' => [['AttributeName' => 'application', 'AttributeType' => 'S'], ['AttributeName' => 'uuid', 'AttributeType' => 'S']],
                'KeySchema' => [['AttributeName' => 'application', 'KeyType' => 'HASH'], ['AttributeName' => 'uuid', 'KeyType' => 'RANGE']],
                'TimeToLiveSpecification' => ['AttributeName' => 'expires_at', 'Enabled' => true],
                'Tags' => self::tags('failed-jobs'),
            ],
        ], $resources['TableFailedXjobs']);
        self::assertSame(['Value' => ['Ref' => 'TableCache']], $template['Outputs']['TableCacheName']);
        self::assertSame(['Value' => ['Fn::GetAtt' => ['TableFailedXjobs', 'Arn']]], $template['Outputs']['TableFailedXjobsArn']);
        self::assertSame(['StorageUploads', 'StorageMedia', 'StorageMediaPolicy', 'TableCache', 'TableFailedXjobs'], array_keys($resources));
    }

    public function test_existing_tables_are_left_out(): void
    {
        $raw = App::make('shop')->region('us-east-1')->cache(existing: 'their-cache')->failedJobsTable(existing: 'their-failed')->environment('production')->toArray();

        self::assertSame([], ResourcesStack::template(ConfigResolver::resolve($raw, 'production'))['Resources']);
    }
}
