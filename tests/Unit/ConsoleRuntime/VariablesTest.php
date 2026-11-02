<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\ConsoleRuntime;

use Multiapi\Arco\ConsoleRuntime\Variables;
use Multiapi\Arco\Tests\Support\S3Emulator;
use Multiapi\Arco\Tests\Support\TempPaths;
use PHPUnit\Framework\TestCase;

/** The console and worker runtimes' half of bundleVariables(): runtime/console/Variables.php against a local S3 emulator. */
final class VariablesTest extends TestCase
{
    private const BUCKET = 'arco-111111111111-us-east-1';
    private const CREDENTIALS = ['AWS_ACCESS_KEY_ID' => 'AK', 'AWS_SECRET_ACCESS_KEY' => 'SK', 'AWS_SESSION_TOKEN' => 'T'];
    private S3Emulator $s3;

    protected function setUp(): void
    {
        $this->s3 = new S3Emulator();
    }

    protected function tearDown(): void
    {
        $this->s3->stop();
    }

    /** Puts $body under its checksum key, as arco deploy does; returns the env with the pointer. @return array<string, string> */
    private function bundle(string $body, ?string $key = null): array
    {
        $key ??= 'variables/shop/staging/' . hash('sha256', $body) . '.json';
        $file = (string) TempPaths::file('bundle');
        file_put_contents($file, $body);
        $this->s3->put(self::BUCKET, $key, $file);
        return [...self::CREDENTIALS, 'ARCO_S3_ENDPOINT' => "http://127.0.0.1:{$this->s3->port}", 'ARCO_VARIABLES' => 's3://' . self::BUCKET . "/$key"];
    }

    public function test_without_a_pointer_nothing_is_read(): void
    {
        self::assertSame([], (new Variables('us-east-1'))->load(['APP_NAME' => 'Shop']));
        self::assertSame([], $this->s3->requests());
    }

    public function test_loads_the_bundle_without_overriding_the_real_environment(): void
    {
        $body = json_encode(['schema' => 1, 'variables' => ['APP_KEY' => 'arco-ssm:/shop/staging/app-key', 'AWS_SESSION_TOKEN' => 'stolen', 'LINE' => "a\nb ñ"]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $env = $this->bundle($body);

        $loaded = (new Variables('us-east-1'))->load($env);

        self::assertSame(['APP_KEY' => 'arco-ssm:/shop/staging/app-key', 'LINE' => "a\nb ñ"], $loaded);
        $request = $this->s3->requests()[0];
        self::assertSame('/' . self::BUCKET . '/variables/shop/staging/' . hash('sha256', $body) . '.json', $request['path']);
        self::assertStringStartsWith('AWS4-HMAC-SHA256 Credential=AK/', $request['authorization']);
        self::assertStringContainsString('/us-east-1/s3/aws4_request', $request['authorization']);
        self::assertSame('T', $request['token']);
    }

    public function test_a_body_that_does_not_match_its_key_is_refused(): void
    {
        $env = $this->bundle('{"schema":1,"variables":{"A":"2"}}', 'variables/shop/staging/' . hash('sha256', '{"schema":1,"variables":{"A":"1"}}') . '.json');

        $this->expectExceptionMessageMatches('/does not match its checksum/');
        (new Variables('us-east-1'))->load($env);
    }

    public function test_something_else_than_a_bundle_is_refused(): void
    {
        foreach (['{"schema":2,"variables":{}}', '{"schema":1,"variables":{"A":1}}', '{"schema":1,"variables":["A"]}', 'not json'] as $body) {
            try {
                (new Variables('us-east-1'))->load($this->bundle($body));
                self::fail("$body was accepted");
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('is not a variable bundle', $e->getMessage());
            }
        }
    }

    public function test_a_missing_object_or_a_bad_pointer_fails_with_what_to_check(): void
    {
        $env = [...self::CREDENTIALS, 'ARCO_S3_ENDPOINT' => "http://127.0.0.1:{$this->s3->port}", 'ARCO_VARIABLES' => 's3://' . self::BUCKET . '/variables/shop/staging/nope.json'];
        try {
            (new Variables('us-east-1'))->load($env);
            self::fail('a missing bundle was accepted');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('HTTP 404', $e->getMessage());
            self::assertStringContainsString('s3:GetObject', $e->getMessage());
        }

        $this->expectExceptionMessageMatches('/ARCO_VARIABLES must be s3:/');
        (new Variables('us-east-1'))->load(['ARCO_VARIABLES' => 'https://example.com/x']);
    }

    public function test_on_aws_the_request_goes_to_the_regional_virtual_hosted_endpoint(): void
    {
        $urls = [];
        $variables = new Variables('eu-west-1', function (string $url, array $headers) use (&$urls): array {
            $urls[] = [$url, $headers['host'], $headers['x-amz-content-sha256']];
            return ['status' => 200, 'body' => '{"schema":1,"variables":{}}'];
        });

        $variables->load([...self::CREDENTIALS, 'ARCO_VARIABLES' => 's3://b/variables/x/y/' . hash('sha256', '{"schema":1,"variables":{}}') . '.json']);

        self::assertSame([[
            'https://b.s3.eu-west-1.amazonaws.com/variables/x/y/' . hash('sha256', '{"schema":1,"variables":{}}') . '.json',
            'b.s3.eu-west-1.amazonaws.com',
            hash('sha256', ''),
        ]], $urls);
    }
}
