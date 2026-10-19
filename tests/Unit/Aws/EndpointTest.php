<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Aws;

use Multiapi\Arco\Aws\Endpoint;
use Multiapi\Arco\Deploy\DeployResult;
use PHPUnit\Framework\TestCase;

final class EndpointTest extends TestCase
{
    private const VARIABLES = ['AWS_ENDPOINT_URL', 'AWS_IGNORE_CONFIGURED_ENDPOINT_URLS'];

    /** @var array<string, string|false> */
    private array $original = [];

    protected function setUp(): void
    {
        foreach (self::VARIABLES as $name) {
            $this->original[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->original as $name => $value) {
            putenv($value === false ? $name : "$name=$value");
        }
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

    public function test_a_malformed_value_is_reported_as_unrecognised(): void
    {
        self::assertNull(Endpoint::unrecognised());
        putenv('AWS_ENDPOINT_URL=');
        self::assertNull(Endpoint::unrecognised());
        putenv('AWS_ENDPOINT_URL=http://localhost:4566');
        self::assertNull(Endpoint::unrecognised());
        putenv('AWS_ENDPOINT_URL=localhost:4566');
        self::assertSame('localhost:4566', Endpoint::unrecognised());
    }

    public function test_the_sdk_ignore_flag_turns_the_override_off_as_it_does_for_the_sdk(): void
    {
        putenv('AWS_ENDPOINT_URL=http://localhost:4566');
        foreach (['true', 'TRUE', 'True', 'on', '1'] as $flag) {
            putenv("AWS_IGNORE_CONFIGURED_ENDPOINT_URLS=$flag");
            self::assertNull(Endpoint::override(), $flag);
            self::assertSame('https', Endpoint::scheme(), $flag);
            self::assertSame('https://b.s3.us-east-1.amazonaws.com', Endpoint::bucketUrl('b', 'us-east-1'), $flag);
        }
        foreach (['false', '0', 'off', ''] as $flag) {
            putenv("AWS_IGNORE_CONFIGURED_ENDPOINT_URLS=$flag");
            self::assertSame('http://localhost:4566', Endpoint::override(), $flag);
        }

        putenv('AWS_ENDPOINT_URL=localhost:4566');
        putenv('AWS_IGNORE_CONFIGURED_ENDPOINT_URLS=true');
        self::assertNull(Endpoint::unrecognised());
    }
}
