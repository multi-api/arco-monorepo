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
        yield 'a separate value starting with a dash' => [['x', '--password', '-s3cret', '--force'], ['x', '--password', '***', '--force']];
        yield 'words split by dashes, underscores and case' => [
            ['x', '--auth-token=1', '--secret=2', '--dbPassword=3', '--SECRETS=4', '--access_tokens=5'],
            ['x', '--auth-token=***', '--secret=***', '--dbPassword=***', '--SECRETS=***', '--access_tokens=***'],
        ];
        yield 'names that only contain a secret word are kept' => [
            ['x', '--author=Ada', '--monkey=1', '--keyboard', 'us', '--passport=X', '--tokenizer=simple', '--authority', 'a'],
            ['x', '--author=Ada', '--monkey=1', '--keyboard', 'us', '--passport=X', '--tokenizer=simple', '--authority', 'a'],
        ];
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
        // Without a split nothing tells where the value ends: everything from the option on is masked.
        self::assertSame('x --password=***', SecretArguments::maskLine("x --password='hunter 2 --name=Ada"));
        self::assertSame('x --name=Ada --token ***', SecretArguments::maskLine("x --name=Ada --token 'hunter 2 --x"));
        self::assertSame("x --name='Ada", SecretArguments::maskLine("x --name='Ada"));
        self::assertSame("x --author='Ada", SecretArguments::maskLine("x --author='Ada"));
    }
}
