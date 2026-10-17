<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Support;

use Symfony\Component\Filesystem\Filesystem;

/**
 * Temp files and directories a test creates. Every path handed out or tracked here is removed when the
 * test finishes (RemoveTempPaths, registered in phpunit.xml.dist), so the suite leaves nothing in
 * $TMPDIR. Static, so helpers that run outside a test instance (fixtures, emulators) can use it too.
 *
 * Two roots are allowed: the temp directory, and the shared root (build/tmp/integration in the
 * repository, git-ignored) for paths that sibling Docker containers bind-mount. The repository has the
 * same absolute path on the host and in the integration test container, so the host's Docker daemon
 * resolves a shared path to the same files the tests wrote.
 */
final class TempPaths
{
    /** @var array<string, true> removed when the current test finishes */
    private static array $paths = [];
    /** @var array<string, true> removed when the whole test run finishes */
    private static array $runPaths = [];

    /** A fresh path in the temp directory, not created yet: $prefix, 8 random hex digits, $suffix. */
    public static function path(string $prefix, string $suffix = ''): string
    {
        return self::track(sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(4)) . $suffix);
    }

    /**
     * A fresh path under the shared root, not created yet (nor is the root: create it with
     * mkdir(..., recursive: true)). With $untilRunEnds it outlives the test and is removed when the
     * run finishes, for a fixture several tests reuse.
     */
    public static function shared(string $prefix, bool $untilRunEnds = false): string
    {
        $path = self::sharedRoot() . '/' . $prefix . bin2hex(random_bytes(4));
        if (! $untilRunEnds) {
            return self::track($path);
        }
        self::assertAllowed($path);
        self::$runPaths[$path] = true;
        return $path;
    }

    /** build/tmp/integration in the repository; see sharedRootOf(). */
    public static function sharedRoot(): string
    {
        return self::sharedRootOf(dirname(__DIR__, 2));
    }

    /**
     * $repo/build/tmp/integration, after checking that none of build, build/tmp and build/tmp/integration
     * is a symlink and that, once it exists, it resolves inside $repo: a link there could point the
     * deleter anywhere. Throws \RuntimeException otherwise. Public for the tests of this check.
     */
    public static function sharedRootOf(string $repo): string
    {
        $repo = rtrim($repo, '/');
        $root = "$repo/build/tmp/integration";
        foreach (["$repo/build", "$repo/build/tmp", $root] as $dir) {
            if (is_link($dir)) {
                throw new \RuntimeException("TempPaths refuses the shared root: $dir is a symlink.");
            }
        }
        if (file_exists($root)) {
            $realRepo = realpath($repo);
            if ($realRepo === false || realpath($root) !== $realRepo . '/build/tmp/integration') {
                throw new \RuntimeException("TempPaths refuses the shared root: $root does not resolve inside $repo.");
            }
        }
        return $root;
    }

    /** A new empty file from tempnam(). */
    public static function file(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        if ($path === false) {
            throw new \RuntimeException('Cannot create a temporary file.');
        }
        return self::track($path);
    }

    /**
     * Removes $path (file or directory) when the test finishes; returns it unchanged. Only paths inside
     * the temp directory or the shared root are accepted, so a mistake can never delete anything else.
     */
    public static function track(string $path): string
    {
        self::assertAllowed($path);
        self::$paths[$path] = true;
        return $path;
    }

    private static function assertAllowed(string $path): void
    {
        if (! self::insideAllowedRoot($path)) {
            throw new \InvalidArgumentException("TempPaths only removes paths inside the temp directory or the shared root, not $path.");
        }
    }

    private static function insideAllowedRoot(string $path): bool
    {
        // The system temp root may be reached through a link (macOS: /var -> /private/var), so its
        // resolved form is accepted too; the shared root only literally (sharedRoot() refuses links).
        $temp = rtrim(sys_get_temp_dir(), '/');
        $realTemp = realpath($temp);
        $bases = [$temp, $realTemp === false ? $temp : rtrim($realTemp, '/'), self::sharedRoot()];
        foreach (array_unique($bases) as $base) {
            if ($base === '' || ! str_starts_with($path, $base . '/')) {
                continue;
            }
            // Every segment below the base must name an entry: no "", "." or ".." ("$base//" or
            // "$base/." would make the deleter remove the base itself or everything in it).
            $rest = substr($path, strlen($base) + 1);
            foreach (explode('/', $rest) as $segment) {
                if ($segment === '' || $segment === '.' || $segment === '..') {
                    return false;
                }
            }
            return true;
        }
        return false;
    }

    /** Removes the paths of the current test (RemoveTempPaths calls it after each test). */
    public static function removeAll(): void
    {
        $paths = array_keys(self::$paths);
        self::$paths = [];
        self::remove($paths);
    }

    /** Removes the paths kept for the whole run (RemoveTempPaths calls it when the run finishes). */
    public static function removeRunPaths(): void
    {
        $paths = array_keys(self::$runPaths);
        self::$runPaths = [];
        self::remove($paths);
    }

    /** @param list<string> $paths */
    private static function remove(array $paths): void
    {
        $filesystem = new Filesystem();
        foreach ($paths as $path) {
            // Tests may leave read-only modes behind (unwritable caches): make them removable first.
            if (is_dir($path) && ! is_link($path)) {
                @chmod($path, 0700);
            }
            $filesystem->remove($path);
        }
    }
}
