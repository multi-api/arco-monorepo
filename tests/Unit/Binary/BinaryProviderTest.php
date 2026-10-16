<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Binary;

use Multiapi\Arco\Binary\BinaryException;
use Multiapi\Arco\Binary\BinaryProvider;
use Multiapi\Arco\Binary\Downloader;
use Multiapi\Arco\Binary\RuntimeManifest;
use Multiapi\Arco\Config\RuntimeConfig;
use PHPUnit\Framework\TestCase;

final class BinaryProviderTest extends TestCase
{
    private string $cache;
    private string $elf;
    /** @var list<string> */
    private array $urls = [];

    protected function setUp(): void
    {
        $this->cache = sys_get_temp_dir() . '/arco-cache-' . bin2hex(random_bytes(4));
        $this->elf = (string) file_get_contents(ElfInspectorTest::fakeElf(0xB7));
    }

    private function manifest(?string $sha = null, ?int $size = null): RuntimeManifest
    {
        return RuntimeManifest::fromArray([
            'default' => '1.13.0-r1',
            'builds' => ['1.13.0-r1' => [
                'url' => 'https://example.test/frankenphp-1.13.0-r1/frankenphp-linux-aarch64',
                'sha256' => $sha ?? hash('sha256', $this->elf),
                'size' => $size ?? strlen($this->elf),
                'frankenphp' => '1.13.0',
                'php' => '8.5.11',
            ]],
        ]);
    }

    private function runtime(?string $binary = null): RuntimeConfig
    {
        return new RuntimeConfig(null, null, $binary, 4, 64, 8, 'direct', []);
    }

    private function provider(RuntimeManifest $manifest, string $content): BinaryProvider
    {
        $downloader = new class ($content, $this->urls) implements Downloader {
            /** @param list<string> $urls */
            public function __construct(private string $content, private array &$urls) {}
            public function download(string $url, string $destination, ?callable $onProgress = null): void
            {
                $this->urls[] = $url;
                file_put_contents($destination, $this->content);
            }
        };
        return new BinaryProvider($downloader, $this->cache, $manifest);
    }

    public function test_downloads_verifies_and_caches_by_build_id(): void
    {
        $provider = $this->provider($this->manifest(), $this->elf);

        $path = $provider->get($this->runtime(), '/project');
        $again = $provider->get($this->runtime(), '/project');

        self::assertSame($path, $again);
        self::assertSame(['https://example.test/frankenphp-1.13.0-r1/frankenphp-linux-aarch64'], $this->urls);
        self::assertSame($this->cache . '/1.13.0-r1/frankenphp', $path);
        self::assertTrue(is_executable($path));
        self::assertSame('1.13.0-r1', $provider->describe($this->runtime())?->id);
    }

    public function test_checksum_mismatch_stops_and_caches_nothing(): void
    {
        try {
            $this->provider($this->manifest(sha: str_repeat('0', 64)), $this->elf)->get($this->runtime(), '/project');
            self::fail('Expected BinaryException');
        } catch (BinaryException $e) {
            self::assertSame('binary.checksum_mismatch', $e->key);
            self::assertSame('1.13.0-r1', $e->params['build']);
        }
        self::assertFileDoesNotExist($this->cache . '/1.13.0-r1/frankenphp');
        self::assertSame([], glob($this->cache . '/1.13.0-r1/*.part'));
    }

    public function test_size_mismatch_stops_and_caches_nothing(): void
    {
        try {
            $this->provider($this->manifest(size: 1), $this->elf)->get($this->runtime(), '/project');
            self::fail('Expected BinaryException');
        } catch (BinaryException $e) {
            self::assertSame('binary.size_mismatch', $e->key);
        }
        self::assertFileDoesNotExist($this->cache . '/1.13.0-r1/frankenphp');
        self::assertSame([], glob($this->cache . '/1.13.0-r1/*.part'));
    }

    public function test_unwritable_cache_throws_and_returns_nothing(): void
    {
        file_put_contents($this->cache, 'a file where the cache dir should be');

        try {
            $this->provider($this->manifest(), $this->elf)->get($this->runtime(), '/project');
            self::fail('Expected BinaryException');
        } catch (BinaryException $e) {
            self::assertSame('binary.cache_write_failed', $e->key);
        }
        self::assertSame([], $this->urls);
        unlink($this->cache);
    }

