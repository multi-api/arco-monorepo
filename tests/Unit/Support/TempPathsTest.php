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

    #[DataProvider('outsidePaths')]
    public function test_paths_outside_the_temp_directory_are_refused(string $path): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TempPaths::track($path);
    }
}
