<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Cli;

use Multiapi\Arco\Arco;
use PHPUnit\Framework\TestCase;

final class LangParityTest extends TestCase
{
    public function test_english_and_spanish_have_the_same_keys(): void
    {
        /** @var array<string, string> $en */
        $en = require Arco::packageRoot() . '/lang/en.php';
        /** @var array<string, string> $es */
        $es = require Arco::packageRoot() . '/lang/es.php';

        self::assertSame([], array_keys(array_diff_key($en, $es)), 'Keys missing in es.php');
        self::assertSame([], array_keys(array_diff_key($es, $en)), 'Keys missing in en.php');

        foreach ($en as $key => $text) {
            preg_match_all('/\{(\w+)\}/', $text, $enParams);
            preg_match_all('/\{(\w+)\}/', $es[$key], $esParams);
            sort($enParams[1]);
            sort($esParams[1]);
            self::assertSame($enParams[1], $esParams[1], "Placeholders differ for $key");
        }
    }
}
