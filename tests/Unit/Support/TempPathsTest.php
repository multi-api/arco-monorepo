<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Support;

use Multiapi\Arco\Tests\Support\TempPaths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TempPathsTest extends TestCase
{
    public function test_paths_inside_the_temp_directory_are_tracked(): void
    {
        $path = TempPaths::path('arco-temppaths-');
        self::assertStringStartsWith(sys_get_temp_dir(), $path);
        self::assertSame($path, TempPaths::track($path));

        $real = (string) realpath(sys_get_temp_dir());
        $resolved = $real . '/arco-temppaths-' . bin2hex(random_bytes(4));
        self::assertSame($resolved, TempPaths::track($resolved), 'the resolved form of the temp directory is accepted too');
    }

    public function test_shared_paths_live_in_the_repository_build_directory(): void
    {
        $root = TempPaths::sharedRoot();
        self::assertSame(dirname(__DIR__, 3) . '/build/tmp/integration', $root);

        $path = TempPaths::shared('arco-temppaths-');
        self::assertStringStartsWith("$root/arco-temppaths-", $path);
        self::assertFileDoesNotExist($path);
        self::assertSame($path, TempPaths::track($path));
        self::assertStringStartsWith("$root/arco-run-", TempPaths::shared('arco-run-', untilRunEnds: true));
    }

    /** @return array<string, array{string}> */
    public static function outsidePaths(): array
    {
        $temp = rtrim(sys_get_temp_dir(), '/');
        $shared = TempPaths::sharedRoot();
        return [
            'the temp directory with a double slash' => [$temp . '//'],
            'the temp directory with /.' => [$temp . '/.'],
            'the temp directory with /./' => [$temp . '/./'],
            'an empty segment inside the temp directory' => [$temp . '/a//b'],
            'a . segment inside the temp directory' => [$temp . '/a/./b'],
            'the shared root with a double slash' => [$shared . '//'],
            'the shared root with /.' => [$shared . '/.'],
            'the shared root with /./x/..' => [$shared . '/./x/..'],
            'the shared root itself' => [$shared],
            'the shared root with a slash' => [$shared . '/'],
            'the repository root' => [dirname($shared, 3)],
            'a file of the repository' => [dirname($shared, 3) . '/composer.json'],
            'the parent of the shared root' => [dirname($shared) . '/other'],
            'a sibling of the shared root sharing the prefix' => [$shared . '-other/file'],
            'climbing out of the shared root' => [$shared . '/../../../composer.json'],
            'another directory' => ['/Users/someone/project'],
            'the root' => ['/'],
            'the temp directory itself' => [$temp],
            'the temp directory with a slash' => [$temp . '/'],
            'a sibling sharing the prefix' => [$temp . '-other/file'],
            'a path climbing out with ..' => [$temp . '/../etc'],
            'a relative path' => ['arco-relative'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function linkedSharedRootParts(): array
    {
        return ['build' => ['build'], 'build/tmp' => ['build/tmp'], 'build/tmp/integration' => ['build/tmp/integration']];
    }

    /** Only the guard runs here: nothing is deleted but the sandbox, which RemoveTempPaths removes. */
    #[DataProvider('linkedSharedRootParts')]
    public function test_a_symlink_in_the_shared_root_is_refused(string $linked): void
    {
        $sandbox = TempPaths::path('arco-temppaths-link-');
        $repo = "$sandbox/repo";
        mkdir("$sandbox/elsewhere", 0777, true);
        $parent = dirname("$repo/$linked");
        mkdir($parent, 0777, true);
        self::assertTrue(symlink("$sandbox/elsewhere", "$repo/$linked"));
        self::assertSame("$sandbox/plain/build/tmp/integration", TempPaths::sharedRootOf("$sandbox/plain"), 'a plain root is accepted');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is a symlink');

        TempPaths::sharedRootOf($repo);
    }

    #[DataProvider('outsidePaths')]
    public function test_paths_outside_the_temp_directory_are_refused(string $path): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TempPaths::track($path);
    }
}
