<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Tools;

use InvalidArgumentException;
use Multiapi\Arco\Binary\RuntimeManifest;
use Multiapi\Arco\Tests\Support\TempPaths;
use Multiapi\Arco\Tools\RuntimeManifestEditor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RuntimeManifestEditorTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = TempPaths::path('arco-manifest-', '.php');
        copy(__DIR__ . '/../../../packages/arco/resources/runtime-builds.php', $this->path);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    /**
     * A build the shipped manifest (copied in setUp) can never contain, so these tests keep passing as
     * real builds are added.
     *
     * @return array<string, mixed>
     */
    private static function buildJson(string $id = '1.13.0-r90'): array
    {
        return [
            'id' => $id, 'frankenphp' => '1.13.0', 'php' => '8.5.13', 'arch' => 'linux-aarch64',
            'sha256' => str_repeat('b', 64), 'size' => 168140100,
        ];
    }

    public function test_adds_a_build_under_its_php_and_frankenphp_id(): void
    {
        $before = RuntimeManifest::load($this->path);
        $id = RuntimeManifestEditor::add($this->path, ['php' => '8.6.0'] + self::buildJson(), 'multi-api/arco-runtime');
        self::assertSame('php8.6.0-fp1.13.0-r90', $id);
        $manifest = RuntimeManifest::load($this->path);
        // The release id stays the GitHub tag (frankenphp-<release id>): only the Arco id is new.
        self::assertSame(
            'https://github.com/multi-api/arco-runtime/releases/download/frankenphp-1.13.0-r90/frankenphp-linux-aarch64',
            $manifest->get('php8.6.0-fp1.13.0-r90')->url,
        );
        // A new PHP minor never becomes the default, and the aliases are kept.
        self::assertSame($before->resolve(null, null)->id, $manifest->resolve(null, null)->id);
        self::assertSame($before->aliases(), $manifest->aliases());
        self::assertSame('8.5', $manifest->defaultPhp());
    }

    public function test_a_newer_build_of_the_default_php_minor_becomes_the_default(): void
    {
        RuntimeManifestEditor::add($this->path, self::buildJson(), 'multi-api/arco-runtime');
        self::assertSame('php8.5.13-fp1.13.0-r90', RuntimeManifest::load($this->path)->resolve(null, null)->id);
        // The pre-0.9 ids still name their own builds.
        self::assertSame('php8.5.11-fp1.13.0-r2', RuntimeManifest::load($this->path)->get('1.13.0-r2')->id);
    }

    public function test_adds_the_php_cli_with_a_derived_url(): void
    {
        $build = self::buildJson() + ['php_cli' => [
            'sha256' => str_repeat('c', 64), 'size' => 90000000, 'spc' => '2.7.0', 'extensions' => ['curl'],
        ]];
        RuntimeManifestEditor::add($this->path, $build, 'multi-api/arco-runtime');

        $cli = RuntimeManifest::load($this->path)->get('php8.5.13-fp1.13.0-r90')->phpCli;
        self::assertNotNull($cli);
        self::assertSame(
            ['https://github.com/multi-api/arco-runtime/releases/download/frankenphp-1.13.0-r90/php-linux-aarch64', str_repeat('c', 64), 90000000],
            [$cli->url, $cli->sha256, $cli->size],
        );
    }

    public function test_release_cross_check_covers_the_php_cli_asset(): void
    {
        $build = self::buildJson() + ['php_cli' => ['sha256' => str_repeat('c', 64), 'size' => 90000000]];
        $cliAsset = ['name' => 'php-linux-aarch64', 'size' => 90000000, 'digest' => 'sha256:' . str_repeat('c', 64)];
        $release = self::release();

        $release['assets'][] = $cliAsset;
        RuntimeManifestEditor::assertReleaseMatches($release, $build);

        foreach ([['digest' => 'sha256:' . str_repeat('a', 64)], ['size' => 1]] as $override) {
            $bad = self::release();
            $bad['assets'][] = $override + $cliAsset;
            try {
                RuntimeManifestEditor::assertReleaseMatches($bad, $build);
                self::fail('Expected InvalidArgumentException');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_non_array_php_cli_is_refused_everywhere(): void
    {
        $build = self::buildJson() + ['php_cli' => 'oops'];
        $before = file_get_contents($this->path);
        foreach ([
            fn () => RuntimeManifestEditor::add($this->path, $build, 'multi-api/arco-runtime'),
            fn () => RuntimeManifestEditor::assertReleaseMatches(self::release(), $build),
        ] as $call) {
            try {
                $call();
                self::fail('Expected InvalidArgumentException');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame($before, file_get_contents($this->path));
    }

    public function test_release_cross_check_refuses_a_missing_php_cli_asset(): void
    {
        $build = self::buildJson() + ['php_cli' => ['sha256' => str_repeat('c', 64), 'size' => 90000000]];
        $this->expectException(InvalidArgumentException::class);
        RuntimeManifestEditor::assertReleaseMatches(self::release(), $build);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function badBuilds(): iterable
    {
        // Review focus 5.
        yield 'wrong arch' => [['arch' => 'linux-x86_64'] + self::buildJson()];
        yield 'missing sha' => [array_diff_key(self::buildJson(), ['sha256' => true])];
        yield 'id/version mismatch' => [['frankenphp' => '1.12.0'] + self::buildJson()];
        yield 'bad id' => [['id' => 'latest'] + self::buildJson()];
        yield 'new-format id' => [['id' => 'php8.5.13-fp1.13.0-r90'] + self::buildJson()];
        yield 'php not a version' => [['php' => '8.5'] + self::buildJson()];
    }

    /** @param array<string, mixed> $build */
    #[DataProvider('badBuilds')]
    public function test_rejects_bad_build_json_without_touching_the_file(array $build): void
    {
        $before = file_get_contents($this->path);
        try {
            RuntimeManifestEditor::add($this->path, $build, 'multi-api/arco-runtime');
            self::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException) {
            self::assertSame($before, file_get_contents($this->path));
        }
    }

    public function test_refuses_to_replace_an_existing_build_or_release(): void
    {
        $before = file_get_contents($this->path);
        // The same Arco id, and the same release (tag) under another PHP version.
        foreach (['8.5.11', '8.5.13'] as $php) {
            try {
                RuntimeManifestEditor::add($this->path, ['sha256' => str_repeat('c', 64), 'php' => $php] + self::buildJson('1.13.0-r1'), 'multi-api/arco-runtime');
                self::fail('Expected InvalidArgumentException');
            } catch (InvalidArgumentException) {
                self::assertSame($before, file_get_contents($this->path));
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function badRepos(): iterable
    {
        yield 'newline injection' => ["multi-api/arco-runtime\n// evil"];
        yield 'trailing newline' => ["multi-api/arco-runtime\n"];
        yield 'no slash' => ['arco-runtime'];
        yield 'extra segment' => ['a/b/c'];
        yield 'space' => ['a/b c'];
    }

    #[DataProvider('badRepos')]
    public function test_rejects_invalid_repository_without_touching_the_file(string $repo): void
    {
        $before = file_get_contents($this->path);
        try {
            RuntimeManifestEditor::add($this->path, self::buildJson(), $repo);
            self::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException) {
            self::assertSame($before, file_get_contents($this->path));
        }
    }

    /** @return array<string, mixed> */
    private static function release(): array
    {
        return ['immutable' => true, 'assets' => [
            ['name' => 'SHA256SUMS', 'size' => 10, 'digest' => 'sha256:' . str_repeat('0', 64)],
            ['name' => 'frankenphp-linux-aarch64', 'size' => 168140100, 'digest' => 'sha256:' . str_repeat('b', 64)],
        ]];
    }

    public function test_release_cross_check_accepts_a_matching_release(): void
    {
        RuntimeManifestEditor::assertReleaseMatches(self::release(), self::buildJson());
        $this->addToAssertionCount(1);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function badReleases(): iterable
    {
        $r = self::release();
        yield 'not immutable' => [['immutable' => false] + $r];
        yield 'immutable missing' => [array_diff_key($r, ['immutable' => true])];
        yield 'missing asset' => [['assets' => [$r['assets'][0]]] + $r];
        yield 'size mismatch' => [['assets' => [$r['assets'][0], ['size' => 1] + $r['assets'][1]]] + $r];
        yield 'digest mismatch' => [['assets' => [$r['assets'][0], ['digest' => 'sha256:' . str_repeat('a', 64)] + $r['assets'][1]]] + $r];
    }

    /** @param array<string, mixed> $release */
    #[DataProvider('badReleases')]
    public function test_release_cross_check_rejects_mismatches(array $release): void
    {
        $this->expectException(InvalidArgumentException::class);
        RuntimeManifestEditor::assertReleaseMatches($release, self::buildJson());
    }
}
