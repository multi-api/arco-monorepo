<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Cli;

use Multiapi\Arco\Cli\LocaleResolver;
use Multiapi\Arco\Tests\Support\TempPaths;
use PHPUnit\Framework\TestCase;

final class LocaleResolverTest extends TestCase
{
    public function test_precedence_flag_env_global_default(): void
    {
        $global = TempPaths::path('arco-global-', '.php');
        file_put_contents($global, "<?php return ['lang' => 'es'];");

        self::assertSame('es', LocaleResolver::resolve('es', ['ARCO_LANG' => 'en'], $global));
        self::assertSame('en', LocaleResolver::resolve(null, ['ARCO_LANG' => 'en'], $global));
        self::assertSame('es', LocaleResolver::resolve(null, [], $global));
        self::assertSame('en', LocaleResolver::resolve(null, [], '/nonexistent/config.php'));
    }

    public function test_unknown_locales_fall_back_to_english(): void
    {
        self::assertSame('en', LocaleResolver::resolve('fr', [], '/nonexistent'));
        self::assertSame('es', LocaleResolver::resolve('ES', [], '/nonexistent'));
    }
}
