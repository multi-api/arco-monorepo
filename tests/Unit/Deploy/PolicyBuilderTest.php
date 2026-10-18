<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy;

use Multiapi\Arco\Deploy\DeployException;
use Multiapi\Arco\Deploy\PolicyBuilder;
use Multiapi\Arco\Secrets\SecretReference;
use PHPUnit\Framework\TestCase;

final class PolicyBuilderTest extends TestCase
{
    public function test_logs_only_without_references(): void
    {
        $doc = PolicyBuilder::document('us-east-1', '111111111111', 'shop-production', []);

        self::assertCount(1, $doc['Statement']);
        self::assertSame(['logs:CreateLogGroup', 'logs:CreateLogStream', 'logs:PutLogEvents'], $doc['Statement'][0]['Action']);
        self::assertSame([
            'arn:aws:logs:us-east-1:111111111111:log-group:/aws/lambda/web/shop-production:*',
            'arn:aws:logs:us-east-1:111111111111:log-group:/aws/lambda/web/shop-production.local:*',
            'arn:aws:logs:us-east-1:111111111111:log-group:/aws/lambda/shop-production-console:*',
        ], $doc['Statement'][0]['Resource']);
    }

    public function test_queues_and_workers_extend_the_policy(): void
    {
        $arns = ['arn:aws:sqs:us-east-1:111111111111:shop-production-jobs', 'arn:aws:sqs:eu-west-1:222222222222:legacy'];
        $doc = PolicyBuilder::document('us-east-1', '111111111111', 'shop-production', [], [], null, $arns, ['shop-production-queue-jobs']);

        $bySid = array_column($doc['Statement'], null, 'Sid');
        self::assertSame(['sqs:SendMessage', 'sqs:ReceiveMessage', 'sqs:DeleteMessage', 'sqs:ChangeMessageVisibility', 'sqs:GetQueueAttributes', 'sqs:GetQueueUrl'], $bySid['ArcoQueues']['Action']);
        self::assertSame($arns, $bySid['ArcoQueues']['Resource']);
        self::assertSame('arn:aws:logs:us-east-1:111111111111:log-group:/aws/lambda/shop-production-queue-jobs:*', $bySid['ArcoLogs']['Resource'][3]);
    }

    public function test_without_queues_there_is_no_queue_statement(): void
    {
        $doc = PolicyBuilder::document('us-east-1', '111111111111', 'shop-production', []);

        self::assertArrayNotHasKey('ArcoQueues', array_column($doc['Statement'], null, 'Sid'));
        self::assertCount(3, $doc['Statement'][0]['Resource']);
    }

    public function test_the_queue_statement_counts_toward_the_size_limit(): void
    {
        $this->expectException(DeployException::class);
        $arns = array_map(fn (int $i) => "arn:aws:sqs:us-east-1:111111111111:shop-production-queue-$i", range(1, 400));

        PolicyBuilder::document('us-east-1', '111111111111', 'shop-production', [], [], null, $arns);
    }

    public function test_buckets_and_tables_extend_the_policy(): void
    {
        $buckets = ['arn:aws:s3:::shop-production-uploads-111111111111', 'arn:aws:s3:::legacy.bucket'];
        $tables = ['arn:aws:dynamodb:us-east-1:111111111111:table/shop-production-cache', 'arn:aws:dynamodb:us-east-1:111111111111:table/shop-production-failed-jobs'];
        $doc = PolicyBuilder::document('us-east-1', '111111111111', 'shop-production', [], [], null, [], [], $buckets, $tables);

        $bySid = array_column($doc['Statement'], null, 'Sid');
        self::assertSame([
            [
                'Sid' => 'ArcoStorage',
                'Effect' => 'Allow',
                'Action' => ['s3:GetObject', 's3:PutObject', 's3:DeleteObject', 's3:GetObjectAcl', 's3:PutObjectAcl', 's3:AbortMultipartUpload'],
                'Resource' => ['arn:aws:s3:::shop-production-uploads-111111111111/*', 'arn:aws:s3:::legacy.bucket/*'],
            ],
            [
                'Sid' => 'ArcoStorageList',
                'Effect' => 'Allow',
                'Action' => ['s3:ListBucket'],
                'Resource' => $buckets,
            ],
        ], [$bySid['ArcoStorage'], $bySid['ArcoStorageList']]);
        self::assertSame([
            'Sid' => 'ArcoTables',
            'Effect' => 'Allow',
            'Action' => ['dynamodb:GetItem', 'dynamodb:PutItem', 'dynamodb:UpdateItem', 'dynamodb:DeleteItem', 'dynamodb:Query', 'dynamodb:BatchGetItem', 'dynamodb:BatchWriteItem', 'dynamodb:DescribeTable'],
            'Resource' => $tables,
        ], $bySid['ArcoTables']);
    }

