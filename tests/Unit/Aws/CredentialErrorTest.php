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
