<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy\Domains;

use Multiapi\Arco\Deploy\Domains\Certificate;
use Multiapi\Arco\Deploy\Domains\CertificateMatcher;
use PHPUnit\Framework\TestCase;

final class CertificateMatcherTest extends TestCase
{
    /**
     * @param list<string> $names
     * @param array<string, string> $tags
     */
    private static function cert(string $arn, array $names, string $status, array $tags = [], string $type = 'AMAZON_ISSUED', string $key = 'RSA_2048'): Certificate
    {
        return new Certificate($arn, $names, $status, $tags, [], false, $type, $key);
    }

    public function test_only_public_rsa_2048_or_p256_certificates_are_usable(): void
    {
        self::assertTrue(CertificateMatcher::usableByCloudFront('AMAZON_ISSUED', 'RSA-2048'), 'ACM answers with hyphens');
        self::assertTrue(CertificateMatcher::usableByCloudFront('IMPORTED', 'EC_prime256v1'));
        self::assertFalse(CertificateMatcher::usableByCloudFront('PRIVATE', 'RSA_2048'));
        self::assertFalse(CertificateMatcher::usableByCloudFront('AMAZON_ISSUED', 'EC_secp384r1'));
        self::assertFalse(CertificateMatcher::usableByCloudFront('AMAZON_ISSUED', 'RSA_4096'));
        self::assertFalse(CertificateMatcher::usableByCloudFront(null, 'RSA_2048'));

        $domains = ['a.example.com'];
        $private = self::cert('arn:private', ['a.example.com'], 'ISSUED', type: 'PRIVATE');
        $p384 = self::cert('arn:p384', ['a.example.com'], 'ISSUED', key: 'EC_secp384r1');
        $rsa = self::cert('arn:rsa', ['*.example.com', 'example.com', 'b.example.com'], 'ISSUED');
        self::assertSame('arn:rsa', CertificateMatcher::pick([$private, $p384, $rsa], $domains, 'shop', 'production')?->arn, 'fewer names does not beat an unusable key or type');
        self::assertNull(CertificateMatcher::pick([$private, $p384], $domains, 'shop', 'production'));
    }

    public function test_exact_names_and_one_level_wildcards_cover_a_domain(): void
    {
        self::assertTrue(CertificateMatcher::covers('arco.acmeapp.io', 'arco.acmeapp.io'));
        self::assertTrue(CertificateMatcher::covers('*.acmeapp.io', 'arco.acmeapp.io'));
        self::assertTrue(CertificateMatcher::covers('*.AcmeApp.io.', 'api-arco.acmeapp.io'));
        self::assertFalse(CertificateMatcher::covers('*.acmeapp.io', 'a.arco.acmeapp.io'), 'a wildcard covers exactly one label');
        self::assertFalse(CertificateMatcher::covers('*.acmeapp.io', 'acmeapp.io'));
        self::assertFalse(CertificateMatcher::covers('acmeapp.io', 'arco.acmeapp.io'));
        self::assertFalse(CertificateMatcher::covers('*.io', 'xacmeapp.io.evil'));
    }

    public function test_uncovered_lists_domains_no_name_covers(): void
    {
        self::assertSame(['b.other.cl'], CertificateMatcher::uncovered(['*.acmeapp.io'], ['a.acmeapp.io', 'b.other.cl']));
        self::assertSame([], CertificateMatcher::uncovered(['*.acmeapp.io', 'b.other.cl'], ['a.acmeapp.io', 'b.other.cl']));
    }

    public function test_picks_a_certificate_covering_all_domains(): void
    {
        $partial = self::cert('arn:partial', ['arco.acmeapp.io'], 'ISSUED');
        $wildcard = self::cert('arn:wildcard', ['acmeapp.io', '*.acmeapp.io'], 'ISSUED');

        $pick = CertificateMatcher::pick([$partial, $wildcard], ['arco.acmeapp.io', 'api-arco.acmeapp.io'], 'shop', 'production');

        self::assertSame('arn:wildcard', $pick?->arn);
        self::assertNull(CertificateMatcher::pick([$partial], ['arco.acmeapp.io', 'api-arco.acmeapp.io'], 'shop', 'production'));
    }

    public function test_prefers_this_environments_certificate_then_fewest_names(): void
    {
        $domains = ['a.example.com'];
        $big = self::cert('arn:big', ['a.example.com', 'b.example.com', 'c.example.com'], 'ISSUED');
        $small = self::cert('arn:small', ['*.example.com', 'example.com'], 'ISSUED');
        $ours = self::cert('arn:ours', ['a.example.com', 'b.example.com', 'c.example.com', 'd.example.com'], 'ISSUED', ['arco:app' => 'shop', 'arco:environment' => 'production']);
        $otherEnv = self::cert('arn:other', ['a.example.com'], 'ISSUED', ['arco:app' => 'shop', 'arco:environment' => 'staging']);

        self::assertSame('arn:small', CertificateMatcher::pick([$big, $small], $domains, 'shop', 'production')?->arn);
        self::assertSame('arn:ours', CertificateMatcher::pick([$big, $small, $ours], $domains, 'shop', 'production')?->arn);
        self::assertSame('arn:other', CertificateMatcher::pick([$big, $otherEnv], $domains, 'shop', 'production')?->arn, 'fewest names among untagged');
    }

    public function test_pending_certificates_are_only_resumed_when_arco_requested_them_for_this_environment(): void
    {
        $domains = ['a.example.com'];
        $tags = ['arco:app' => 'shop', 'arco:environment' => 'production', 'arco:managed' => 'true'];
        $foreignPending = self::cert('arn:foreign', ['a.example.com'], 'PENDING_VALIDATION');
        $ourPending = self::cert('arn:pending', ['a.example.com'], 'PENDING_VALIDATION', $tags);
        $issued = self::cert('arn:issued', ['a.example.com', 'b.example.com'], 'ISSUED');

        self::assertNull(CertificateMatcher::pick([$foreignPending], $domains, 'shop', 'production'));
        self::assertSame('arn:pending', CertificateMatcher::pick([$foreignPending, $ourPending], $domains, 'shop', 'production')?->arn);
        self::assertSame('arn:issued', CertificateMatcher::pick([$ourPending, $issued], $domains, 'shop', 'production')?->arn, 'issued wins over pending');
    }

    public function test_managed_requires_the_managed_tag_and_this_environment(): void
    {
        $managed = self::cert('arn:m', [], 'ISSUED', ['arco:app' => 'shop', 'arco:environment' => 'production', 'arco:managed' => 'true']);
        self::assertTrue($managed->managedFor('shop', 'production'));
        self::assertFalse($managed->managedFor('shop', 'staging'));
        self::assertFalse((self::cert('arn:t', [], 'ISSUED', ['arco:app' => 'shop', 'arco:environment' => 'production']))->managedFor('shop', 'production'));
    }

    public function test_idempotency_token_is_alphanumeric_and_changes_with_the_domains(): void
    {
        $token = CertificateMatcher::idempotencyToken('shop', 'production', ['a.example.com']);
        self::assertMatchesRegularExpression('/^[a-z0-9]{1,32}$/', $token);
        self::assertSame($token, CertificateMatcher::idempotencyToken('shop', 'production', ['a.example.com']));
        self::assertNotSame($token, CertificateMatcher::idempotencyToken('shop', 'production', ['a.example.com', 'b.example.com']));
        self::assertNotSame($token, CertificateMatcher::idempotencyToken('shop', 'staging', ['a.example.com']));
    }
}
