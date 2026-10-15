<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy;

use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Multiapi\Arco\Deploy\CodeBucket;
use Multiapi\Arco\Deploy\DeployException;
use PHPUnit\Framework\TestCase;

final class CodeBucketTest extends TestCase
{
    public function test_creates_a_private_versioned_bucket_when_missing(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            AwsMocks::error(S3Exception::class, 'HeadBucket', 'NotFound', 404),
            new Result([]), new Result([]), new Result([]), new Result([]), new Result([]),
        );

        (new CodeBucket($mocks->clients()->s3()))->ensure('arco-111111111111-us-east-2', 'us-east-2', '111111111111');

        self::assertSame(['HeadBucket', 'CreateBucket', 'PutPublicAccessBlock', 'PutBucketPolicy', 'PutBucketVersioning', 'PutBucketLifecycleConfiguration'], $mocks->calls);
        self::assertSame(['LocationConstraint' => 'us-east-2'], $mocks->params[1]['CreateBucketConfiguration']);
        self::assertTrue($mocks->params[2]['PublicAccessBlockConfiguration']['BlockPublicAcls']);
        $policy = json_decode($mocks->params[3]['Policy'], true);
        self::assertSame(['Service' => 'lambda.amazonaws.com'], $policy['Statement'][1]['Principal']);
        self::assertSame(['s3:GetObject', 's3:GetObjectVersion'], $policy['Statement'][1]['Action']);
        self::assertSame('arn:aws:s3:::arco-111111111111-us-east-2/*', $policy['Statement'][1]['Resource']);
        self::assertSame(['StringEquals' => ['aws:SourceAccount' => '111111111111']], $policy['Statement'][1]['Condition']);
    }

    public function test_existing_bucket_is_not_recreated_but_reconfigured_and_forbidden_bucket_fails(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(new Result([]), new Result([]), new Result([]), new Result([]), new Result([]));
        (new CodeBucket($mocks->clients()->s3()))->ensure('b', 'us-east-1', '111111111111');
        self::assertSame(['HeadBucket', 'PutPublicAccessBlock', 'PutBucketPolicy', 'PutBucketVersioning', 'PutBucketLifecycleConfiguration'], $mocks->calls);

        $mocks = new AwsMocks();
        $mocks->handler->append(AwsMocks::error(S3Exception::class, 'HeadBucket', 'Forbidden', 403));
        $this->expectException(DeployException::class);
        (new CodeBucket($mocks->clients()->s3()))->ensure('b', 'us-east-1', '111111111111');
    }

    public function test_upload_skips_existing_key(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'pkg');
        file_put_contents((string) $file, 'zip');

        $mocks = new AwsMocks();
        $mocks->handler->append(new Result(['VersionId' => 'v1']));
        self::assertSame('v1', (new CodeBucket($mocks->clients()->s3()))->upload('b', 'shop/prod/h.zip', (string) $file));
        self::assertSame(['HeadObject'], $mocks->calls);

        $mocks = new AwsMocks();
        $mocks->handler->append(AwsMocks::error(S3Exception::class, 'HeadObject', 'NotFound', 404), new Result(['VersionId' => 'v2']));
        self::assertSame('v2', (new CodeBucket($mocks->clients()->s3()))->upload('b', 'shop/prod/h.zip', (string) $file));
        self::assertSame(['HeadObject', 'PutObject'], $mocks->calls);
    }

    public function test_prune_keeps_newest_and_protected(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['Contents' => [
                ['Key' => 'p/a.zip', 'LastModified' => new \DateTimeImmutable('2026-01-01')],
                ['Key' => 'p/b.zip', 'LastModified' => new \DateTimeImmutable('2026-01-03')],
                ['Key' => 'p/c.zip', 'LastModified' => new \DateTimeImmutable('2026-01-02')],
                ['Key' => 'p/old-but-live.zip', 'LastModified' => new \DateTimeImmutable('2025-01-01')],
            ]]),
            new Result([]),
        );

        $deleted = (new CodeBucket($mocks->clients()->s3()))->prune('b', 'p/', 1, ['p/old-but-live.zip']);

        self::assertSame(2, $deleted);
        self::assertSame([['Key' => 'p/c.zip'], ['Key' => 'p/a.zip']], $mocks->params[1]['Delete']['Objects']);
    }
}
