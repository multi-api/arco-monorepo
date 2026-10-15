<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Config;

use Multiapi\Arco\Config\ConfigException;
use Multiapi\Arco\Config\ConfigLoader;
use PHPUnit\Framework\TestCase;

final class ConfigLoaderTest extends TestCase
{
    private function write(string $php): string
    {
        $dir = sys_get_temp_dir() . '/arco cfg ' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("$dir/arco.php", $php);
        return "$dir/arco.php";
    }

    public function test_loads_config_in_a_separate_process(): void
    {
        $path = $this->write(<<<'PHP'
            <?php
            use Multiapi\Arco\Config\App;
            return App::make('shop')->region('us-east-1')->environment('production');
            PHP);

        $raw = (new ConfigLoader())->load($path);

        self::assertSame('shop', $raw['name']);
        self::assertSame(['production'], array_keys($raw['environments']));
    }

    public function test_output_and_notices_in_arco_php_do_not_corrupt_the_result(): void
    {
        $path = $this->write(<<<'PHP'
            <?php
            echo "debug output\n";
            trigger_error('a notice', E_USER_NOTICE);
            return Multiapi\Arco\Config\App::make('noisy')->environment('dev');
            PHP);

        self::assertSame('noisy', (new ConfigLoader())->load($path)['name']);
    }

    public function test_invalid_return_value(): void
    {
        $path = $this->write('<?php return ["name" => "x"];');

        try {
            (new ConfigLoader())->load($path);
            self::fail('Expected ConfigException');
        } catch (ConfigException $e) {
            self::assertSame('config.invalid_return', $e->key);
        }
    }

    public function test_fatal_error_is_reported(): void
    {
        $path = $this->write('<?php undefined_function();');

        try {
            (new ConfigLoader())->load($path);
            self::fail('Expected ConfigException');
        } catch (ConfigException $e) {
            self::assertSame('config.failed', $e->key);
            self::assertStringContainsString('undefined_function', (string) $e->params['output']);
        }
    }

    public function test_missing_file(): void
    {
        $this->expectException(ConfigException::class);
        (new ConfigLoader())->load('/nonexistent/arco.php');
    }
}
