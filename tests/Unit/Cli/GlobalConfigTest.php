<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Cli;

use Multiapi\Arco\Cli\GlobalConfig;
use Multiapi\Arco\Cli\LocaleResolver;
use Multiapi\Arco\Tests\Support\TempPaths;
use PHPUnit\Framework\TestCase;

final class GlobalConfigTest extends TestCase
{
    public function test_the_notice_is_not_shown_until_marked(): void
    {
        $path = TempPaths::path('arco-gc-') . '/.arco/config.php';

        self::assertFalse(GlobalConfig::telemetryNoticeShown($path));
        self::assertTrue(GlobalConfig::markTelemetryNoticeShown($path));
        self::assertTrue(GlobalConfig::telemetryNoticeShown($path));
    }

    public function test_marking_keeps_the_existing_keys(): void
    {
        $path = TempPaths::path('arco-gc-', '.php');
        file_put_contents($path, "<?php return ['lang' => 'es', 'other' => [1, 2]];");

        self::assertTrue(GlobalConfig::markTelemetryNoticeShown($path));

        $config = require $path;
        self::assertSame('es', $config['lang']);
        self::assertSame([1, 2], $config['other']);
        self::assertTrue($config['telemetry_notice_shown']);
        self::assertSame('es', LocaleResolver::resolve(null, [], $path));
        self::assertSame([], glob("$path.*.tmp"), 'no temp file is left behind');
    }

    /** @return array<string, array{string}> */
    public static function unreadableConfigs(): array
    {
        return [
            'not an array' => ['<?php return 42;'],
            'a parse error' => ["<?php return ['lang' => 'es'"],
            'a file that throws' => ["<?php throw new \\RuntimeException('x');"],
        ];
    }

    /** A file that does not load as an array may still be the user's (a typo away from ['lang' => ...]): kept as it is. */
    #[\PHPUnit\Framework\Attributes\DataProvider('unreadableConfigs')]
    public function test_a_file_that_does_not_load_as_a_config_is_left_alone(string $contents): void
    {
        $path = TempPaths::path('arco-gc-', '.php');
        file_put_contents($path, $contents);

        self::assertFalse(GlobalConfig::markTelemetryNoticeShown($path));
        self::assertSame($contents, file_get_contents($path));
        self::assertFalse(GlobalConfig::telemetryNoticeShown($path));
        self::assertSame([], glob("$path.*.tmp"));
    }

    public function test_a_home_that_cannot_be_written_returns_false(): void
    {
        $blocker = TempPaths::path('arco-gc-', '.file');
        file_put_contents($blocker, 'x');

        self::assertFalse(GlobalConfig::markTelemetryNoticeShown("$blocker/.arco/config.php"));
        self::assertFalse(GlobalConfig::telemetryNoticeShown("$blocker/.arco/config.php"));
    }
}
