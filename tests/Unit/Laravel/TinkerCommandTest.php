<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Laravel;

use Illuminate\Console\Application as Artisan;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Multiapi\Arco\Laravel\ArcoServiceProvider;
use Multiapi\Arco\Laravel\Console\TinkerCommand;
use Multiapi\Arco\Laravel\Tinker\StatementRunner;
use PHPUnit\Framework\TestCase;

final class TinkerCommandTest extends TestCase
{
    /** The console runtime keeps the last 5 MiB of a command's output (OutputBuffer::LIMIT in multiapi/arco). */
    private const RUNTIME_BUFFER_BYTES = 5 * 1024 * 1024;

    protected function tearDown(): void
    {
        unset($_SERVER['ARCO']);
        Artisan::forgetBootstrappers();
    }

    /** @return array{output: string, presented: string|null, context: string|null, dropped: list<string>, exception: array{class: string, message: string}|null} */
    private function runStatement(string $code, ?string $context = null): array
    {
        return (new StatementRunner())->run($code, $context);
    }

    /** @return array<string, mixed> */
    private static function decode(string $line): array
    {
        $reply = json_decode((string) base64_decode(substr($line, strlen(TinkerCommand::MARKER)), true), true);
        self::assertIsArray($reply);
        return $reply;
    }

    public function test_variables_persist_through_the_serialized_context(): void
    {
        $first = $this->runStatement('$answer = 40 + 2');
        self::assertSame('42', $first['presented']);
        self::assertNull($first['exception']);

        $second = $this->runStatement('$answer + 1', $first['context']);
        self::assertSame('43', $second['presented']);
    }

    public function test_output_is_captured_and_statements_without_a_value_present_nothing(): void
    {
        $reply = $this->runStatement('echo "hello";');

        self::assertSame('hello', $reply['output']);
        self::assertNull($reply['presented']);
    }

    public function test_presented_values_are_plain_text(): void
    {
        $presented = (string) $this->runStatement('["a" => 1]')['presented'];

        self::assertStringContainsString('"a" => 1', $presented);
        self::assertStringNotContainsString('<number>', $presented);
    }

    public function test_closures_and_unserializable_objects_are_dropped_with_their_names(): void
    {
        $reply = $this->runStatement('$f = fn () => 1; $file = new SplFileObject("php://memory"); $n = 5;');

        self::assertSame(['f', 'file'], $reply['dropped']);
        self::assertSame('6', $this->runStatement('$n + 1', $reply['context'])['presented']);
    }

    public function test_resources_are_dropped_not_turned_into_zero(): void
    {
        // serialize() turns a resource into i:0 without an error.
        $reply = $this->runStatement('$handle = fopen("php://memory", "r"); $list = [fopen("php://memory", "r")]; $n = 1;');

        self::assertSame(['handle', 'list'], $reply['dropped']);
        self::assertSame(['n' => 1], StatementRunner::restore($reply['context']));
    }

    public function test_an_exception_is_reported_and_the_earlier_scope_is_kept(): void
    {
        $first = $this->runStatement('$a = 1');

        $reply = $this->runStatement('throw new RuntimeException("boom");', $first['context']);

        self::assertSame(['class' => 'RuntimeException', 'message' => 'boom'], $reply['exception']);
        self::assertSame(['a' => 1], StatementRunner::restore($reply['context']));
    }

    public function test_a_scope_over_the_size_limit_is_not_kept(): void
    {
        $reply = $this->runStatement('$big = str_repeat("x", 5000000); $small = 1;');

        self::assertNull($reply['context']);
        self::assertEqualsCanonicalizing(['big', 'small'], $reply['dropped']);
    }

