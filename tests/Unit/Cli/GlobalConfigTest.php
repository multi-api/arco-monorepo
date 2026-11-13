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

    public function test_a_file_that_is_not_a_config_is_replaced_not_fatal(): void
    {
        $path = TempPaths::path('arco-gc-', '.php');
        file_put_contents($path, '<?php return 42;');

        self::assertTrue(GlobalConfig::markTelemetryNoticeShown($path));
        self::assertTrue(GlobalConfig::telemetryNoticeShown($path));
    }

    public function test_a_home_that_cannot_be_written_returns_false(): void
    {
        $blocker = TempPaths::path('arco-gc-', '.file');
        file_put_contents($blocker, 'x');

        self::assertFalse(GlobalConfig::markTelemetryNoticeShown("$blocker/.arco/config.php"));
        self::assertFalse(GlobalConfig::telemetryNoticeShown("$blocker/.arco/config.php"));
    }
}
