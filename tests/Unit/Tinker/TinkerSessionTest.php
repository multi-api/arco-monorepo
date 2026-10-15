<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Tinker;

use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Console\CommandResult;
use Multiapi\Arco\Laravel\Console\TinkerCommand;
use Multiapi\Arco\Tests\Unit\Commands\FakeConsole;
use Multiapi\Arco\Tinker\TinkerReply;
use Multiapi\Arco\Tinker\TinkerSession;
use PHPUnit\Framework\TestCase;

final class TinkerSessionTest extends TestCase
{
    public static function config(): AppConfig
    {
        return ConfigResolver::resolve(App::make('shop')->region('us-east-1')->environment('staging')->toArray(), 'staging');
    }

    public function test_the_context_of_each_reply_goes_with_the_next_statement(): void
    {
        $console = new FakeConsole([
            FakeConsole::tinkerReply(['presented' => '42', 'context' => 'ctx-1']),
            FakeConsole::tinkerReply(['presented' => '43', 'context' => 'ctx-2']),
        ]);
        $session = new TinkerSession($console, self::config());

        self::assertSame('42', $session->execute('$a = 42')->presented);
        self::assertSame('43', $session->execute('$a + 1')->presented);

        self::assertSame([['tinker', '$a = 42', null, null], ['tinker', '$a + 1', 'ctx-1', null]], $console->calls);
        self::assertSame('ctx-2', $session->context());
    }

    public function test_a_crash_keeps_the_previous_context(): void
    {
        $console = new FakeConsole([
            FakeConsole::tinkerReply(['context' => 'ctx-1']),
            new CommandResult(1, '', 'Sandbox.Timedout', 'Task timed out after 900.00 seconds'),
        ]);
        $session = new TinkerSession($console, self::config());
        $session->execute('$a = 1');

        $reply = $session->execute('sleep(1000)');

        self::assertFalse($reply->replied);
        self::assertSame(['class' => 'Sandbox.Timedout', 'message' => 'Task timed out after 900.00 seconds'], $reply->exception);
        self::assertSame('ctx-1', $session->context());
    }

    public function test_output_around_the_result_line_is_kept_apart(): void
    {
        $reply = TinkerReply::parse(FakeConsole::tinkerReply(['output' => 'hi', 'dropped' => ['f']], 0, "PHP Deprecated: old call\n"));

        self::assertTrue($reply->replied);
        self::assertSame('PHP Deprecated: old call', $reply->extra);
        self::assertSame('hi', $reply->output);
        self::assertSame(['f'], $reply->dropped);
    }

    public function test_an_app_without_arco_tinker_gives_no_reply(): void
    {
        $reply = TinkerReply::parse(new CommandResult(1, "There are no commands defined in the \"arco\" namespace.\n"));

        self::assertFalse($reply->replied);
        self::assertSame(TinkerReply::NO_REPLY, $reply->exception['class'] ?? null);
        self::assertStringContainsString('no commands defined', $reply->exception['message'] ?? '');
    }

    public function test_a_failing_restore_without_marker_keeps_the_raw_output_and_exit_code(): void
    {
        $console = new FakeConsole([
            FakeConsole::tinkerReply(['context' => 'ctx-1']),
            new CommandResult(255, "PHP Fatal error:  Uncaught Error: Class \"Gone\" not found\n"),
        ]);
        $session = new TinkerSession($console, self::config());
        $session->execute('$a = new Gone()');

        $reply = $session->execute('$a');

        self::assertFalse($reply->replied);
        self::assertSame(255, $reply->exitCode);
        self::assertSame(TinkerReply::NO_REPLY, $reply->exception['class'] ?? null);
        self::assertSame('PHP Fatal error:  Uncaught Error: Class "Gone" not found', $reply->exception['message'] ?? null);
        self::assertSame('ctx-1', $session->context(), 'a reply without marker does not lose the scope');
    }

    public function test_a_missing_marker_with_no_output_still_gives_no_reply(): void
    {
        $reply = TinkerReply::parse(new CommandResult(1, ''));

        self::assertFalse($reply->replied);
        self::assertSame(1, $reply->exitCode);
        self::assertSame(['class' => TinkerReply::NO_REPLY, 'message' => ''], $reply->exception);
    }

    public function test_a_corrupt_marker_line_gives_no_reply(): void
    {
        $reply = TinkerReply::parse(new CommandResult(0, TinkerSession::MARKER . "not-base64!!\n"));

        self::assertFalse($reply->replied);
        self::assertSame(TinkerReply::NO_REPLY, $reply->exception['class'] ?? null);
    }

    public function test_truncation_markers_are_kept_as_sent(): void
    {
        $reply = TinkerReply::parse(FakeConsole::tinkerReply(['output' => "[truncated]\ntail", 'presented' => "head[truncated]\n"]));

        self::assertSame("[truncated]\ntail", $reply->output);
        self::assertSame("head[truncated]\n", $reply->presented);
    }

    public function test_the_marker_matches_arco_laravel(): void
    {
        self::assertSame(TinkerCommand::MARKER, TinkerSession::MARKER);
    }
}
