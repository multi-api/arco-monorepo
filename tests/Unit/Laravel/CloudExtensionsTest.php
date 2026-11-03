<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Laravel;

use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Multiapi\Arco\Laravel\ArcoServiceProvider;
use Multiapi\Arco\Laravel\CloudExtensions;
use Multiapi\Arco\Tests\Support\TempPaths;
use PHPUnit\Framework\TestCase;

final class CloudExtensionsTest extends TestCase
{
    protected function tearDown(): void
    {
        ArcoServiceProvider::$log = null;
        unset($_SERVER['ARCO']);
    }

    /** An app directory with one extension: its bootstrap.php holds $bootstrap. Returns the base path. */
    private static function appWith(string $name, string $bootstrap): string
    {
        $base = TempPaths::path('arco-ext-');
        mkdir("$base/.arco-cloud/$name", 0777, true);
        file_put_contents("$base/.arco-cloud/$name/bootstrap.php", $bootstrap);
        return $base;
    }

    /** A bootstrap that declares a provider with a unique name and returns it; the name is set in $class. */
    private static function providerBootstrap(?string &$class): string
    {
        $short = 'ExtProvider' . bin2hex(random_bytes(4));
        $class = "Multiapi\\Arco\\Tests\\Unit\\Laravel\\Generated\\$short";
        return "<?php\nnamespace Multiapi\\Arco\\Tests\\Unit\\Laravel\\Generated;\n"
            . "class $short extends \\Illuminate\\Support\\ServiceProvider { public function register(): void { \$this->app->instance('ext.ran', true); } }\n"
            . "return $short::class;\n";
    }

    private static function boot(string $base): Application
    {
        $app = new Application($base);
        $app->instance('config', new Repository([]));
        (new ArcoServiceProvider($app))->register();
        return $app;
    }

    public function test_registers_the_provider_an_extension_bootstrap_returns(): void
    {
        $_SERVER['ARCO'] = '1';
        $base = self::appWith('demo', self::providerBootstrap($class));

        $app = self::boot($base);

        self::assertTrue($app->bound('ext.ran'));
        self::assertCount(1, $app->getProviders($class));
            }

    public function test_ignores_extensions_when_arco_is_not_1(): void
    {
        unset($_SERVER['ARCO']);
        putenv('ARCO');
        $base = self::appWith('demo', self::providerBootstrap($class));

        $app = self::boot($base);

        self::assertFalse($app->bound('ext.ran'));
    }

    public function test_skips_a_bootstrap_that_throws_or_returns_a_non_provider_without_failing_boot(): void
    {
        $_SERVER['ARCO'] = '1';
        $lines = [];
        ArcoServiceProvider::$log = static function (array $line) use (&$lines): void { $lines[] = $line; };
        $base = self::appWith('a-throws', "<?php\nthrow new \\RuntimeException('boom');\n");
        mkdir("$base/.arco-cloud/b-string", 0777, true);
        file_put_contents("$base/.arco-cloud/b-string/bootstrap.php", "<?php\nreturn 'No\\\\Such\\\\Provider';\n");
        mkdir("$base/.arco-cloud/c-stdclass", 0777, true);
        file_put_contents("$base/.arco-cloud/c-stdclass/bootstrap.php", "<?php\nreturn \\stdClass::class;\n");
        mkdir("$base/.arco-cloud/d-ok", 0777, true);
        file_put_contents("$base/.arco-cloud/d-ok/bootstrap.php", self::providerBootstrap($class));

        // The bootstrap files are required once per process, so providers() and boot() each get their own copy.
        self::assertSame([$class], CloudExtensions::providers($base));
        $app = self::boot(self::appWith('d-ok', self::providerBootstrap($ok)));
        self::assertTrue($app->bound('ext.ran'));

        $names = array_values(array_unique(array_map(static fn (array $l): mixed => $l['extension'] ?? null, $lines)));
        self::assertSame(['a-throws', 'b-string', 'c-stdclass'], $names);
        self::assertSame('extension_failed', $lines[0]['arco']);
    }

    public function test_no_arco_cloud_directory_registers_nothing(): void
    {
        $_SERVER['ARCO'] = '1';
        $base = TempPaths::path('arco-ext-');
        mkdir($base);

        self::assertSame([], CloudExtensions::providers($base));
        self::assertFalse(self::boot($base)->bound('ext.ran'));
    }
}
