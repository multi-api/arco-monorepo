<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Binary;

use Multiapi\Arco\Binary\BinaryException;
use Multiapi\Arco\Binary\RuntimeManifest;
use PHPUnit\Framework\TestCase;

final class RuntimeManifestTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function entry(string $frankenphp, string $php = '8.5.11', ?string $sha = null): array
    {
        return [
            'url' => 'https://github.com/multi-api/arco-runtime/releases/download/x/frankenphp-linux-aarch64',
            'sha256' => $sha ?? str_repeat('a', 64),
            'size' => 100,
            'frankenphp' => $frankenphp,
            'php' => $php,
        ];
    }

    private static function manifest(): RuntimeManifest
    {
        return RuntimeManifest::fromArray([
            'default_php' => '8.5',
            'builds' => [
                'php8.5.11-fp1.12.7-r1' => self::entry('1.12.7'),
                'php8.5.11-fp1.13.0-r2' => self::entry('1.13.0'),
                'php8.5.11-fp1.13.0-r10' => self::entry('1.13.0'),
                'php8.5.11-fp1.13.0-r1' => self::entry('1.13.0'),
                'php8.6.0-fp1.14.0-r1' => self::entry('1.14.0', '8.6.0'),
            ],
            'aliases' => ['1.13.0-r1' => 'php8.5.11-fp1.13.0-r1'],
        ]);
    }

    public function test_resolution_order(): void
    {
        $m = self::manifest();
        // The default is the newest build of the default PHP minor, never another minor (owner decision 2).
        self::assertSame('8.5', $m->defaultPhp());
        self::assertSame('php8.5.11-fp1.13.0-r10', $m->resolve(null, null)->id);
        self::assertSame('php8.5.11-fp1.13.0-r1', $m->resolve('php8.5.11-fp1.13.0-r1', null)->id);
        // Review focus 1: numeric revision order, r10 > r2.
        self::assertSame('php8.5.11-fp1.13.0-r10', $m->resolve(null, '1.13.0')->id);
        self::assertSame(10, $m->get('php8.5.11-fp1.13.0-r10')->revision());
        self::assertSame(
            ['php8.5.11-fp1.12.7-r1', 'php8.5.11-fp1.13.0-r1', 'php8.5.11-fp1.13.0-r2', 'php8.5.11-fp1.13.0-r10', 'php8.6.0-fp1.14.0-r1'],
            $m->ids(),
        );
        self::assertSame(['8.5', '8.6'], $m->phpMinors());
    }

    public function test_php_selects_the_newest_build_of_that_minor(): void
    {
        $m = self::manifest();
        self::assertSame('php8.5.11-fp1.13.0-r10', $m->resolve(null, null, '8.5')->id);
        self::assertSame('php8.6.0-fp1.14.0-r1', $m->resolve(null, null, '8.6')->id);
        // frankenphp() is a prefix on whole version parts, and combines with php().
        self::assertSame('php8.5.11-fp1.12.7-r1', $m->resolve(null, '1.12', '8.5')->id);
        self::assertSame('php8.5.11-fp1.13.0-r10', $m->resolve(null, '1.13', null)->id);
        self::assertSame('php8.6.0-fp1.14.0-r1', $m->resolve(null, '1', '8.6')->id);
    }

    public function test_newest_compares_php_first_then_frankenphp_then_revision(): void
    {
        $m = RuntimeManifest::fromArray(['default_php' => '8.5', 'builds' => [
            'php8.5.12-fp1.13.0-r1' => self::entry('1.13.0', '8.5.12'),
            'php8.5.11-fp1.14.0-r3' => self::entry('1.14.0'),
            'php8.5.9-fp1.14.1-r1' => self::entry('1.14.1', '8.5.9'),
        ]]);
        self::assertSame('php8.5.12-fp1.13.0-r1', $m->resolve(null, null)->id);
        self::assertSame('php8.5.11-fp1.14.0-r3', $m->resolve(null, '1.14.0')->id);
    }

    public function test_frankenphp_alone_stays_on_the_default_php_minor(): void
    {
        // A new PHP minor is opt-in via php(), so frankenphp('1.14') alone does not reach PHP 8.6.
        try {
            self::manifest()->resolve(null, '1.14');
            self::fail('Expected binary.unknown_version');
        } catch (BinaryException $e) {
            self::assertSame('binary.unknown_version', $e->key);
            self::assertSame('8.5', $e->params['php']);
        }
    }

    public function test_legacy_ids_are_aliases_of_the_same_build(): void
    {
        $m = self::manifest();
        $alias = $m->resolve('1.13.0-r1', null);
        self::assertSame('php8.5.11-fp1.13.0-r1', $alias->id);
        self::assertSame($m->get('php8.5.11-fp1.13.0-r1'), $m->get('1.13.0-r1'));
        self::assertSame('php8.5.11-fp1.13.0-r1', $m->canonical('1.13.0-r1'));
        self::assertSame('php8.5.11-fp1.13.0-r1', $m->canonical('php8.5.11-fp1.13.0-r1'));
        self::assertSame('9.9.9-r9', $m->canonical('9.9.9-r9'));
    }

    public function test_errors_name_the_known_builds(): void
    {
        $m = self::manifest();
        foreach ([
            [fn () => $m->resolve('9.9.9-r1', null), 'binary.unknown_build', 'builds', 'php8.5.11-fp1.13.0-r10'],
            [fn () => $m->resolve(null, '1.11.0'), 'binary.unknown_version', 'builds', 'php8.5.11-fp1.13.0-r10'],
            [fn () => $m->resolve(null, '1.1'), 'binary.unknown_version', 'builds', 'php8.5.11-fp1.13.0-r10'],
            [fn () => $m->resolve(null, null, '8.4'), 'binary.unknown_php', 'minors', '8.5, 8.6'],
            [fn () => $m->resolve(null, null, '8.5.11'), 'binary.invalid_php', 'minors', '8.5, 8.6'],
            [fn () => $m->resolve('1.13.0-r1', '1.13.0'), 'binary.build_and_version', null, null],
            [fn () => $m->resolve('1.13.0-r1', null, '8.5'), 'binary.build_and_version', null, null],
        ] as [$call, $key, $param, $contains]) {
            try {
                $call();
                self::fail("Expected $key");
            } catch (BinaryException $e) {
                self::assertSame($key, $e->key);
                if ($param !== null) {
                    self::assertStringContainsString((string) $contains, (string) $e->params[$param]);
                }
            }
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidManifests(): iterable
    {
        $id = 'php8.5.11-fp1.13.0-r1';
        yield 'no build of the default php minor' => [['default_php' => '8.4', 'builds' => [$id => self::entry('1.13.0')]]];
        yield 'missing default php' => [['builds' => [$id => self::entry('1.13.0')]]];
        yield 'default php is a patch' => [['default_php' => '8.5.11', 'builds' => [$id => self::entry('1.13.0')]]];
        yield 'legacy id as a build key' => [['default_php' => '8.5', 'builds' => ['1.13.0-r1' => self::entry('1.13.0')]]];
        yield 'bad id' => [['default_php' => '8.5', 'builds' => ['1.13.0' => self::entry('1.13.0')]]];
        yield 'id/frankenphp mismatch' => [['default_php' => '8.5', 'builds' => [$id => self::entry('1.12.0')]]];
        yield 'id/php mismatch' => [['default_php' => '8.5', 'builds' => [$id => self::entry('1.13.0', '8.5.12')]]];
        yield 'bad sha' => [['default_php' => '8.5', 'builds' => [$id => self::entry('1.13.0', '8.5.11', 'xyz')]]];
        yield 'http url' => [['default_php' => '8.5', 'builds' => [$id => ['url' => 'http://x/y'] + self::entry('1.13.0')]]];
        yield 'sha with trailing newline' => [['default_php' => '8.5', 'builds' => [$id => self::entry('1.13.0', '8.5.11', str_repeat('a', 64) . "\n")]]];
        yield 'zero size' => [['default_php' => '8.5', 'builds' => [$id => ['size' => 0] + self::entry('1.13.0')]]];
        yield 'no builds' => [['default_php' => '8.5', 'builds' => []]];
        yield 'alias to an unknown build' => [['default_php' => '8.5', 'builds' => [$id => self::entry('1.13.0')], 'aliases' => ['1.13.0-r2' => 'php8.5.11-fp1.13.0-r2']]];
        yield 'alias that is a build id' => [['default_php' => '8.5', 'builds' => [$id => self::entry('1.13.0')], 'aliases' => [$id => $id]]];
        yield 'aliases not a map' => [['default_php' => '8.5', 'builds' => [$id => self::entry('1.13.0')], 'aliases' => 'x']];
    }

    /** @param array<string, mixed> $data */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidManifests')]
    public function test_rejects_invalid_manifests(array $data): void
    {
        try {
            RuntimeManifest::fromArray($data);
            self::fail('Expected binary.manifest_invalid');
        } catch (BinaryException $e) {
            self::assertSame('binary.manifest_invalid', $e->key);
        }
    }

    public function test_a_build_may_carry_a_php_cli(): void
    {
        $manifest = RuntimeManifest::fromArray(['default_php' => '8.5', 'builds' => [
            'php8.5.11-fp1.13.0-r2' => [
                'url' => 'https://example.test/frankenphp', 'sha256' => str_repeat('a', 64), 'size' => 10,
                'frankenphp' => '1.13.0', 'php' => '8.5.11',
                'php_cli' => ['url' => 'https://example.test/php', 'sha256' => str_repeat('b', 64), 'size' => 5],
            ],
        ]]);

        $cli = $manifest->get('php8.5.11-fp1.13.0-r2')->phpCli;
        self::assertNotNull($cli);
        self::assertSame(['https://example.test/php', str_repeat('b', 64), 5], [$cli->url, $cli->sha256, $cli->size]);
    }

    public function test_a_build_without_php_cli_has_none(): void
    {
        $manifest = RuntimeManifest::fromArray(['default_php' => '8.5', 'builds' => [
            'php8.5.11-fp1.13.0-r1' => ['url' => 'https://example.test/f', 'sha256' => str_repeat('a', 64), 'size' => 10, 'frankenphp' => '1.13.0', 'php' => '8.5.11'],
        ]]);

        self::assertNull($manifest->get('php8.5.11-fp1.13.0-r1')->phpCli);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidPhpClis(): iterable
    {
        $ok = ['url' => 'https://example.test/php', 'sha256' => str_repeat('b', 64), 'size' => 5];
        yield 'http url' => [['url' => 'http://insecure/php'] + $ok];
        yield 'uppercase sha' => [['sha256' => str_repeat('B', 64)] + $ok];
        yield 'short sha' => [['sha256' => str_repeat('b', 63)] + $ok];
        yield 'sha with trailing newline' => [['sha256' => str_repeat('b', 64) . "\n"] + $ok];
        yield 'zero size' => [['size' => 0] + $ok];
        yield 'non-int size' => [['size' => '5'] + $ok];
        yield 'not an array' => ['https://example.test/php'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidPhpClis')]
    public function test_an_invalid_php_cli_is_refused(mixed $phpCli): void
    {
        try {
            RuntimeManifest::fromArray(['default_php' => '8.5', 'builds' => [
                'php8.5.11-fp1.13.0-r2' => [
                    'url' => 'https://example.test/f', 'sha256' => str_repeat('a', 64), 'size' => 10, 'frankenphp' => '1.13.0', 'php' => '8.5.11',
                    'php_cli' => $phpCli,
                ],
            ]]);
            self::fail('Expected binary.manifest_invalid');
        } catch (BinaryException $e) {
            self::assertSame('binary.manifest_invalid', $e->key);
        }
    }

    public function test_shipped_manifest_is_valid_and_pins_the_default_build_with_its_php_cli(): void
    {
        $manifest = RuntimeManifest::load();
        self::assertSame('8.5', $manifest->defaultPhp());
        $build = $manifest->resolve(null, null);
        self::assertSame('php8.5.12-fp1.13.1-r1', $build->id);
        self::assertSame($build, $manifest->resolve(null, null, '8.5'));
        self::assertSame('9cb3a31d10c7e8bf2448d599937bda889bdff8d3934011ee1b6a40e4fab5c5a5', $build->sha256);
        self::assertSame(168138896, $build->size);
        self::assertSame('https://github.com/multi-api/arco-runtime/releases/download/frankenphp-1.13.1-r1/frankenphp-linux-aarch64', $build->url);
        self::assertNotNull($build->phpCli);
        self::assertSame(
            ['https://github.com/multi-api/arco-runtime/releases/download/frankenphp-1.13.1-r1/php-linux-aarch64', '5a0fb99033ecf48b07f368b11b5fad7a3c41cc030e700b980999ea8f1b277e49', 95099992],
            [$build->phpCli->url, $build->phpCli->sha256, $build->phpCli->size],
        );

        // The first build stays pinnable, and has no PHP CLI (its console downloads FrankenPHP).
        $first = $manifest->get('php8.5.11-fp1.13.0-r1');
        self::assertSame('605ff4ae0ae4b8273026d92ee125405b8a589234a196e930f05eff067e9f2ca5', $first->sha256);
        self::assertSame('https://github.com/multi-api/arco-runtime/releases/download/frankenphp-1.13.0-r1/frankenphp-linux-aarch64', $first->url);
        self::assertNull($first->phpCli);
    }

    /** Review focus 2: an arco.php pinning a pre-0.9 id keeps the exact same binaries, forever. */
    public function test_shipped_legacy_ids_resolve_to_the_identical_binaries(): void
    {
        $manifest = RuntimeManifest::load();
        self::assertSame(['1.13.0-r1' => 'php8.5.11-fp1.13.0-r1', '1.13.0-r2' => 'php8.5.11-fp1.13.0-r2'], $manifest->aliases());

        $r2 = $manifest->resolve('1.13.0-r2', null);
        self::assertSame(
            [
                'https://github.com/multi-api/arco-runtime/releases/download/frankenphp-1.13.0-r2/frankenphp-linux-aarch64',
                '590762f2aea6b66cdc1d18b262a21ee1b0b938953a617598b8c970b453f2b1f1',
                168140016,
                'https://github.com/multi-api/arco-runtime/releases/download/frankenphp-1.13.0-r2/php-linux-aarch64',
                '408921e0132b1874980b5443dcefbd7bfacc52c4cb12864da04e76664d647e76',
                95099864,
            ],
            [$r2->url, $r2->sha256, $r2->size, $r2->phpCli?->url, $r2->phpCli?->sha256, $r2->phpCli?->size],
        );
        $r1 = $manifest->resolve('1.13.0-r1', null);
        self::assertSame(
            ['https://github.com/multi-api/arco-runtime/releases/download/frankenphp-1.13.0-r1/frankenphp-linux-aarch64', '605ff4ae0ae4b8273026d92ee125405b8a589234a196e930f05eff067e9f2ca5', 168140016, null],
            [$r1->url, $r1->sha256, $r1->size, $r1->phpCli],
        );
    }
}
