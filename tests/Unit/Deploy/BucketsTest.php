<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy;

use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Multiapi\Arco\Deploy\Buckets;
use PHPUnit\Framework\TestCase;

final class BucketsTest extends TestCase
{
    private function buckets(AwsMocks $mocks): Buckets
    {
        return new Buckets($mocks->clients()->s3());
    }

    public function test_a_bucket_this_account_can_read_is_ours(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(new Result([]));

        self::assertSame('ours', $this->buckets($mocks)->exists('shop-production-uploads-111111111111'));
        self::assertSame(['HeadBucket'], $mocks->calls);
        self::assertSame('shop-production-uploads-111111111111', $mocks->params[0]['Bucket']);
    }

    public function test_a_forbidden_bucket_is_taken(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(AwsMocks::error(S3Exception::class, 'HeadBucket', 'Forbidden', 403));

        self::assertSame('taken', $this->buckets($mocks)->exists('shop-production-uploads-111111111111'));
    }

    public function test_a_bucket_in_another_region_is_taken(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(AwsMocks::error(S3Exception::class, 'HeadBucket', 'PermanentRedirect', 301));

        self::assertSame('taken', $this->buckets($mocks)->exists('shop-production-uploads-111111111111'));
    }

    public function test_a_bucket_answering_for_another_region_is_taken(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(AwsMocks::error(S3Exception::class, 'HeadBucket', 'AuthorizationHeaderMalformed', 400));

        self::assertSame('taken', $this->buckets($mocks)->exists('shop-production-uploads-111111111111'));
    }

    public function test_a_missing_bucket_is_free(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(AwsMocks::error(S3Exception::class, 'HeadBucket', 'NotFound', 404));

        self::assertSame('free', $this->buckets($mocks)->exists('shop-production-uploads-111111111111'));
    }

    public function test_other_errors_are_not_guessed(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(AwsMocks::error(S3Exception::class, 'HeadBucket', 'MethodNotAllowed', 405));

        $this->expectException(S3Exception::class);
        $this->buckets($mocks)->exists('shop-production-uploads-111111111111');
    }

    public function test_has_objects(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(new Result(['KeyCount' => 1]), new Result(['KeyCount' => 0]), AwsMocks::error(S3Exception::class, 'ListObjectsV2', 'AccessDenied', 403));
        $buckets = $this->buckets($mocks);

        self::assertTrue($buckets->hasObjects('a-bucket'));
        self::assertFalse($buckets->hasObjects('a-bucket'));
        self::assertNull($buckets->hasObjects('a-bucket'), 'unreadable: unknown');
        self::assertSame(['ListObjectsV2', 'ListObjectsV2', 'ListObjectsV2'], $mocks->calls);
        self::assertSame(['Bucket' => 'a-bucket', 'MaxKeys' => 1], array_intersect_key($mocks->params[0], ['Bucket' => 1, 'MaxKeys' => 1]));
    }
}
