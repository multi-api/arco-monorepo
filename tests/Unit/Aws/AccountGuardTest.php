<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Aws;

use Aws\Exception\CredentialsException;
use Multiapi\Arco\Aws\AccountGuard;
use Multiapi\Arco\Aws\AccountMismatch;
use Multiapi\Arco\Aws\SsoHint;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AccountGuardTest extends TestCase
{
    public function test_passes_when_unlocked_or_matching(): void
    {
        AccountGuard::check(null, '111111111111', 'p');
        AccountGuard::check('111111111111', '111111111111', 'p');
        $this->addToAssertionCount(2);
    }

    public function test_throws_on_mismatch(): void
    {
        try {
            AccountGuard::check('222222222222', '111111111111', 'default');
            self::fail('Expected AccountMismatch');
        } catch (AccountMismatch $e) {
            self::assertSame(['222222222222', '111111111111', 'default'], [$e->expected, $e->actual, $e->profile]);
        }
    }

    public function test_sso_hint(): void
    {
        $expired = new CredentialsException('Error retrieving credentials from SSO: The SSO session associated with this profile has expired');
        self::assertSame('aws sso login --profile acme-prod', SsoHint::command($expired, 'acme-prod'));
        self::assertNull(SsoHint::command(new RuntimeException('boom'), 'x'));
        self::assertNull(SsoHint::command($expired, null));
    }
}
