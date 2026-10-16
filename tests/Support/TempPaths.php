<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Support;

use Symfony\Component\Filesystem\Filesystem;

/**
 * Temp files and directories a test creates. Every path handed out or tracked here is removed when the
 * test finishes (RemoveTempPaths, registered in phpunit.xml.dist), so the suite leaves nothing in
 * $TMPDIR. Static, so helpers that run outside a test instance (fixtures, emulators) can use it too.
 */
final class TempPaths
{
    /** @var array<string, true> */
    private static array $paths = [];

    /** A fresh path in the temp directory, not created yet: $prefix, 8 random hex digits, $suffix. */
    public static function path(string $prefix, string $suffix = ''): string
    {
        return self::track(sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(4)) . $suffix);
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
     * the temp directory are accepted, so a mistake can never delete anything else.
     */
    public static function track(string $path): string
    {
        if (! self::insideTempDir($path)) {
            throw new \InvalidArgumentException("TempPaths only removes paths inside the temp directory, not $path.");
        }
        self::$paths[$path] = true;
        return $path;
    }

    private static function insideTempDir(string $path): bool
    {
        if (str_contains($path, '..')) {
            return false;
        }
        $temp = rtrim(sys_get_temp_dir(), '/');
        $real = realpath($temp);
        foreach (array_unique([$temp, $real === false ? $temp : rtrim($real, '/')]) as $base) {
            if ($base !== '' && str_starts_with($path, $base . '/') && strlen($path) > strlen($base) + 1) {
                return true;
            }
        }
        return false;
    }

    public static function removeAll(): void
    {
        $paths = array_keys(self::$paths);
        self::$paths = [];
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
