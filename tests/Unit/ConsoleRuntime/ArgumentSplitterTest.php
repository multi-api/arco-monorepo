<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\ConsoleRuntime;

use Multiapi\Arco\ConsoleRuntime\ArgumentSplitter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ArgumentSplitterTest extends TestCase
{
    /** @return iterable<string, array{string, list<string>}> */
    public static function lines(): iterable
    {
        yield 'words' => ['migrate --force', ['migrate', '--force']];
        yield 'extra spaces and tabs' => ["  migrate\t --force  ", ['migrate', '--force']];
        yield 'double quotes keep backslashes' => ['db:seed --class="Database\Seeders\UserSeeder"', ['db:seed', '--class=Database\Seeders\UserSeeder']];
        yield 'single quotes are literal' => ["tinker --execute='echo \"hi\";'", ['tinker', '--execute=echo "hi";']];
        yield 'escaped quote inside double quotes' => ['say "a \"b\" c"', ['say', 'a "b" c']];
        yield 'backslash escapes a space' => ['say a\ b', ['say', 'a b']];
        yield 'empty quoted arguments' => ["x '' \"\"", ['x', '', '']];
        yield 'shell syntax stays literal' => ['about; rm -rf / $(id) `id` | cat > f && x', ['about;', 'rm', '-rf', '/', '$(id)', '`id`', '|', 'cat', '>', 'f', '&&', 'x']];
        yield 'variables are not expanded' => ['echo "$HOME" $PATH', ['echo', '$HOME', '$PATH']];
        yield 'empty line' => ['   ', []];
    }

    /** @param list<string> $expected */
    #[DataProvider('lines')]
    public function test_splits_like_a_shell_without_interpreting_it(string $line, array $expected): void
    {
        self::assertSame($expected, ArgumentSplitter::split($line));
    }

    public function test_an_unclosed_quote_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unclosed');
        ArgumentSplitter::split("migrate --path='database/x");
    }

    public function test_a_trailing_backslash_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ArgumentSplitter::split('migrate \\');
    }
}
