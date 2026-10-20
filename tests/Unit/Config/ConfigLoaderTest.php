<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Config;

use Composer\Autoload\ClassLoader;
use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\ConfigException;
use Multiapi\Arco\Config\ConfigLoader;
use Multiapi\Arco\Tests\Support\TempPaths;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ConfigLoaderTest extends TestCase
{
    private function write(string $php): string
    {
        $dir = TempPaths::path('arco cfg ');
        mkdir($dir);
        file_put_contents("$dir/arco.php", $php);
        return "$dir/arco.php";
    }

    public function test_autoload_path_skips_a_foreign_loader_that_cannot_find_arco(): void
    {
        $arco = new ClassLoader();
        $arco->addClassMap([App::class => __FILE__]);

        $path = ConfigLoader::autoloadPathFor([
            'phar:///Applications/Herd.app/dump.phar/vendor' => new ClassLoader(),
            '/projects/shop/vendor' => $arco,
        ]);

        $this->assertSame('/projects/shop/vendor/autoload.php', $path);
    }

    public function test_autoload_path_falls_back_to_the_running_class_loader(): void
    {
        $path = ConfigLoader::autoloadPathFor(['/elsewhere/vendor' => new ClassLoader()]);

        $this->assertSame(
            dirname((string) (new ReflectionClass(ClassLoader::class))->getFileName(), 2) . '/autoload.php',
            $path,
        );
    }

    public function test_a_removed_max_environments_call_names_its_replacement(): void
    {
        $path = $this->write("<?php\nuse Multiapi\\Arco\\Config\\App;\nreturn App::make('shop')->region('us-east-1')->maxEnvironments(3)->environment('production');\n");

        try {
            (new ConfigLoader())->load($path);
            self::fail('maxEnvironments() is gone');
        } catch (ConfigException $e) {
            self::assertSame('config.removed_max_environments', $e->key);
            self::assertSame(['value' => '3'], $e->params);
        }
    }

    public function test_a_removed_console_timeout_call_names_its_replacement(): void
    {
        $path = $this->write("<?php\nuse Multiapi\\Arco\\Config\\App;\nuse Multiapi\\Arco\\Config\\Environment;\nreturn App::make('shop')->region('us-east-1')->environment('production', fn (Environment \$e) => \$e->consoleTimeout(60));\n");

        try {
            (new ConfigLoader())->load($path);
            self::fail('consoleTimeout() is gone');
        } catch (ConfigException $e) {
            self::assertSame('config.removed_console_timeout', $e->key);
            self::assertSame(['value' => '60'], $e->params);
        }
    }

    /** @return iterable<string, array{0: string, 1: string, 2: array<string, string>}> */
    public static function changedSignatures(): iterable
    {
        yield 'binary(path:)' => ["App::make('shop')->region('us-east-1')->runtime(fn (\\Multiapi\\Arco\\Config\\Runtime \$r) => \$r->binary(path: 'bin/frankenphp'))", 'config.changed_binary_path', []];
        yield 'dns(profile:)' => ["App::make('shop')->region('us-east-1')->dns(profile: 'dns')", 'config.changed_dns_profile', []];
        yield 'cache(null)' => ["App::make('shop')->region('us-east-1')->cache(null)", 'config.changed_null_table', ['method' => 'cache']];
        yield 'failedJobsTable(null)' => ["App::make('shop')->region('us-east-1')->failedJobsTable(null)", 'config.changed_null_table', ['method' => 'failedJobsTable']];
    }

    /** @param array<string, string> $params */
    #[\PHPUnit\Framework\Attributes\DataProvider('changedSignatures')]
    public function test_a_signature_changed_in_0_9_says_what_to_write_now(string $chain, string $key, array $params): void
    {
        $path = $this->write("<?php\nuse Multiapi\\Arco\\Config\\App;\nreturn {$chain}->environment('production');\n");

        try {
            (new ConfigLoader())->load($path);
            self::fail('the old signature no longer works');
        } catch (ConfigException $e) {
            self::assertSame($key, $e->key);
            self::assertSame($params, $e->params);
        }
        foreach (['en', 'es'] as $lang) {
            $text = (new \Multiapi\Arco\Cli\Translator($lang, \Multiapi\Arco\Arco::packageRoot() . '/lang'))->trans($key, $params);
            self::assertNotSame($key, $text, $lang);
        }
        $en = (new \Multiapi\Arco\Cli\Translator('en', \Multiapi\Arco\Arco::packageRoot() . '/lang'))->trans($key, $params);
        self::assertStringContainsString(match ($key) {
            'config.changed_binary_path' => 'binary(frankenphp: ..., php: ...) since 0.9',
            'config.changed_dns_profile' => 'dns(dns: ...)',
            default => 'false to turn it off',
        }, $en);
    }

    public function test_other_unknown_named_parameters_and_type_errors_keep_php_s_text(): void
    {
        foreach (["App::make('shop')->region('us-east-1')->timeout(secs: 3)", "App::make('shop')->region('us-east-1')->console(null)"] as $chain) {
            $path = $this->write("<?php\nuse Multiapi\\Arco\\Config\\App;\nreturn {$chain}->environment('production');\n");
            try {
                (new ConfigLoader())->load($path);
                self::fail('expected an error');
            } catch (ConfigException $e) {
                self::assertSame('config.failed', $e->key);
            }
        }
    }

    public function test_other_undefined_methods_keep_the_generic_error(): void
    {
        $path = $this->write("<?php\nuse Multiapi\\Arco\\Config\\App;\nreturn App::make('shop')->nope()->environment('production');\n");

        $this->expectException(ConfigException::class);
        try {
            (new ConfigLoader())->load($path);
        } catch (ConfigException $e) {
            self::assertSame('config.failed', $e->key);
            throw $e;
        }
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
