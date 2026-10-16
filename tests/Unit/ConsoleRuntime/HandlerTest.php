<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\ConsoleRuntime;

use Multiapi\Arco\ConsoleRuntime\Handler;
use Multiapi\Arco\ConsoleRuntime\InvalidEvent;
use Multiapi\Arco\ConsoleRuntime\ProcessRunner;
use PHPUnit\Framework\TestCase;

final class HandlerTest extends TestCase
{
    private string $app;
    /** @var list<array<string, mixed>> */
    private array $logged = [];
    private string $echoed = '';

    protected function setUp(): void
    {
        $this->app = sys_get_temp_dir() . '/arco-handler-' . bin2hex(random_bytes(4));
        mkdir("$this->app/scripts", 0777, true);
        // A stand-in for artisan: prints its arguments and stdin, fails with --fail, sleeps with --slow.
        file_put_contents("$this->app/artisan", <<<'PHP'
            <?php
            $args = array_slice($argv, 1);
            echo 'args:', json_encode($args), "\n";
            if (($args[0] ?? '') === 'arco:tinker') {
                $stdin = stream_get_contents(STDIN);
                echo 'stdin:', $stdin, "\n";
                // The statement decides the reply: 'throw' is an exception in it (exit 3 with the reply
                // line), 'failure' a reply arco:tinker refused (exit 1 with the line), 'boot' a failure
                // before any reply (exit 1, no line), 'bare3' exit 3 without the line, 'fatal' a PHP fatal error.
                $code = base64_decode(json_decode($stdin, true)['code']);
                if (in_array($code, ['throw', 'failure'], true)) { echo "ARCO-TINKER:e30=\n"; }
                exit(match ($code) { 'throw', 'bare3' => 3, 'failure', 'boot' => 1, 'fatal' => 255, default => 0 });
            }
            if (in_array('--slow', $args, true)) { sleep(30); }
            exit(in_array('--fail', $args, true) ? 3 : 0);
            PHP);
        file_put_contents("$this->app/scripts/job.php", '<?php echo "job:", json_encode(array_slice($argv, 1));');
    }

    private function handler(?string $entry = 'artisan'): Handler
    {
        return new Handler(new ProcessRunner(function (string $chunk): void { $this->echoed .= $chunk; }), [PHP_BINARY], $this->app, $entry, [], function (array $line): void { $this->logged[] = $line; });
    }

    public function test_runs_the_command_through_the_entry_script(): void
    {
        $result = $this->handler()->handle('{"command":"migrate --path=\"db/a b\""}', 60_000);

        self::assertSame(0, $result['exitCode']);
        self::assertSame("args:[\"migrate\",\"--path=db\\/a b\"]\n", $result['output']);
        self::assertSame(['arco' => 'command', 'command' => 'migrate --path="db/a b"'], $this->logged[0]);
        self::assertSame($result['output'], $this->echoed, 'command output is echoed to the function log');
    }

    public function test_a_failing_command_is_a_normal_result_and_is_logged(): void
    {
        $result = $this->handler()->handle('{"command":"migrate --fail"}', 60_000);

        self::assertSame(3, $result['exitCode']);
        self::assertSame('command_failed', $this->logged[1]['arco']);
        self::assertSame(3, $this->logged[1]['exitCode']);
        self::assertIsInt($this->logged[1]['durationMs'], 'the invocation duration, unlike ms (time since the runtime started)');
    }

    public function test_plain_php_runs_the_script_named_first(): void
    {
        $result = $this->handler(null)->handle('{"command":"scripts/job.php --x"}', 60_000);

        self::assertSame('job:["--x"]', $result['output']);
    }

    public function test_a_blank_command_is_an_invalid_event(): void
    {
        $this->expectException(InvalidEvent::class);
        $this->handler(null)->handle('{"command":"   "}', 60_000);
    }

    public function test_the_time_limit_is_the_remaining_time_minus_three_seconds(): void
    {
        // Three seconds: the 2 s SIGTERM grace and the response still fit before the Lambda deadline.
        $started = microtime(true);

        $result = $this->handler()->handle('{"command":"x --slow"}', 5_000);

        self::assertSame(124, $result['exitCode']);
        self::assertStringContainsString('[arco] Stopped after 2 s', $result['output']);
        self::assertLessThan(5, microtime(true) - $started);
    }

    public function test_the_event_timeout_can_only_lower_the_limit(): void
    {
        $result = $this->handler()->handle('{"command":"x --slow","timeout":1}', 600_000);

        self::assertSame(124, $result['exitCode']);
    }

    public function test_secret_looking_option_values_are_masked_in_the_function_log(): void
    {
        $result = $this->handler()->handle('{"command":"migrate --fail --password=hunter2 --api-key abc --name=Ada"}', 60_000);

        self::assertSame(3, $result['exitCode']);
        self::assertSame("args:[\"migrate\",\"--fail\",\"--password=hunter2\",\"--api-key\",\"abc\",\"--name=Ada\"]\n", $result['output'], 'the command itself gets the real values');
        self::assertSame(['arco' => 'command', 'command' => 'migrate --fail --password=*** --api-key *** --name=Ada'], $this->logged[0]);
        self::assertSame(['migrate', '--fail', '--password=***', '--api-key', '***', '--name=Ada'], $this->logged[1]['arguments']);
        self::assertStringNotContainsString('hunter2', (string) json_encode($this->logged));
    }

    public function test_an_unclosed_quote_is_reported_as_exit_code_2(): void
    {
        $result = $this->handler()->handle('{"command":"migrate \'oops"}', 60_000);

        self::assertSame(2, $result['exitCode']);
        self::assertStringContainsString('unclosed', $result['output']);
    }

