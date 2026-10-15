<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Binary;

use Multiapi\Arco\Binary\ElfInspector;
use PHPUnit\Framework\TestCase;

final class ElfInspectorTest extends TestCase
{
    /** Writes a minimal 64-byte ELF header for the given e_machine. */
    public static function fakeElf(int $machine): string
    {
        $path = tempnam(sys_get_temp_dir(), 'arco-elf-');
        $header = "\x7FELF" . "\x02\x01\x01" . str_repeat("\0", 9) . pack('v', 2) . pack('v', $machine) . str_repeat("\0", 44);
        file_put_contents((string) $path, $header);
        return (string) $path;
    }

    public function test_detects_linux_arm64(): void
    {
        self::assertTrue(ElfInspector::isLinuxArm64(self::fakeElf(0xB7)));
        self::assertFalse(ElfInspector::isLinuxArm64(self::fakeElf(0x3E))); // x86-64
        self::assertFalse(ElfInspector::isLinuxArm64(__FILE__));
        self::assertFalse(ElfInspector::isLinuxArm64('/nonexistent'));
    }
}
