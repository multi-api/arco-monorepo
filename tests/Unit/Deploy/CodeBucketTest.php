<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy;

use Aws\CommandInterface;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Multiapi\Arco\Deploy\CodeBucket;
use Multiapi\Arco\Deploy\DeployException;
use Multiapi\Arco\Deploy\PartUploadException;
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
        // 'private' is rejected by S3-compatible emulators (Floci); this ACL is valid on AWS too.
        self::assertSame('bucket-owner-full-control', $mocks->params[1]['ACL']);
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

    public function test_a_part_that_fails_on_the_network_is_retried_once_resuming_the_upload(): void
    {
        [$mocks, $file, $failures] = self::multipart(['2' => 1]);
        $sleeper = AwsMocks::noSleep();
        $steps = [];

        $version = (new CodeBucket($mocks->clients(overrides: ['retries' => 0])->s3(), $sleeper))
            ->upload('b', 'shop/prod/h.zip', $file, function (string $key, array $params) use (&$steps): void { $steps[] = [$key, $params]; });

        self::assertSame('v9', $version);
        self::assertSame(['HeadObject', 'CreateMultipartUpload'], array_slice($mocks->calls, 0, 2));
        self::assertSame(1, count(array_keys($mocks->calls, 'CreateMultipartUpload', true)), 'the same upload is resumed');
        self::assertSame([1, 2, 2, 3, 4], self::sortedParts($mocks));
        self::assertSame('CompleteMultipartUpload', end($mocks->calls));
        self::assertNotContains('AbortMultipartUpload', $mocks->calls);
        self::assertSame(CodeBucket::RETRY_DELAY, $sleeper->slept);
        self::assertSame([['deploy.upload_retry', ['key' => 'shop/prod/h.zip', 'error' => 'part 2: cURL error 56: Recv failure: Connection reset by peer', 'seconds' => CodeBucket::RETRY_DELAY]]], $steps);
        self::assertSame(1, $failures->count);
        unlink($file);
    }

    public function test_a_part_that_fails_twice_fails_the_upload_and_aborts_it(): void
    {
        [$mocks, $file] = self::multipart(['3' => 2]);
        $steps = 0;
        try {
            (new CodeBucket($mocks->clients(overrides: ['retries' => 0])->s3(), AwsMocks::noSleep()))
                ->upload('b', 'k.zip', $file, function () use (&$steps): void { $steps++; });
            self::fail('expected the upload to fail');
        } catch (PartUploadException $e) {
            self::assertStringContainsString('Part 3: cURL error 56', $e->getMessage());
        }
        self::assertSame(1, $steps, 'one retry only');
        self::assertSame('AbortMultipartUpload', end($mocks->calls));
        self::assertSame('u1', $mocks->params[array_key_last($mocks->params)]['UploadId']);
        unlink($file);
    }

    public function test_a_part_rejected_by_s3_is_not_retried_and_the_upload_is_aborted(): void
    {
        [$mocks, $file] = self::multipart(['1' => 9], denied: true);
        $sleeper = AwsMocks::noSleep();
        try {
            (new CodeBucket($mocks->clients(overrides: ['retries' => 0])->s3(), $sleeper))->upload('b', 'k.zip', $file, fn () => self::fail('no retry'));
            self::fail('expected the upload to fail');
        } catch (PartUploadException) {
        }
        self::assertSame(0, $sleeper->slept);
        self::assertSame('AbortMultipartUpload', end($mocks->calls));
        unlink($file);
    }

    public function test_a_small_package_is_put_again_after_a_server_error(): void
    {
        $file = (string) TempPaths::file('pkg');
        file_put_contents($file, 'zip');
        $mocks = new AwsMocks();
        $mocks->handler->append(
            AwsMocks::error(S3Exception::class, 'HeadObject', 'NotFound', 404),
            AwsMocks::error(S3Exception::class, 'PutObject', 'ServiceUnavailable', 503, 'Please reduce your request rate.'),
            new Result(['VersionId' => 'v2']),
        );
        $steps = [];

        $version = (new CodeBucket($mocks->clients(overrides: ['retries' => 0])->s3(), AwsMocks::noSleep()))
            ->upload('b', 'k.zip', $file, function (string $key, array $params) use (&$steps): void { $steps[] = $params['error']; });

        self::assertSame('v2', $version);
        self::assertSame(['HeadObject', 'PutObject', 'PutObject'], $mocks->calls);
        self::assertSame(['Please reduce your request rate.'], $steps);
        unlink($file);
    }

    /**
     * A 17 MB package (four 5 MB parts) whose parts fail as listed: part number => failed attempts.
     *
     * @param array<string, int> $fail
     * @return array{AwsMocks, string, object{count: int}}
     */
    private static function multipart(array $fail, bool $denied = false): array
    {
        $file = (string) TempPaths::file('pkg');
        file_put_contents($file, str_repeat('a', 17 * 1024 * 1024));
        $failures = new class { public int $count = 0; };
        $attempts = [];
        $respond = function (CommandInterface $command) use ($fail, $denied, $failures, &$attempts) {
            return match ($command->getName()) {
                'CreateMultipartUpload' => new Result(['UploadId' => 'u1']),
                'UploadPart' => (function () use ($command, $fail, $denied, $failures, &$attempts) {
                    $part = (string) $command['PartNumber'];
                    $attempts[$part] = ($attempts[$part] ?? 0) + 1;
                    if ($attempts[$part] <= ($fail[$part] ?? 0)) {
                        $failures->count++;
                        return $denied
                            ? new S3Exception('Access Denied', $command, ['code' => 'AccessDenied', 'message' => 'Access Denied', 'response' => new \GuzzleHttp\Psr7\Response(403)])
                            : new S3Exception('cURL error 56: Recv failure: Connection reset by peer', $command, ['connection_error' => true]);
                    }
                    return new Result(['ETag' => '"e' . $part . '"']);
                })(),
                'CompleteMultipartUpload' => new Result(['VersionId' => 'v9']),
                default => new Result([]),
            };
        };
        $mocks = new AwsMocks();
        $mocks->handler->append(AwsMocks::error(S3Exception::class, 'HeadObject', 'NotFound', 404), ...array_fill(0, 20, $respond));
        return [$mocks, $file, $failures];
    }

    /** @return list<int> */
    private static function sortedParts(AwsMocks $mocks): array
    {
        $parts = [];
        foreach ($mocks->calls as $i => $call) {
            if ($call === 'UploadPart') {
                $parts[] = (int) $mocks->params[$i]['PartNumber'];
            }
        }
        sort($parts);
        return $parts;
    }
}
