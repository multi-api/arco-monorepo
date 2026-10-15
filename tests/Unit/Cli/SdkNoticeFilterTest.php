<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Cli;

use Multiapi\Arco\Cli\SdkNoticeFilter;
use PHPUnit\Framework\TestCase;

final class SdkNoticeFilterTest extends TestCase
{
    public function test_hides_only_user_notices_from_the_sdk_credential_providers(): void
    {
        self::assertTrue(SdkNoticeFilter::handle(E_USER_NOTICE, 'expired', '/app/vendor/aws/aws-sdk-php/src/Credentials/CredentialProvider.php'));
        self::assertTrue(SdkNoticeFilter::handle(E_USER_NOTICE, 'expired', 'C:\\app\\vendor\\aws\\aws-sdk-php\\src\\Credentials\\CredentialProvider.php'));
        self::assertFalse(SdkNoticeFilter::handle(E_USER_WARNING, 'warn', '/app/vendor/aws/aws-sdk-php/src/Credentials/CredentialProvider.php'));
        self::assertFalse(SdkNoticeFilter::handle(E_USER_NOTICE, 'other', '/app/src/Thing.php'));
        self::assertSame(E_USER_NOTICE, SdkNoticeFilter::LEVELS);
    }
}