    public function test_a_context_of_3_to_4_mb_is_dropped_by_name_and_the_reply_still_arrives(): void
    {
        // 2.5 MB of text serializes to a base64 context of about 3.3 MB: above the limit.
        [$exitCode, $line] = TinkerCommand::reply((string) json_encode(['code' => base64_encode('$big = str_repeat("x", 2500000); $small = 1;'), 'context' => null]));

        self::assertSame(0, $exitCode);
        self::assertLessThanOrEqual(self::RUNTIME_BUFFER_BYTES, strlen($line) + 1);
        $reply = self::decode($line);
        self::assertNull($reply['context']);
        self::assertEqualsCanonicalizing(['big', 'small'], $reply['dropped']);
    }

    public function test_output_is_capped_keeping_the_tail(): void
    {
        $output = $this->runStatement('echo str_repeat("a", 1500000), "the end";')['output'];

        self::assertLessThanOrEqual(StatementRunner::MAX_OUTPUT_BYTES, strlen($output));
        self::assertStringStartsWith(StatementRunner::TRUNCATED, $output);
        self::assertStringEndsWith('the end', $output);
    }

    public function test_a_kept_context_near_the_limit_with_large_output_still_fits_the_runtime_buffer(): void
    {
        // A context just under MAX_CONTEXT_BYTES plus 1 MB of output would be a 5.3 MB line: the output gives way.
        [$exitCode, $line] = TinkerCommand::reply((string) json_encode(['code' => base64_encode('$big = str_repeat("x", 2200000); echo str_repeat("\u{1}", 2000000), "the end";'), 'context' => null]));

        self::assertSame(0, $exitCode);
        self::assertLessThanOrEqual(self::RUNTIME_BUFFER_BYTES, strlen($line) + 1);
        $reply = self::decode($line);
        self::assertIsString($reply['context']);
        self::assertSame(2200000, strlen(StatementRunner::restore($reply['context'])['big']));
        self::assertIsString($reply['output']);
        self::assertStringStartsWith(StatementRunner::TRUNCATED, $reply['output']);
        self::assertStringEndsWith('the end', $reply['output']);
    }

    public function test_a_huge_presented_value_is_cut_to_fit_the_runtime_buffer(): void
    {
        [, $line] = TinkerCommand::reply((string) json_encode(['code' => base64_encode('str_repeat("\u{1}", 4000000)'), 'context' => null]));

        self::assertLessThanOrEqual(self::RUNTIME_BUFFER_BYTES, strlen($line) + 1);
        $reply = self::decode($line);
        self::assertIsString($reply['presented']);
        self::assertStringEndsWith(StatementRunner::TRUNCATED, $reply['presented']);
    }

    public function test_a_broken_context_starts_an_empty_scope(): void
    {
        self::assertSame([], StatementRunner::restore('not base64 at all ***'));
        self::assertSame([], StatementRunner::restore(base64_encode('garbage')));
    }

    public function test_reply_is_one_marked_line_with_the_exit_code(): void
    {
        [$exitCode, $line] = TinkerCommand::reply((string) json_encode(['code' => base64_encode('1 + 1'), 'context' => null]));

        self::assertSame(0, $exitCode);
        self::assertStringStartsWith(TinkerCommand::MARKER, $line);
        self::assertStringNotContainsString("\n", $line);
        self::assertSame('2', self::decode($line)['presented']);

        [$exitCode, $line] = TinkerCommand::reply('nope');
        self::assertSame(1, $exitCode);
        $exception = self::decode($line)['exception'];
        self::assertIsArray($exception);
        self::assertSame('InvalidArgumentException', $exception['class']);
    }

    public function test_the_provider_registers_arco_tinker_on_arco_only(): void
    {
        $app = new Application(sys_get_temp_dir());
        (new ArcoServiceProvider($app))->boot();
        self::assertFalse((new Artisan($app, new Dispatcher($app), 'test'))->has('arco:tinker'));

        $_SERVER['ARCO'] = '1';
        (new ArcoServiceProvider($app))->boot();
        $artisan = new Artisan($app, new Dispatcher($app), 'test');
        self::assertTrue($artisan->has('arco:tinker'));
        self::assertTrue($artisan->get('arco:tinker')->isHidden());
    }
}
