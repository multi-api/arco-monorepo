<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Binary;

use Multiapi\Arco\Binary\BinaryException;
use Multiapi\Arco\Binary\RuntimeManifest;
use PHPUnit\Framework\TestCase;

final class RuntimeManifestTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function entry(string $frankenphp, ?string $sha = null): array
    {
        return [
            'url' => 'https://github.com/multi-api/arco-runtime/releases/download/x/frankenphp-linux-aarch64',
            'sha256' => $sha ?? str_repeat('a', 64),
            'size' => 100,
            'frankenphp' => $frankenphp,
            'php' => '8.5.11',
        ];
    }

    private static function manifest(): RuntimeManifest
    {
        return RuntimeManifest::fromArray([
            'default' => '1.13.0-r2',
            'builds' => [
                '1.12.7-r1' => self::entry('1.12.7'),
                '1.13.0-r2' => self::entry('1.13.0'),
                '1.13.0-r10' => self::entry('1.13.0'),
                '1.13.0-r1' => self::entry('1.13.0'),
            ],
        ]);
    }

    public function test_resolution_order(): void
    {
        $m = self::manifest();
        self::assertSame('1.13.0-r2', $m->resolve(null, null)->id);
        self::assertSame('1.13.0-r1', $m->resolve('1.13.0-r1', null)->id);
        // Review focus 1: numeric revision order, r10 > r2.
        self::assertSame('1.13.0-r10', $m->resolve(null, '1.13.0')->id);
        self::assertSame(10, $m->get('1.13.0-r10')->revision());
        self::assertSame(['1.12.7-r1', '1.13.0-r1', '1.13.0-r2', '1.13.0-r10'], $m->ids());
    }

    public function test_errors_name_the_known_builds(): void
    {
        $m = self::manifest();
        foreach ([
            [fn () => $m->resolve('9.9.9-r1', null), 'binary.unknown_build'],
            [fn () => $m->resolve(null, '1.11.0'), 'binary.unknown_version'],
            [fn () => $m->resolve('1.13.0-r1', '1.13.0'), 'binary.build_and_version'],
        ] as [$call, $key]) {
            try {
                $call();
                self::fail("Expected $key");
            } catch (BinaryException $e) {
                self::assertSame($key, $e->key);
                if ($key !== 'binary.build_and_version') {
                    self::assertStringContainsString('1.13.0-r10', (string) $e->params['builds']);
                }
            }
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidManifests(): iterable
    {
        yield 'missing default build' => [['default' => '1.0.0-r1', 'builds' => ['1.13.0-r1' => self::entry('1.13.0')]]];
        yield 'bad id' => [['default' => '1.13.0', 'builds' => ['1.13.0' => self::entry('1.13.0')]]];
        yield 'id/version mismatch' => [['default' => '1.13.0-r1', 'builds' => ['1.13.0-r1' => self::entry('1.12.0')]]];
        yield 'bad sha' => [['default' => '1.13.0-r1', 'builds' => ['1.13.0-r1' => self::entry('1.13.0', 'xyz')]]];
        yield 'http url' => [['default' => '1.13.0-r1', 'builds' => ['1.13.0-r1' => ['url' => 'http://x/y'] + self::entry('1.13.0')]]];
        yield 'zero size' => [['default' => '1.13.0-r1', 'builds' => ['1.13.0-r1' => ['size' => 0] + self::entry('1.13.0')]]];
        yield 'no builds' => [['default' => '1.13.0-r1', 'builds' => []]];
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

    public function test_shipped_manifest_is_valid_and_pins_the_first_build(): void
    {
        $build = RuntimeManifest::load()->resolve(null, null);
        self::assertSame('1.13.0-r1', $build->id);
        self::assertSame('605ff4ae0ae4b8273026d92ee125405b8a589234a196e930f05eff067e9f2ca5', $build->sha256);
        self::assertSame(168140016, $build->size);
        self::assertSame('https://github.com/multi-api/arco-runtime/releases/download/frankenphp-1.13.0-r1/frankenphp-linux-aarch64', $build->url);
    }
}
