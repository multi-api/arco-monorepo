<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy;

use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Multiapi\Arco\Deploy\CodeBucket;
use Multiapi\Arco\Deploy\DeployException;
use Multiapi\Arco\Tests\Support\TempPaths;
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
        $file = TempPaths::file('pkg');
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

    public function test_prune_can_leave_a_sub_prefix_to_its_own_prune(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['Contents' => [
                ['Key' => 'p/console-new.zip', 'LastModified' => new \DateTimeImmutable('2026-01-05')],
                ['Key' => 'p/b.zip', 'LastModified' => new \DateTimeImmutable('2026-01-03')],
                ['Key' => 'p/console-old.zip', 'LastModified' => new \DateTimeImmutable('2026-01-02')],
                ['Key' => 'p/a.zip', 'LastModified' => new \DateTimeImmutable('2026-01-01')],
            ]]),
            new Result([]),
        );

        $deleted = (new CodeBucket($mocks->clients()->s3()))->prune('b', 'p/', 1, [], 'p/console-');

        self::assertSame(1, $deleted, 'console zips neither count towards the web zips kept nor get deleted');
        self::assertSame([['Key' => 'p/a.zip']], $mocks->params[1]['Delete']['Objects']);
    }

    public function test_a_runtime_binary_is_uploaded_once(): void
    {
        $file = (string) TempPaths::file('bin');
        file_put_contents($file, 'ELF');
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['ContentLength' => 3, 'VersionId' => 'bv1', 'ContentType' => 'binary/octet-stream', 'Metadata' => ['origin' => 'arco']]), // same size: kept
            new Result([]),                                                                 // CopyObject onto itself
            new Result(['ContentLength' => 2, 'VersionId' => 'bv0']),                       // other size: replaced
            new Result(['VersionId' => 'bv2']),
            AwsMocks::error(S3Exception::class, 'HeadObject', 'NotFound', 404),             // missing: uploaded
            new Result(['VersionId' => 'bv3']),
        );
        $bucket = new CodeBucket($mocks->clients()->s3());

        $bucket->uploadBinary('b', 'runtime/1.13.0-r1/frankenphp', $file, 3);
        $bucket->uploadBinary('b', 'runtime/1.13.0-r1/frankenphp', $file, 3);
        $bucket->uploadBinary('b', 'runtime/custom/abc/frankenphp', $file, 3);

        self::assertSame(['HeadObject', 'CopyObject', 'HeadObject', 'PutObject', 'HeadObject', 'PutObject'], $mocks->calls);
        self::assertSame([
            'Bucket' => 'b',
            'Key' => 'runtime/1.13.0-r1/frankenphp',
            'CopySource' => 'b/runtime/1.13.0-r1/frankenphp',
            'MetadataDirective' => 'REPLACE',
            'ContentType' => 'binary/octet-stream',
            'Metadata' => ['origin' => 'arco'],
        ], array_diff_key($mocks->params[1], ['@http' => 1, '@context' => 1]), 'a reused binary restarts its 24 h grace period');
        self::assertSame('runtime/custom/abc/frankenphp', $mocks->params[5]['Key']);
        unlink($file);
    }
}
