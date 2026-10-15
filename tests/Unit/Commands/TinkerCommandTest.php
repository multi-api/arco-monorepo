<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Commands;

use Aws\MockHandler;
use Aws\Result;
use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Build\PlainPhpPreset;
use Multiapi\Arco\Build\PresetRegistry;
use Multiapi\Arco\Commands\TinkerCommand;
use Multiapi\Arco\Console\CommandResult;
use Multiapi\Arco\Tinker\TinkerReply;
use Multiapi\Arco\Tinker\TinkerSession;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class TinkerCommandTest extends TestCase
{
    private ProjectFixture $project;

    protected function setUp(): void
    {
        $this->project = new ProjectFixture(ProjectFixture::standardConfig());
    }

    protected function tearDown(): void
    {
        $this->project->restore();
    }

    private function tester(FakeConsole $console, bool $laravel = true, ?\Closure $shell = null): CommandTester
    {
        $mock = new MockHandler([new Result(['Account' => '111111111111'])]);
        $preset = $laravel ? new class extends PlainPhpPreset { public function name(): string { return 'laravel'; } } : new PlainPhpPreset();
        return $this->project->tester(new TinkerCommand(
            awsFactory: fn (?string $profile, string $region) => new AwsClients($profile, $region, ['handler' => $mock, 'credentials' => false]),
            consoleFactory: fn () => $console,
            presets: new PresetRegistry([$preset]),
            shell: $shell,
        ));
    }

    public function test_execute_runs_one_statement_and_prints_its_value(): void
    {
        $console = new FakeConsole([FakeConsole::tinkerReply(['output' => "hi\n", 'presented' => '3', 'dropped' => ['pdo']])]);
        $tester = $this->tester($console);

        $tester->execute(['--env' => 'staging', '--profile' => 'p', '--execute' => 'User::count()'], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        self::assertStringContainsString("hi\n= 3", $display);
        self::assertStringContainsString('$pdo', $display);
        self::assertSame([['tinker', 'User::count()', null, null]], $console->calls);
    }

    public function test_execute_exits_1_on_an_exception(): void
    {
        $tester = $this->tester(new FakeConsole([FakeConsole::tinkerReply(['exception' => ['class' => 'RuntimeException', 'message' => 'boom']], 1)]));

        $tester->execute(['--env' => 'staging', '--profile' => 'p', '--execute' => 'throw new RuntimeException("boom");'], ['interactive' => false]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('RuntimeException: boom', $tester->getDisplay());
    }

    public function test_execute_shows_the_raw_output_and_exit_code_when_arco_tinker_does_not_answer(): void
    {
        $tester = $this->tester(new FakeConsole([new CommandResult(255, "PHP Fatal error:  boom in restore\n")]));

        $tester->execute(['--env' => 'staging', '--profile' => 'p', '--execute' => '$a'], ['interactive' => false]);

        self::assertSame(1, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('PHP Fatal error:  boom in restore', $display);
        self::assertStringContainsString('255', $display);
        self::assertStringContainsString('multiapi/arco-laravel', $display);
        self::assertStringNotContainsString(TinkerReply::NO_REPLY, $display);
    }

    public function test_execute_shows_a_crash_of_the_invocation(): void
    {
        $tester = $this->tester(new FakeConsole([new CommandResult(1, '', 'Sandbox.Timedout', 'Task timed out after 900.00 seconds', ['{"arco":"tinker","code":"sleep(1000)"}', 'END RequestId: <abc>'])]));

        $tester->execute(['--env' => 'staging', '--profile' => 'p', '--execute' => 'sleep(1000)'], ['interactive' => false]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Sandbox.Timedout: Task timed out after 900.00 seconds', $tester->getDisplay());
        self::assertStringContainsString("  {\"arco\":\"tinker\",\"code\":\"sleep(1000)\"}\n  END RequestId: <abc>\n", $tester->getDisplay(), 'the log tail, like arco command, markup kept as text');
    }

    public function test_a_crash_tail_drops_control_characters(): void
    {
        $tester = $this->tester(new FakeConsole([new CommandResult(1, '', 'Sandbox.Timedout', 'Task timed out', ["boom \e[31mred\x07\tend\r\nnext"])]));

        $tester->execute(['--env' => 'staging', '--profile' => 'p', '--execute' => 'sleep(1000)'], ['interactive' => false]);

        self::assertStringContainsString("  boom [31mred\tend next\n", $tester->getDisplay());
        self::assertStringNotContainsString("\e", $tester->getDisplay());
        self::assertStringNotContainsString("\x07", $tester->getDisplay());
    }

    public function test_truncated_values_are_shown_with_their_markers(): void
    {
        $tester = $this->tester(new FakeConsole([FakeConsole::tinkerReply(['output' => "[truncated]\nend of output\n", 'presented' => "start of value[truncated]\n"])]));

        $tester->execute(['--env' => 'staging', '--profile' => 'p', '--execute' => 'big()'], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString("[truncated]\nend of output\n= start of value[truncated]\n", $tester->getDisplay());
        self::assertStringNotContainsString("[truncated]\n\n", $tester->getDisplay());
    }

    public function test_a_dropped_context_lists_every_variable(): void
    {
        $tester = $this->tester(new FakeConsole([FakeConsole::tinkerReply(['presented' => '1', 'dropped' => ['users', 'big']])]));

        $tester->execute(['--env' => 'staging', '--profile' => 'p', '--execute' => '1'], ['interactive' => false]);

        self::assertStringContainsString('$users, $big', $tester->getDisplay());
    }

    public function test_the_missing_remote_tinker_gets_a_hint(): void
    {
        $tester = $this->tester(new FakeConsole([FakeConsole::tinkerReply(['exception' => ['class' => 'Arco.TinkerMissing', 'message' => 'psy/psysh is not installed in this app']], 1)]));

        $tester->execute(['--env' => 'staging', '--profile' => 'p', '--execute' => '1'], ['interactive' => false]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Add laravel/tinker', $tester->getDisplay());
    }

    public function test_production_needs_force_without_a_terminal(): void
    {
        $console = new FakeConsole();
        $tester = $this->tester($console);

        $tester->execute(['--env' => 'production', '--profile' => 'p', '--execute' => '1'], ['interactive' => false]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('--force', $tester->getDisplay());
        self::assertSame([], $console->calls);
    }

    public function test_production_asks_with_account_and_environment(): void
    {
        $console = new FakeConsole();
        $tester = $this->tester($console);
        $tester->setInputs(['no']);

        $tester->execute(['--env' => 'production', '--profile' => 'p', '--execute' => '1']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('111111111111', $tester->getDisplay());
        self::assertStringContainsString('production', $tester->getDisplay());
        self::assertSame([], $console->calls);
    }

    public function test_force_skips_the_confirmation(): void
    {
        $console = new FakeConsole([FakeConsole::tinkerReply(['presented' => '1'])]);

        $this->tester($console)->execute(['--env' => 'production', '--profile' => 'p', '--execute' => '1', '--force' => true], ['interactive' => false]);

        self::assertCount(1, $console->calls);
    }

    public function test_without_execute_the_local_shell_starts(): void
    {
        $started = null;
        $tester = $this->tester(new FakeConsole(), shell: function (TinkerSession $session) use (&$started): int {
            $started = $session;
            return 0;
        });

        $tester->execute(['--env' => 'staging', '--profile' => 'p'], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        self::assertInstanceOf(TinkerSession::class, $started);
        self::assertStringContainsString('shop-staging-console', $tester->getDisplay());
    }

    public function test_needs_a_laravel_app(): void
    {
        $tester = $this->tester(new FakeConsole(), laravel: false);

        $tester->execute(['--env' => 'staging', '--profile' => 'p', '--execute' => '1'], ['interactive' => false]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Laravel', $tester->getDisplay());
    }
}