    public function test_corrupted_cache_is_downloaded_again(): void
    {
        // Review focus 2.
        mkdir($this->cache . '/1.13.0-r1', 0777, true);
        file_put_contents($this->cache . '/1.13.0-r1/frankenphp', 'truncated');

        $path = $this->provider($this->manifest(), $this->elf)->get($this->runtime(), '/project');

        self::assertSame(hash('sha256', $this->elf), hash_file('sha256', $path));
        self::assertCount(1, $this->urls);
    }

    public function test_custom_binary_bypasses_the_manifest(): void
    {
        $project = sys_get_temp_dir() . '/arco-bin-' . bin2hex(random_bytes(4));
        mkdir("$project/bin", 0777, true);
        file_put_contents("$project/bin/frankenphp", $this->elf);
        $provider = $this->provider($this->manifest(), '');

        self::assertSame("$project/bin/frankenphp", $provider->get($this->runtime('bin/frankenphp'), $project));
        self::assertNull($provider->describe($this->runtime('bin/frankenphp')));
        self::assertSame([], $this->urls);
    }

    private function cliManifest(?string $sha = null): RuntimeManifest
    {
        return RuntimeManifest::fromArray([
            'default' => '1.13.0-r2',
            'builds' => [
                '1.13.0-r1' => [
                    'url' => 'https://example.test/frankenphp-1.13.0-r1/frankenphp-linux-aarch64',
                    'sha256' => hash('sha256', $this->elf),
                    'size' => strlen($this->elf),
                    'frankenphp' => '1.13.0',
                    'php' => '8.5.11',
                ],
                '1.13.0-r2' => [
                    'url' => 'https://example.test/frankenphp-1.13.0-r2/frankenphp-linux-aarch64',
                    'sha256' => hash('sha256', $this->elf),
                    'size' => strlen($this->elf),
                    'frankenphp' => '1.13.0',
                    'php' => '8.5.11',
                    'php_cli' => [
                        'url' => 'https://example.test/frankenphp-1.13.0-r2/php-linux-aarch64',
                        'sha256' => $sha ?? hash('sha256', 'php-cli-bytes'),
                        'size' => strlen('php-cli-bytes'),
                    ],
                ],
            ],
        ]);
    }

    public function test_php_cli_is_downloaded_verified_and_cached(): void
    {
        $provider = $this->provider($this->cliManifest(), 'php-cli-bytes');
        $build = $provider->build('1.13.0-r2');
        self::assertNotNull($build);

        $path = $provider->phpCli($build);
        $again = $provider->phpCli($build);

        self::assertSame($this->cache . '/1.13.0-r2/php', $path);
        self::assertSame($path, $again);
        self::assertSame(['https://example.test/frankenphp-1.13.0-r2/php-linux-aarch64'], $this->urls);
        self::assertSame(0755, fileperms($path) & 0777);
    }

    public function test_a_cached_php_cli_with_a_wrong_checksum_is_downloaded_again(): void
    {
        mkdir($this->cache . '/1.13.0-r2', 0777, true);
        file_put_contents($this->cache . '/1.13.0-r2/php', 'truncated');
        $provider = $this->provider($this->cliManifest(), 'php-cli-bytes');

        $path = $provider->phpCli($provider->build('1.13.0-r2'));

        self::assertSame('php-cli-bytes', file_get_contents($path));
        self::assertCount(1, $this->urls);
    }

    public function test_a_downloaded_php_cli_with_a_wrong_checksum_is_refused(): void
    {
        $provider = $this->provider($this->cliManifest(sha: str_repeat('0', 64)), 'php-cli-bytes');

        try {
            $provider->phpCli($provider->build('1.13.0-r2'));
            self::fail('Expected BinaryException');
        } catch (BinaryException $e) {
            self::assertSame('binary.checksum_mismatch', $e->key);
        }
        self::assertFileDoesNotExist($this->cache . '/1.13.0-r2/php');
        self::assertSame([], glob($this->cache . '/1.13.0-r2/*.part'));
    }

    public function test_no_php_cli_returns_null(): void
    {
        $provider = $this->provider($this->cliManifest(), '');

        self::assertNull($provider->phpCli($provider->build('1.13.0-r1')));
        self::assertSame([], $this->urls);
    }

    public function test_build_returns_null_for_an_unknown_id(): void
    {
        self::assertNull($this->provider($this->cliManifest(), '')->build('9.9.9-r9'));
    }
}
