<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Aws;

use Multiapi\Arco\Aws\Endpoint;
use Multiapi\Arco\Deploy\DeployResult;
use PHPUnit\Framework\TestCase;

final class EndpointTest extends TestCase
{
    private string|false $original;

    protected function setUp(): void
    {
        $this->original = getenv('AWS_ENDPOINT_URL');
        putenv('AWS_ENDPOINT_URL');
    }

    protected function tearDown(): void
    {
        putenv($this->original === false ? 'AWS_ENDPOINT_URL' : "AWS_ENDPOINT_URL=$this->original");
    }

    public function test_unset_means_real_aws(): void
    {
        self::assertNull(Endpoint::override());
        self::assertSame('https', Endpoint::scheme());
        self::assertSame('https://b.s3.us-east-1.amazonaws.com', Endpoint::bucketUrl('b', 'us-east-1'));
    }

    public function test_an_http_endpoint_switches_scheme_and_uses_path_style_buckets(): void
    {
        putenv('AWS_ENDPOINT_URL=http://localhost:4566');

        self::assertSame('http://localhost:4566', Endpoint::override());
        self::assertSame('http', Endpoint::scheme());
        self::assertSame('http://localhost:4566/b', Endpoint::bucketUrl('b', 'us-east-1'));
        self::assertSame('http://abc.on.aws', (new DeployResult('abc.on.aws', 'r', null, 'b', 'k'))->url());
    }

    public function test_an_https_endpoint_keeps_https_and_a_trailing_slash_is_dropped(): void
    {
        putenv('AWS_ENDPOINT_URL=https://aws.internal.example/');

        self::assertSame('https://aws.internal.example', Endpoint::override());
        self::assertSame('https', Endpoint::scheme());
        self::assertSame('https://aws.internal.example/b', Endpoint::bucketUrl('b', 'us-east-1'));
        self::assertSame('https://abc.on.aws', (new DeployResult('abc.on.aws', 'r', null, 'b', 'k'))->url());
    }

    public function test_a_malformed_value_is_treated_as_unset(): void
    {
        foreach (['not a url', 'localhost:4566', 'ftp://localhost', 'http://', 'http://host/path'] as $value) {
            putenv("AWS_ENDPOINT_URL=$value");
            self::assertNull(Endpoint::override(), $value);
            self::assertSame('https', Endpoint::scheme(), $value);
            self::assertSame('https://b.s3.us-east-1.amazonaws.com', Endpoint::bucketUrl('b', 'us-east-1'), $value);
        }
    }
}
