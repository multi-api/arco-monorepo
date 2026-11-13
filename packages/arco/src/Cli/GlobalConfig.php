<?php declare(strict_types=1);

namespace Multiapi\Arco\Cli;

use Throwable;

/**
 * The per-machine file ~/.arco/config.php: a PHP file returning an array (lang, telemetry_notice_shown).
 * Writing keeps every key it finds and never throws: a home that cannot be written is only reported as false.
 */
final class GlobalConfig
{
    public static function path(): string
    {
        return LocaleResolver::globalConfigPath();
    }

    /** Whether the one-time telemetry notice was already shown on this machine. */
    public static function telemetryNoticeShown(?string $path = null): bool
    {
        return (self::read($path ?? self::path())['telemetry_notice_shown'] ?? false) === true;
    }

    /** Records that the notice was shown. False when the file could not be written. */
    public static function markTelemetryNoticeShown(?string $path = null): bool
    {
        $path ??= self::path();
        return self::write($path, ['telemetry_notice_shown' => true] + self::read($path));
    }

    /** @return array<array-key, mixed> */
    private static function read(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        try {
            $config = (static fn () => require $path)();
        } catch (Throwable) {
            return [];
        }
        return is_array($config) ? $config : [];
    }

    /** @param array<array-key, mixed> $config */
    private static function write(string $path, array $config): bool
    {
        $temp = null;
        try {
            $dir = dirname($path);
            if (! is_dir($dir) && ! @mkdir($dir, 0777, true) && ! is_dir($dir)) {
                return false;
            }
            $temp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (@file_put_contents($temp, "<?php\n\nreturn " . var_export($config, true) . ";\n") === false) {
                return false;
            }
            if (! @rename($temp, $path)) {
                @unlink($temp);
                return false;
            }
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($path, true);
            }
            return true;
        } catch (Throwable) {
            if ($temp !== null) {
                @unlink($temp);
            }
            return false;
        }
    }
}
