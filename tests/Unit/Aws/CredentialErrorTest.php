<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Aws;

use Aws\Command;
use Aws\Exception\AwsException;
use Aws\Exception\CredentialsException;
use Multiapi\Arco\Aws\CredentialError;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CredentialErrorTest extends TestCase
{
    public function test_recognises_missing_or_expired_credentials(): void
    {
        self::assertTrue(CredentialError::matches(new CredentialsException('Token file at /x is expired')));
        foreach (['ExpiredToken', 'ExpiredTokenException', 'UnrecognizedClientException', 'InvalidClientTokenId'] as $code) {
            self::assertTrue(CredentialError::matches(new AwsException('boom', new Command('GetFunction'), ['code' => $code])), $code);
        }
        self::assertTrue(CredentialError::matches(new RuntimeException('wrapped', 0, new CredentialsException('expired'))));
        self::assertFalse(CredentialError::matches(new AwsException('no', new Command('GetFunction'), ['code' => 'AccessDeniedException'])));
        self::assertFalse(CredentialError::matches(new RuntimeException('boom')));
    }

    /** AssumeRoleCredentialProvider wraps every AssumeRole failure in a CredentialsException. */
    public function test_a_credentials_exception_wrapping_another_aws_error_is_not_an_expiry(): void
    {
        $denied = new AwsException('denied', new Command('AssumeRole'), ['code' => 'AccessDenied', 'message' => 'User is not authorized to perform sts:AssumeRole']);
        $wrapped = new CredentialsException('Error in retrieving assume role credentials.', 0, $denied);
        self::assertFalse(CredentialError::matches($wrapped));
        self::assertSame('User is not authorized to perform sts:AssumeRole', CredentialError::message($wrapped));

        $expired = new AwsException('expired', new Command('AssumeRole'), ['code' => 'ExpiredToken', 'message' => 'The security token included in the request is expired']);
        $wrapped = new CredentialsException('Error in retrieving assume role credentials.', 0, $expired);
        self::assertTrue(CredentialError::matches($wrapped));
        self::assertSame('The security token included in the request is expired', CredentialError::message($wrapped));

        self::assertSame('Token file at /x is expired', CredentialError::message(new CredentialsException('Token file at /x is expired')));
    }

    public function test_suggests_sso_login_only_for_sso_profiles(): void
    {
        $config = tempnam(sys_get_temp_dir(), 'arco-cfg');
        file_put_contents($config, "[profile acme-sso]\nsso_session = acme\nsso_account_id = 111111111111\n\n[profile keys]\nregion = us-east-1\n");
        $credentials = tempnam(sys_get_temp_dir(), 'arco-cred');
        file_put_contents($credentials, "[keys]\naws_access_key_id = AKIA\naws_secret_access_key = s\n");
        putenv("AWS_CONFIG_FILE=$config");
        putenv("AWS_SHARED_CREDENTIALS_FILE=$credentials");
        try {
            $plain = new CredentialsException('expired');
            self::assertSame('aws sso login --profile acme-sso', CredentialError::loginCommand($plain, 'acme-sso'));
            self::assertNull(CredentialError::loginCommand($plain, 'keys'));
            self::assertNull(CredentialError::loginCommand($plain, null));
            // The SDK's own SSO messages still give the hint, whatever the config says.
            self::assertSame('aws sso login --profile other', CredentialError::loginCommand(new CredentialsException('The SSO session associated with this profile has expired'), 'other'));
        } finally {
            putenv('AWS_CONFIG_FILE');
            putenv('AWS_SHARED_CREDENTIALS_FILE');
            unlink($config);
            unlink($credentials);
        }
    }
}
