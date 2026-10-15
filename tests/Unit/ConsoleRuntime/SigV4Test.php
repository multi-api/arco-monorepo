<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\ConsoleRuntime;

use Multiapi\Arco\ConsoleRuntime\SigV4;
use PHPUnit\Framework\TestCase;

final class SigV4Test extends TestCase
{
    // Example from the AWS docs "Create a signed AWS API request" (IAM ListUsers), as in runtime/test/sigv4.test.js.
    public function test_matches_the_documented_example(): void
    {
        $headers = SigV4::sign(
            'GET', 'iam.amazonaws.com', '/', 'Action=ListUsers&Version=2010-05-08',
            ['content-type' => 'application/x-www-form-urlencoded; charset=utf-8'], '',
            ['accessKeyId' => 'AKIDEXAMPLE', 'secretAccessKey' => 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY', 'sessionToken' => null],
            'us-east-1', 'iam', new \DateTimeImmutable('2015-08-30T12:36:00Z'),
        );

        self::assertSame('20150830T123600Z', $headers['x-amz-date']);
        self::assertSame(
            'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/iam/aws4_request, '
            . 'SignedHeaders=content-type;host;x-amz-date, '
            . 'Signature=5d672d79c15b13162d9279b0855cfba6789a8edb4c82c400e06b5924a6f2b5d7',
            $headers['authorization'],
        );
    }

    public function test_adds_and_signs_the_session_token(): void
    {
        $headers = SigV4::sign('POST', 'ssm.us-east-1.amazonaws.com', '/', '', [], '{}', ['accessKeyId' => 'AK', 'secretAccessKey' => 'SK', 'sessionToken' => 'TOKEN'], 'us-east-1', 'ssm');

        self::assertSame('TOKEN', $headers['x-amz-security-token']);
        self::assertStringContainsString('SignedHeaders=host;x-amz-date;x-amz-security-token,', $headers['authorization']);
    }
}