    public function test_without_buckets_or_tables_there_are_no_storage_statements(): void
    {
        $sids = array_column(PolicyBuilder::document('us-east-1', '111111111111', 'shop-production', [])['Statement'], 'Sid');

        self::assertSame(['ArcoLogs'], $sids);
    }

    public function test_the_storage_statements_count_toward_the_size_limit(): void
    {
        $this->expectException(DeployException::class);
        $arns = array_map(fn (int $i) => "arn:aws:s3:::shop-production-bucket-$i-111111111111", range(1, 150));

        PolicyBuilder::document('us-east-1', '111111111111', 'shop-production', [], [], null, [], [], $arns);
    }

    public function test_the_console_may_read_runtime_binaries_from_the_code_bucket_only(): void
    {
        $doc = PolicyBuilder::document('us-east-1', '111111111111', 'shop-production', [], [], 'arco-111111111111-us-east-1');

        $bySid = array_column($doc['Statement'], null, 'Sid');
        self::assertSame('s3:GetObject', $bySid['ArcoRuntimeBinary']['Action']);
        self::assertSame('arn:aws:s3:::arco-111111111111-us-east-1/runtime/*', $bySid['ArcoRuntimeBinary']['Resource']);
    }

    public function test_scopes_secret_access_to_referenced_resources(): void
    {
        $doc = PolicyBuilder::document('us-east-1', '111111111111', 'shop-production', [
            'APP_KEY' => SecretReference::parse('arco-ssm:/shop/prod/app-key'),
            'NAME' => SecretReference::parse('arco-ssm:plain'),
            'DB' => SecretReference::parse('arco-secret:shop/prod/db#password'),
            'ARN' => SecretReference::parse('arco-secret:arn:aws:secretsmanager:us-east-1:111111111111:secret:x-AbCdEf'),
        ]);

        $bySid = array_column($doc['Statement'], null, 'Sid');
        self::assertSame([
            'arn:aws:ssm:us-east-1:111111111111:parameter/shop/prod/app-key',
            'arn:aws:ssm:us-east-1:111111111111:parameter/plain',
        ], $bySid['ArcoParameters']['Resource']);
        self::assertSame([
            'arn:aws:secretsmanager:us-east-1:111111111111:secret:shop/prod/db-??????',
            'arn:aws:secretsmanager:us-east-1:111111111111:secret:x-AbCdEf',
        ], $bySid['ArcoSecrets']['Resource']);
        self::assertSame('*', $bySid['ArcoSecretsBatch']['Resource']);
        self::assertSame(
            ['ssm.us-east-1.amazonaws.com', 'secretsmanager.us-east-1.amazonaws.com'],
            $bySid['ArcoDecrypt']['Condition']['StringEquals']['kms:ViaService'],
        );
    }

    public function test_appends_permissions_after_arco_statements(): void
    {
        $extra = ['Effect' => 'Allow', 'Action' => 'dynamodb:GetItem', 'Resource' => 'arn:aws:dynamodb:us-east-1:111111111111:table/cache'];
        $doc = PolicyBuilder::document('us-east-1', '111111111111', 'shop-production', [], [$extra]);

        self::assertCount(2, $doc['Statement']);
        self::assertSame('ArcoLogs', $doc['Statement'][0]['Sid']);
        self::assertSame($extra, $doc['Statement'][1]);
    }

    public function test_rejects_a_policy_larger_than_iam_allows(): void
    {
        $resources = array_map(fn (int $i) => "arn:aws:s3:::bucket-number-$i/*", range(1, 400));

        try {
            PolicyBuilder::document('us-east-1', '111111111111', 'shop-production', [], [['Effect' => 'Allow', 'Action' => 's3:GetObject', 'Resource' => $resources]]);
            self::fail('Expected DeployException');
        } catch (DeployException $e) {
            self::assertSame('deploy.policy_too_large', $e->key);
            self::assertSame(PolicyBuilder::MAX_SIZE, $e->params['max']);
        }
    }
}
