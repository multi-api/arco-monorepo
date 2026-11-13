<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Support;

/**
 * A temp HOME for a test that runs the CLI, so ~/.arco/config.php (the first-deploy telemetry notice, the language)
 * is never the developer's own. By default the notice counts as already shown, so a deploy prints nothing extra.
 */
final class ArcoHome
{
    private static string|false|null $previous = null;

    /** Points HOME at a fresh temp directory and returns it. */
    public static function isolate(bool $noticeShown = true): string
    {
        $home = TempPaths::path('arco-home-');
        mkdir("$home/.arco", 0777, true);
        if ($noticeShown) {
            file_put_contents("$home/.arco/config.php", "<?php\n\nreturn ['telemetry_notice_shown' => true];\n");
        }
        self::$previous ??= getenv('HOME');
        putenv("HOME=$home");
        return $home;
    }

    public static function restore(): void
    {
        if (self::$previous !== null) {
            putenv(self::$previous === false ? 'HOME' : 'HOME=' . self::$previous);
            self::$previous = null;
        }
    }
}
