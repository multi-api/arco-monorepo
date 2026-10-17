<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Commands;

use Multiapi\Arco\Commands\ConsoleAddCommand;
use Multiapi\Arco\Config\ConfigLoader;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Commands\ConsoleRemoveCommand;
use Multiapi\Arco\Commands\LogsRetentionCommand;
use Multiapi\Arco\Commands\SchedulerRemoveCommand;
use Multiapi\Arco\Commands\SchedulerSetCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ConsoleSchedulerCommandsTest extends TestCase
{
    private ?ProjectFixture $project = null;

    protected function tearDown(): void
    {
        $this->project?->restore();
    }

    private function project(string $replace = '', string $with = ''): ProjectFixture
    {
        $php = ProjectFixture::standardConfig();
        return $this->project = new ProjectFixture($replace === '' ? $php : str_replace($replace, $with, $php));
    }

    /** @param array<string, mixed> $input @param list<string> $inputs */
    private function run_(Command $command, array $input, array $inputs = []): CommandTester
    {
        $tester = $this->project?->tester($command) ?? throw new \LogicException('No project.');
        $tester->setInputs($inputs);
        $tester->execute($input, ['interactive' => $inputs !== []]);
        return $tester;
    }

    private function source(): string
    {
        return (string) file_get_contents($this->project?->dir . '/arco.php');
    }

    private static function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    public function test_console_add_writes_console_call(): void
    {
        $this->project();

        $tester = $this->run_(new ConsoleAddCommand(), ['--env' => 'production']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('->console()', $this->source());
        self::assertStringContainsString('console (production): off → on', self::display($tester));
        self::assertStringContainsString('Run `arco deploy --env=production` to apply.', self::display($tester));
    }

    public function test_console_add_when_already_on_says_nothing_to_change(): void
    {
        $this->project('->timeout(60)', '->timeout(60)->console()');
        $before = $this->source();

        $tester = $this->run_(new ConsoleAddCommand(), ['--env' => 'production']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('already has this value', self::display($tester));
        self::assertSame($before, $this->source());
    }

    public function test_console_remove_refuses_while_scheduler_on(): void
    {
        $this->project('->timeout(60)', '->timeout(60)->scheduler(5)');
        $before = $this->source();

        $tester = $this->run_(new ConsoleRemoveCommand(), ['--env' => 'production']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('runs the scheduler', self::display($tester));
        self::assertSame($before, $this->source());
    }

    public function test_console_remove_writes_false(): void
    {
        $this->project('->timeout(60)', '->timeout(60)->console()');

        $tester = $this->run_(new ConsoleRemoveCommand(), ['--env' => 'production']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('->console(false)', $this->source());
        self::assertStringContainsString('console (production): on → off', self::display($tester));
    }

    public function test_scheduler_set_writes_interval_and_prints_change(): void
    {
        $this->project('->timeout(60)', '->timeout(60)->scheduler(1)');

        $tester = $this->run_(new SchedulerSetCommand(), ['minutes' => '5', '--env' => 'production']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('->scheduler(5)', $this->source());
        self::assertStringNotContainsString('->scheduler(1)', $this->source());
        self::assertStringContainsString('Scheduler (production): every 1 min → every 5 min', self::display($tester));
    }

    public function test_scheduler_set_rejects_7(): void
    {
        $this->project();
        $before = $this->source();

        $tester = $this->run_(new SchedulerSetCommand(), ['minutes' => '7', '--env' => 'production']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('scheduler must run every', self::display($tester));
        self::assertSame($before, $this->source());
    }

    public function test_scheduler_set_without_number_asks(): void
    {
        $this->project();

        $tester = $this->run_(new SchedulerSetCommand(), ['--env' => 'production'], ['15']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('->scheduler(15)', $this->source());
    }

    public function test_scheduler_set_replaces_console_false_at_the_same_level(): void
    {
        $this->project('->timeout(60)', '->timeout(60)->console(false)');

        $tester = $this->run_(new SchedulerSetCommand(), ['minutes' => '5', '--env' => 'production']);

        $tester->assertCommandIsSuccessful();
        self::assertStringNotContainsString('console(false)', $this->source());
        self::assertStringContainsString('->console()', $this->source());
        self::assertStringContainsString('->scheduler(5)', $this->source());
        self::assertStringContainsString('console (production): off → on', self::display($tester));
        self::assertStringContainsString('Scheduler (production): off → every 5 min', self::display($tester));
    }

    public function test_scheduler_set_turns_console_on_when_false_is_inherited(): void
    {
        $this->project('->concurrency(48)', '->concurrency(48)->console(false)');

        $this->run_(new SchedulerSetCommand(), ['minutes' => '5', '--env' => 'production'])->assertCommandIsSuccessful();

        self::assertStringContainsString('->console(false)', $this->source());
        self::assertStringContainsString('->console()', $this->source());
        self::assertTrue(ConfigResolver::resolve((new ConfigLoader())->load($this->project?->dir . '/arco.php'), 'production')->consoleEnabled());
    }

    public function test_scheduler_set_without_number_fails_when_not_interactive(): void
    {
        $this->project();
        $before = $this->source();

        $tester = $this->run_(new SchedulerSetCommand(), ['--env' => 'production']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Pass the number of minutes', self::display($tester));
        self::assertSame($before, $this->source());
    }

    public function test_scheduler_remove_writes_false_and_keeps_console(): void
    {
        $this->project('->timeout(60)', '->timeout(60)->console()->scheduler(5)');

        $tester = $this->run_(new SchedulerRemoveCommand(), ['--env' => 'production']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('->scheduler(false)', $this->source());
        self::assertStringContainsString('->console()', $this->source());
    }

    public function test_logs_retention_sets_days(): void
    {
        $this->project();

        $tester = $this->run_(new LogsRetentionCommand(), ['days' => '14', '--env' => 'production']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('->logRetention(14)', $this->source());
        self::assertStringContainsString('Log retention (production): default → 14 days', self::display($tester));
    }

    public function test_logs_retention_never_writes_false(): void
    {
        $this->project();

        $this->run_(new LogsRetentionCommand(), ['days' => 'never', '--env' => 'production'])->assertCommandIsSuccessful();

        self::assertStringContainsString('->logRetention(false)', $this->source());
    }

    public function test_logs_retention_remove_deletes_call(): void
    {
        $this->project('->timeout(60)', '->timeout(60)->logRetention(14)');

        $this->run_(new LogsRetentionCommand(), ['--remove' => true, '--env' => 'production'])->assertCommandIsSuccessful();

        self::assertStringNotContainsString('logRetention', $this->source());
    }

    public function test_logs_retention_rejects_31(): void
    {
        $this->project();
        $before = $this->source();

        $tester = $this->run_(new LogsRetentionCommand(), ['days' => '31', '--env' => 'production']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('logRetention must be', self::display($tester));
        self::assertSame($before, $this->source());
    }

    public function test_scheduler_set_suggests_required_interval(): void
    {
        $this->project();
        $dirs = [];
        $inspector = function (string $dir) use (&$dirs): array {
            $dirs[] = $dir;
            return [['task' => 'reports:send', 'minutes' => range(0, 55, 5)], ['task' => 'backup:run', 'minutes' => [0]]];
        };

        $tester = $this->run_(new SchedulerSetCommand($inspector), ['--env' => 'production'], ['']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('->scheduler(5)', $this->source());
        self::assertSame([$this->project?->dir], $dirs);
        self::assertStringContainsString('every 5 minutes', self::display($tester));
        self::assertStringContainsString('reports:send', self::display($tester));
        self::assertStringContainsString('[5]', self::display($tester));
    }

    public function test_scheduler_set_without_a_readable_schedule_defaults_to_one(): void
    {
        $this->project();

        $tester = $this->run_(new SchedulerSetCommand(fn (string $dir) => null), ['--env' => 'production'], ['']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('->scheduler(1)', $this->source());
    }

    public function test_scheduler_set_names_the_tasks_a_combined_interval_protects(): void
    {
        $this->project();
        $inspector = fn (string $dir): array => [['task' => 'half-hourly', 'minutes' => [0, 30]], ['task' => 'every-twenty', 'minutes' => [0, 20, 40]]];

        $tester = $this->run_(new SchedulerSetCommand($inspector), ['--env' => 'production'], ['']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('->scheduler(10)', $this->source());
        self::assertStringContainsString('every 10 minutes', self::display($tester));
        self::assertStringContainsString('half-hourly, every-twenty', self::display($tester));
    }

    public function test_scheduler_set_names_every_task_when_hourly_is_enough(): void
    {
        $this->project();
        $inspector = fn (string $dir): array => [['task' => 'backup:run', 'minutes' => [0]], ['task' => 'report', 'minutes' => []]];

        $tester = $this->run_(new SchedulerSetCommand($inspector), ['--env' => 'production'], ['']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('->scheduler(60)', $this->source());
        self::assertStringContainsString('backup:run, report', self::display($tester));
    }
}
