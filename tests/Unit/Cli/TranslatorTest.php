<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Cli;

use Multiapi\Arco\Cli\Translator;
use Multiapi\Arco\Tests\Support\TempPaths;
use PHPUnit\Framework\TestCase;

final class TranslatorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = TempPaths::path('arco-lang-');
        mkdir($this->dir);
        file_put_contents($this->dir . '/en.php', "<?php return ['hello' => 'Hello {name}', 'only_en' => 'English only'];");
        file_put_contents($this->dir . '/es.php', "<?php return ['hello' => 'Hola {name}'];");
    }

    public function test_translates_with_placeholders(): void
    {
        self::assertSame('Hola Ana', (new Translator('es', $this->dir))->trans('hello', ['name' => 'Ana']));
        self::assertSame('Hello Ana', (new Translator('en', $this->dir))->trans('hello', ['name' => 'Ana']));
    }

    public function test_falls_back_to_english_then_key(): void
    {
        $es = new Translator('es', $this->dir);
        self::assertSame('English only', $es->trans('only_en'));
        self::assertSame('missing.key', $es->trans('missing.key'));
    }
}
