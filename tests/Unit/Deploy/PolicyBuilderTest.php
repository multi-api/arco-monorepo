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

    public function test_the_console_may_read_runtime_binaries_from_the_code_bucket_only(): void
    {
        $doc = PolicyBuilder::document('us-east-1', '111111111111', 'shop-production', [], [], 'arco-111111111111-us-east-1');

        $bySid = array_column($doc['Statement'], null, 'Sid');
        self::assertSame(['s3:GetObject', 's3:GetObjectVersion'], $bySid['ArcoRuntimeBinary']['Action']);
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
