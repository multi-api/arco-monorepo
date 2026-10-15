<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\ConsoleRuntime;

use Multiapi\Arco\ConsoleRuntime\PhpIni;
use PHPUnit\Framework\TestCase;

final class PhpIniTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/arco-ini-' . bin2hex(random_bytes(4));
    }

    public function test_the_settings_are_written_as_an_ini_file_in_the_scan_dir(): void
    {
        // Checked with PHP's own ini parser; the integration test checks FrankenPHP reads it (memory_limit).
        $env = PhpIni::prepare([
            'memory_limit' => '512M',          // PHP_INI_ALL
            'realpath_cache_size' => '8M',     // PHP_INI_SYSTEM: ini_set() could not change it
            'error_log' => "/tmp/it's \"here\" \${HOME}\\x.log",
            'display_errors' => false,
            'opcache.enable_cli' => true,
            'max_input_nesting_level' => 32,
        ], $this->dir, []);

        self::assertSame(['PHP_INI_SCAN_DIR' => ":$this->dir"], $env);
        self::assertSame([
            'memory_limit' => '512M',
            'realpath_cache_size' => '8M',
            'error_log' => "/tmp/it's \"here\" \${HOME}\\x.log",
            'display_errors' => '0',
            'opcache.enable_cli' => '1',
            'max_input_nesting_level' => '32',
        ], parse_ini_file("$this->dir/99-arco.ini", false, INI_SCANNER_NORMAL));
    }

    public function test_an_existing_scan_dir_is_kept(): void
    {
        self::assertSame(['PHP_INI_SCAN_DIR' => "/opt/php.d:$this->dir"], PhpIni::prepare(['memory_limit' => '1G'], $this->dir, ['PHP_INI_SCAN_DIR' => '/opt/php.d']));
    }

    /** @return array<string, array{array<mixed>}> */
    public static function unsafeSettings(): array
    {
        return [
            'quote then line break' => [['error_log' => "q\"\nmemory_limit = 1M ;"]],
            'carriage return' => [['x' => "a\rb"]],
            'NUL' => [['x' => "a\0b"]],
            'key with a space' => [['memory limit' => '1M']],
            'key with a line break' => [["a\nb" => '1M']],
            'empty key' => [['' => '1M']],
        ];
    }

    /** @param array<mixed> $settings */
    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeSettings')]
    public function test_values_and_keys_that_could_inject_directives_are_refused(array $settings): void
    {
        try {
            PhpIni::prepare($settings, $this->dir, []);
            self::fail('Unsafe settings must be refused.');
        } catch (\InvalidArgumentException) {
            self::assertFileDoesNotExist("$this->dir/99-arco.ini");
        }
    }

    public function test_no_settings_changes_nothing(): void
    {
        self::assertSame([], PhpIni::prepare([], $this->dir, []));
        self::assertDirectoryDoesNotExist($this->dir);
    }
}