    public function test_a_nul_byte_in_the_command_is_a_normal_error_result(): void
    {
        // proc_open throws ValueError on NUL bytes in arguments: it must not escape as a runtime error.
        $result = $this->handler()->handle('{"command":"migrate --path=a\u0000b"}', 60_000);

        self::assertSame(2, $result['exitCode']);
        self::assertStringContainsString('NUL byte', $result['output']);
        self::assertSame('command_failed', $this->logged[1]['arco']);
    }

    public function test_tinker_runs_arco_tinker_with_the_request_on_stdin_and_audits_the_code(): void
    {
        $result = $this->handler()->handle((string) json_encode(['tinker' => ['code' => base64_encode('User::count()'), 'context' => null]]), 60_000);

        self::assertStringContainsString('args:["arco:tinker"]', $result['output']);
        self::assertStringContainsString('stdin:{"code":"' . base64_encode('User::count()') . '","context":null}', $result['output']);
        self::assertSame(['arco' => 'tinker', 'code' => 'User::count()', 'sha256' => hash('sha256', 'User::count()'), 'length' => 13], $this->logged[0]);
        self::assertSame('tinker_done', $this->logged[1]['arco']);
        self::assertSame(0, $this->logged[1]['exitCode']);
        self::assertIsInt($this->logged[1]['durationMs']);
    }

    public function test_a_tinker_statement_that_throws_is_normal_repl_behaviour_not_a_failed_command(): void
    {
        $result = $this->handler()->handle($this->tinker('throw'), 60_000);

        self::assertSame(3, $result['exitCode']);
        self::assertSame(['tinker', 'tinker_done'], array_column($this->logged, 'arco'));
        self::assertSame(3, $this->logged[1]['exitCode']);
    }

    /** Exit 1 is also Laravel failing to boot, or arco:tinker failing before or after the statement. */
    public function test_tinker_exit_1_without_a_reply_line_is_a_failed_command(): void
    {
        $this->handler()->handle($this->tinker('boot'), 60_000);

        self::assertSame(['tinker', 'tinker_done', 'command_failed'], array_column($this->logged, 'arco'));
        self::assertSame(1, $this->logged[2]['exitCode']);
    }

    public function test_a_reply_arco_tinker_refused_is_a_failed_command(): void
    {
        // failure() replies (bad input, psysh missing) carry the reply line but exit with 1.
        $this->handler()->handle($this->tinker('failure'), 60_000);

        self::assertSame(['tinker', 'tinker_done', 'command_failed'], array_column($this->logged, 'arco'));
    }

    public function test_the_statement_exit_code_without_a_reply_line_is_a_failed_command(): void
    {
        $this->handler()->handle($this->tinker('bare3'), 60_000);

        self::assertSame(['tinker', 'tinker_done', 'command_failed'], array_column($this->logged, 'arco'));
    }

    private function tinker(string $code): string
    {
        return (string) json_encode(['tinker' => ['code' => base64_encode($code), 'context' => null]]);
    }

    public function test_a_tinker_crash_is_still_a_failed_command_with_its_duration(): void
    {
        $this->handler()->handle((string) json_encode(['tinker' => ['code' => base64_encode('fatal'), 'context' => null]]), 60_000);

        self::assertSame(['tinker', 'tinker_done', 'command_failed'], array_column($this->logged, 'arco'));
        self::assertSame(255, $this->logged[2]['exitCode']);
        self::assertIsInt($this->logged[2]['durationMs']);
        self::assertGreaterThanOrEqual(0, $this->logged[2]['durationMs']);
    }

    public function test_tinker_output_is_never_echoed_to_the_function_log(): void
    {
        // The reply carries the result and the serialized scope: secrets and PII must stay out of CloudWatch.
        $result = $this->handler()->handle((string) json_encode(['tinker' => ['code' => base64_encode('config("app.key")'), 'context' => null]]), 60_000);

        self::assertStringContainsString('stdin:', $result['output']);
        self::assertSame('', $this->echoed);
    }

    public function test_a_long_tinker_statement_is_audited_truncated_with_the_hash_and_length_of_the_whole_code(): void
    {
        $code = str_repeat('$a = 1; ', 600) . 'DB::table("users")->delete();';

        $this->handler()->handle((string) json_encode(['tinker' => ['code' => base64_encode($code), 'context' => null]]), 60_000);

        self::assertSame(substr($code, 0, 4000), $this->logged[0]['code']);
        self::assertSame(hash('sha256', $code), $this->logged[0]['sha256']);
        self::assertSame(strlen($code), $this->logged[0]['length']);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidEvents(): iterable
    {
        yield 'not json' => ['nope'];
        yield 'a list' => ['["about"]'];
        yield 'no command' => ['{"cmd":"about"}'];
        yield 'command not a string' => ['{"command":["about"]}'];
        yield 'tinker without code' => ['{"tinker":{"context":null}}'];
        yield 'tinker code not base64' => ['{"tinker":{"code":"***","context":null}}'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidEvents')]
    public function test_invalid_events_are_rejected(string $body): void
    {
        $this->expectException(InvalidEvent::class);
        $this->handler()->handle($body, 60_000);
    }

    public function test_tinker_needs_an_entry_script(): void
    {
        $this->expectException(InvalidEvent::class);
        $this->handler(null)->handle((string) json_encode(['tinker' => ['code' => base64_encode('1'), 'context' => null]]), 60_000);
    }
}
