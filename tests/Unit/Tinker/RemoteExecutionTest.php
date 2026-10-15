<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Tinker;

use Multiapi\Arco\Tests\Unit\Commands\FakeConsole;
use Multiapi\Arco\Tinker\RemoteExecution;
use Multiapi\Arco\Tinker\RemoteShell;
use Multiapi\Arco\Tinker\TinkerReply;
use Multiapi\Arco\Tinker\TinkerSession;
use PHPUnit\Framework\TestCase;
use Psy\Configuration;
use Psy\Shell;

final class RemoteExecutionTest extends TestCase
{
    public function test_statements_run_remotely_and_nothing_runs_locally(): void
    {
        $console = new FakeConsole([FakeConsole::tinkerReply(['presented' => '42'])]);
        $rendered = [];
        $listener = new RemoteExecution(new TinkerSession($console, TinkerSessionTest::config()), function (TinkerReply $reply) use (&$rendered): void { $rendered[] = $reply->presented; });
        $shell = new Shell(new Configuration(['updateCheck' => 'never']));

        self::assertSame('return new \Psy\CodeCleaner\NoReturnValue();', $listener->onExecute($shell, 'return $a = 40 + 2;'));
        self::assertSame(['42'], $rendered);
        self::assertSame('return $a = 40 + 2;', $console->calls[0][1]);

        self::assertNull($listener->onExecute($shell, 'return null;'), 'empty input is not sent');
        self::assertCount(1, $console->calls);
    }

    public function test_the_remote_shell_runs_the_listener(): void
    {
        $listener = new RemoteExecution(new TinkerSession(new FakeConsole(), TinkerSessionTest::config()), fn () => null);

        $shell = new RemoteShell(new Configuration(['updateCheck' => 'never']), $listener);

        $listeners = (new \ReflectionProperty(Shell::class, 'loopListeners'))->getValue($shell);
        self::assertIsArray($listeners);
        self::assertContains($listener, $listeners);
    }
}
