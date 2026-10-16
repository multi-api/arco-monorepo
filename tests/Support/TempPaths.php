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

    /** Removes $path (file or directory) when the test finishes; returns it unchanged. */
    public static function track(string $path): string
    {
        self::$paths[$path] = true;
        return $path;
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
