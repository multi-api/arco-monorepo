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

    public function test_every_days_count_message_has_a_singular_one_day_sibling(): void
    {
        foreach (['en', 'es'] as $locale) {
            /** @var array<string, string> $messages */
            $messages = require Arco::packageRoot() . '/lang/' . $locale . '.php';
            foreach ($messages as $key => $text) {
                if (preg_match('/\{days\}\s+(days|días)\b/u', $text) !== 1) {
                    continue;
                }
                $singular = preg_replace('/_days$/', '', $key) . '_one_day';
                self::assertArrayHasKey($singular, $messages, "$locale: $key needs a singular $singular");
                self::assertStringNotContainsString('{days}', $messages[$singular]);
                self::assertMatchesRegularExpression('/\b1 (day|día)\b/u', $messages[$singular], "$locale: $singular must say 1 day");
            }
        }
    }
}
