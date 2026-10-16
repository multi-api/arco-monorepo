<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\ConsoleRuntime;

use Multiapi\Arco\ConsoleRuntime\SecretArguments;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecretArgumentsTest extends TestCase
{
    /** @return iterable<string, array{0: list<string>, 1: list<string>}> */
    public static function arguments(): iterable
    {
        yield 'equals form' => [['user:create', '--password=hunter2', '--name=Ada'], ['user:create', '--password=***', '--name=Ada']];
        yield 'separate value' => [['user:create', '--password', 'hunter2', '--name', 'Ada'], ['user:create', '--password', '***', '--name', 'Ada']];
        yield 'case-insensitive' => [['x', '--API-KEY=k', '--Token', 't'], ['x', '--API-KEY=***', '--Token', '***']];
        yield 'every secret-looking name' => [
            ['x', '--passwd=1', '--client-secret=2', '--key=3', '--apikey=4', '--api_key=5', '--credentials=6', '--auth=7', '--private-key=8', '--db-password=9'],
            ['x', '--passwd=***', '--client-secret=***', '--key=***', '--apikey=***', '--api_key=***', '--credentials=***', '--auth=***', '--private-key=***', '--db-password=***'],
        ];
        yield 'a flag followed by another option keeps it' => [['x', '--token', '--force'], ['x', '--token', '--force']];
        yield 'a trailing flag' => [['x', '--secret'], ['x', '--secret']];
        yield 'ordinary arguments are kept' => [['migrate', '--force', '--path=db/a b', 'password'], ['migrate', '--force', '--path=db/a b', 'password']];
        yield 'empty value' => [['x', '--password='], ['x', '--password=***']];
    }

    /**
     * @param list<string> $arguments
     * @param list<string> $expected
     */
    #[DataProvider('arguments')]
    public function test_masks_values_of_secret_looking_options(array $arguments, array $expected): void
    {
        self::assertSame($expected, SecretArguments::mask($arguments));
    }

    public function test_a_command_line_is_kept_as_written_unless_something_is_masked(): void
    {
        self::assertSame('migrate --path="db/a b"', SecretArguments::maskLine('migrate --path="db/a b"'));
        self::assertSame("user:create '--name=Ada L' --password=*** --role admin", SecretArguments::maskLine('user:create --name="Ada L" --password="s3 cr3t" --role admin'));
        self::assertSame('user:create --token ***', SecretArguments::maskLine("user:create --token 'abc def'"));
    }

    public function test_a_line_that_cannot_be_split_is_still_masked(): void
    {
        self::assertSame("x --password=*** 'oops", SecretArguments::maskLine("x --password=hunter2 'oops"));
        self::assertSame("x --token *** 'oops", SecretArguments::maskLine("x --token hunter2 'oops"));
    }
}
