<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Build;

use Multiapi\Arco\Build\FrameworkPreset;
use Multiapi\Arco\Build\PlainPhpPreset;
use Multiapi\Arco\Build\PresetRegistry;
use Multiapi\Arco\Tests\Support\TempPaths;
use PHPUnit\Framework\TestCase;

final class PresetRegistryTest extends TestCase
{
    public function test_first_detecting_preset_wins_and_plain_php_is_the_fallback(): void
    {
        $special = new class extends PlainPhpPreset {
            public function name(): string { return 'special'; }
            public function detects(string $projectDir): bool { return is_file("$projectDir/special.txt"); }
        };
        $registry = new PresetRegistry([$special]);
        $dir = TempPaths::path('arco-preset-');
        mkdir($dir);

        self::assertSame('php', $registry->detect($dir)->name());
        touch("$dir/special.txt");
        self::assertSame('special', $registry->detect($dir)->name());
    }

    public function test_plain_php_preset_defaults(): void
    {
        $preset = new PlainPhpPreset();
        self::assertInstanceOf(FrameworkPreset::class, $preset);
        self::assertSame('public', $preset->publicDir());
        self::assertNull($preset->workerScript());
        self::assertSame('/', $preset->healthPath());
    }
}
