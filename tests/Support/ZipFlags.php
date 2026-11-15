<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Support;

/** Reads the general purpose flags of a zip entry, which tell the deflate level libzip used. */
final class ZipFlags
{
    /** Bits 1-2 of a deflated entry: 10 means "fast" (level 1 in libzip). */
    public const DEFLATE_FAST = 0b100;
    public const LEVEL_BITS = 0b110;

    /** Whether $name was deflated at level 1. */
    public static function fast(string $zip, string $name): bool
    {
        return (self::of($zip, $name) & self::LEVEL_BITS) === self::DEFLATE_FAST;
    }

    /** The general purpose flags of $name, read from the zip's central directory. */
    public static function of(string $zip, string $name): int
    {
        $bytes = (string) file_get_contents($zip);
        $end = strrpos($bytes, "PK\x05\x06");
        if ($end === false) {
            throw new \RuntimeException("$zip is not a zip");
        }
        $directory = unpack('ventries/Vsize/Voffset', substr($bytes, $end + 10, 10));
        if ($directory === false) {
            throw new \RuntimeException("$zip has no central directory");
        }
        $offset = (int) $directory['offset'];
        for ($i = 0; $i < (int) $directory['entries']; $i++) {
            $record = unpack('Vsignature/vmade/vneeded/vflags/vmethod/Vtime/Vcrc/Vcompressed/Vsize/vname/vextra/vcomment', substr($bytes, $offset, 34));
            if ($record === false) {
                break;
            }
            if (substr($bytes, $offset + 46, (int) $record['name']) === $name) {
                return (int) $record['flags'];
            }
            $offset += 46 + (int) $record['name'] + (int) $record['extra'] + (int) $record['comment'];
        }
        throw new \RuntimeException("$name is not in $zip");
    }
}
