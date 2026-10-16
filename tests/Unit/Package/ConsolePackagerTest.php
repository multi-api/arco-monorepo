<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Package;

use Multiapi\Arco\Build\BuildException;
use Multiapi\Arco\Package\ConsolePackager;
use Multiapi\Arco\Package\Package;
use Multiapi\Arco\Package\PackageManifest;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class ConsolePackagerTest extends TestCase
{
    private const ENTRIES = [
        'app/public/index.php' => '<?php echo 1;',
        'arco-package.json' => '{}',
        'arco-runtime.json' => '{"console":{"entry":"artisan"}}',
        'bin/frankenphp' => 'ELF-the-runtime-binary',
        'bootstrap' => "#!/bin/sh\n",
        'index.js' => '// node web runtime',
        'lib/caddyfile.js' => '// lib',
        'runtime/console.php' => '<?php // console',
        'runtime/console/Handler.php' => '<?php // handler',
    ];

    private function web(bool $withBinary = true, ?string $runtimeBuild = '1.13.0-r1'): Package
    {
        $path = sys_get_temp_dir() . '/arco-web-' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $bytes = 0;
        foreach (self::ENTRIES as $name => $content) {
            if ($name === 'bin/frankenphp' && ! $withBinary) {
                continue;
            }
            $zip->addFromString($name, $content);
            $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, (0100000 | ($name === 'bootstrap' || $name === 'bin/frankenphp' ? 0755 : 0644)) << 16);
            $bytes += strlen($content);
        }
        $zip->close();
        $hash = str_repeat('a', 64);
        return new Package($path, $hash, $bytes, new PackageManifest('shop', 'production', 'laravel', $runtimeBuild, $hash, $bytes, 'dev', '2026-10-15T00:00:00Z'));
    }

    private function dir(): string
    {
        return sys_get_temp_dir() . '/arco-consolepkg-' . bin2hex(random_bytes(4));
    }

    public function test_the_console_zip_is_the_web_zip_without_binary_and_node_runtime(): void
    {
        $web = $this->web();

        $console = ConsolePackager::fromWeb($web, $this->dir());

        $zip = new ZipArchive();
        self::assertTrue($zip->open($console->package->path));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }
        sort($names);
        self::assertSame(['app/public/index.php', 'arco-package.json', 'arco-runtime.json', 'bootstrap', 'runtime/console.php', 'runtime/console/Handler.php'], $names);
        $zip->getExternalAttributesName('bootstrap', $opsys, $attr);
        self::assertSame(0755, ($attr >> 16) & 0777, 'Lambda runs bootstrap directly');
        $zip->close();

        $removed = strlen(self::ENTRIES['bin/frankenphp']) + strlen(self::ENTRIES['index.js']) + strlen(self::ENTRIES['lib/caddyfile.js']);
        self::assertSame($web->bytes - $removed, $console->package->bytes);
        self::assertSame(hash('sha256', "console\0" . $web->hash), $console->package->hash);
        self::assertFileExists($web->path, 'the web zip is untouched');
    }

    public function test_without_a_php_cli_the_console_downloads_the_web_binary(): void
    {
        $console = ConsolePackager::fromWeb($this->web(), $this->dir());

        self::assertNotNull($console->download);
        self::assertSame(self::ENTRIES['bin/frankenphp'], file_get_contents($console->download->path));
        self::assertSame(hash('sha256', self::ENTRIES['bin/frankenphp']), $console->download->sha256);
        self::assertSame(strlen(self::ENTRIES['bin/frankenphp']), $console->download->bytes);
        self::assertSame('runtime/1.13.0-r1/frankenphp', $console->download->key);
    }

    public function test_with_a_php_cli_the_console_zip_carries_bin_php_and_no_frankenphp(): void
    {
        $web = $this->web();
        $cli = sys_get_temp_dir() . '/arco-php-cli-' . bin2hex(random_bytes(4));
        file_put_contents($cli, 'ELF-the-static-php-cli');

        $console = ConsolePackager::fromWeb($web, $this->dir(), $cli);

        $zip = new ZipArchive();
        self::assertTrue($zip->open($console->package->path));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }
        sort($names);
        self::assertSame(['app/public/index.php', 'arco-package.json', 'arco-runtime.json', 'bin/php', 'bootstrap', 'runtime/console.php', 'runtime/console/Handler.php'], $names);
        self::assertSame('ELF-the-static-php-cli', $zip->getFromName('bin/php'));
        $zip->getExternalAttributesName('bin/php', $opsys, $attr);
        self::assertSame(ZipArchive::OPSYS_UNIX, $opsys);
        self::assertSame(0755, ($attr >> 16) & 0777, 'the bootstrap executes bin/php');
        $zip->close();

        self::assertNull($console->download);
        $removed = strlen(self::ENTRIES['bin/frankenphp']) + strlen(self::ENTRIES['index.js']) + strlen(self::ENTRIES['lib/caddyfile.js']);
        self::assertSame($web->bytes - $removed + strlen('ELF-the-static-php-cli'), $console->package->bytes);
        self::assertSame(hash('sha256', "console\0" . $web->hash), $console->package->hash);
        self::assertFileExists($web->path, 'the web zip is untouched');
    }

    public function test_a_custom_binary_is_keyed_by_its_checksum(): void
    {
        $console = ConsolePackager::fromWeb($this->web(runtimeBuild: null), $this->dir());

        self::assertSame('runtime/custom/' . hash('sha256', self::ENTRIES['bin/frankenphp']) . '/frankenphp', $console->download?->key);
    }

    public function test_a_web_zip_without_binary_is_refused(): void
    {
        try {
            ConsolePackager::fromWeb($this->web(withBinary: false), $this->dir());
            self::fail('A web zip without bin/frankenphp must be refused.');
        } catch (BuildException $e) {
            self::assertSame('package.no_binary', $e->key);
        }
    }
}
