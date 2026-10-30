<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Resources;

use Multiapi\Arco\Resources\CertificateChecks;
use PHPUnit\Framework\TestCase;

final class CertificateChecksTest extends TestCase
{
    public function test_covers_exact_and_one_level_wildcards(): void
    {
        self::assertTrue(CertificateChecks::covers(['tiendas.dev.multierp.cl'], 'tiendas.dev.multierp.cl'));
        self::assertTrue(CertificateChecks::covers(['*.dev.multierp.cl'], 'tiendas.dev.multierp.cl'));
        self::assertFalse(CertificateChecks::covers(['*.dev.multierp.cl'], 'a.b.dev.multierp.cl'));
        self::assertFalse(CertificateChecks::covers(['*.dev.multierp.cl'], 'dev.multierp.cl'));
        self::assertFalse(CertificateChecks::covers([], 'dev.multierp.cl'));
    }

    public function test_warnings_for_a_healthy_certificate_are_empty(): void
    {
        $certificate = ['Status' => 'ISSUED', 'DomainName' => 'app.example.com', 'NotAfter' => 1_000_000 + 90 * 86400];
        self::assertSame([], CertificateChecks::warnings($certificate, ['app.example.com'], fn (): int => 1_000_000));
    }

    public function test_warnings_for_a_certificate_that_is_not_issued_expiring_and_not_covering(): void
    {
        $certificate = ['Status' => 'PENDING_VALIDATION', 'DomainName' => '*.other.com', 'NotAfter' => 1_000_000 + 10 * 86400 + 5];
        $warnings = CertificateChecks::warnings($certificate, ['app.example.com'], fn (): int => 1_000_000);

        self::assertSame([
            ['key' => 'resources.warning.certificate_not_issued', 'params' => ['status' => 'PENDING_VALIDATION']],
            ['key' => 'resources.warning.certificate_expiring', 'params' => ['days' => '10']],
            ['key' => 'resources.warning.certificate_not_covering', 'params' => ['domains' => 'app.example.com']],
        ], $warnings);
    }

    public function test_one_day_left_uses_the_singular_message(): void
    {
        $certificate = ['Status' => 'ISSUED', 'DomainName' => 'a.example.com', 'NotAfter' => 1_000_000 + 86400 + 100];
        self::assertSame(
            [['key' => 'resources.warning.certificate_expiring_one_day', 'params' => []]],
            CertificateChecks::warnings($certificate, ['a.example.com'], fn (): int => 1_000_000),
        );
    }
}
