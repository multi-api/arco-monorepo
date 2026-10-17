<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Config;

use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Config\StorageConfig;
use PHPUnit\Framework\TestCase;

final class StorageConfigTest extends TestCase
{
    private function config(): AppConfig
    {
        $raw = App::make('shop')->region('us-east-1')->environment('production', fn ($e) => $e->account('123456789012'))->toArray();
        return ConfigResolver::resolve($raw, 'production');
    }

    public function test_defaults(): void
    {
        $s = new StorageConfig('files');

        self::assertSame([[], [], [], null, false], [$s->public, $s->expire, $s->cors, $s->existing, $s->default]);
    }

    public function test_bucket_name_arn_and_url(): void
    {
        $s = new StorageConfig('files');
        $c = $this->config();

        self::assertSame('shop-production-files-123456789012', $s->bucketName($c));
        self::assertSame('arn:aws:s3:::shop-production-files-123456789012', $s->arn($c));
        self::assertSame('https://shop-production-files-123456789012.s3.us-east-1.amazonaws.com', $s->url($c));
    }

    public function test_existing_bucket_is_used_as_is(): void
    {
        $s = new StorageConfig('files', existing: 'legacy.bucket');
        $c = $this->config();

        self::assertSame('legacy.bucket', $s->bucketName($c));
        self::assertSame('arn:aws:s3:::legacy.bucket', $s->arn($c));
        self::assertSame('https://legacy.bucket.s3.us-east-1.amazonaws.com', $s->url($c));
    }

    public function test_bucket_name_rules(): void
    {
        foreach (['abc', 'my-bucket.1', str_repeat('a', 63)] as $name) {
            self::assertTrue(StorageConfig::isBucketName($name), $name);
        }
        foreach (['ab', str_repeat('a', 64), 'Bucket', 'a_b', '-ab', 'ab-', 'a..b', '192.168.1.1', 'xn--abc', 'abc-s3alias', 'a b'] as $name) {
            self::assertFalse(StorageConfig::isBucketName($name), $name);
        }
    }
}
