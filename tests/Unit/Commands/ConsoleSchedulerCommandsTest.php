<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Commands;

use Multiapi\Arco\Commands\ConsoleAddCommand;
use Multiapi\Arco\Config\ConfigLoader;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Commands\ConsoleRemoveCommand;
use Multiapi\Arco\Commands\LogsRetentionCommand;
use Multiapi\Arco\Commands\SchedulerRemoveCommand;
use Multiapi\Arco\Commands\SchedulerSetCommand;
use Aws\EventBridge\Exception\EventBridgeException;
use Aws\Lambda\Exception\LambdaException;
use Aws\Result;
use Multiapi\Arco\Tests\Unit\Deploy\AwsMocks;
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

        $tester = $this->run_(new SchedulerSetCommand(), ['--env' => 'production'], ['15', 'no']);

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

    public function test_scheduler_set_then_remove_without_console_line_keeps_console_on(): void
    {
        $this->project();

        $this->run_(new SchedulerSetCommand(), ['minutes' => '5', '--env' => 'production'])->assertCommandIsSuccessful();
        self::assertStringContainsString('->console()', $this->source());

        $tester = $this->run_(new SchedulerRemoveCommand(), ['--env' => 'production']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('->scheduler(false)', $this->source());
        self::assertStringContainsString('->console()', $this->source());
        $config = ConfigResolver::resolve((new ConfigLoader())->load($this->project?->dir . '/arco.php'), 'production');
        // An explicit console setting: the deploy's upgrade guard (console exists, setting missing) cannot fire.
        self::assertTrue($config->console);
        self::assertTrue($config->consoleEnabled());
    }

    public function test_scheduler_set_writes_console_when_unset(): void
    {
        $this->project();

        $tester = $this->run_(new SchedulerSetCommand(), ['minutes' => '5', '--env' => 'production']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('->console()', $this->source());
        self::assertStringContainsString('->scheduler(5)', $this->source());
        self::assertTrue(ConfigResolver::resolve((new ConfigLoader())->load($this->project?->dir . '/arco.php'), 'production')->console);
    }

    public function test_scheduler_set_same_interval_still_writes_unset_console(): void
    {
        $this->project('->timeout(60)', '->timeout(60)->scheduler(5)');

        $this->run_(new SchedulerSetCommand(), ['minutes' => '5', '--env' => 'production'])->assertCommandIsSuccessful();

        self::assertStringContainsString('->console()', $this->source());
    }

    public function test_scheduler_remove_writes_console_when_unset(): void
    {
        $this->project('->timeout(60)', '->timeout(60)->scheduler(5)');

        $tester = $this->run_(new SchedulerRemoveCommand(), ['--env' => 'production']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('->scheduler(false)', $this->source());
        self::assertStringContainsString('->console()', $this->source());
        self::assertTrue(ConfigResolver::resolve((new ConfigLoader())->load($this->project?->dir . '/arco.php'), 'production')->console);
    }

    public function test_scheduler_remove_at_app_level_writes_console_when_unset(): void
    {
        $this->project('->concurrency(48)', '->concurrency(48)->scheduler(5)');

        $this->run_(new SchedulerRemoveCommand(), ['--app' => true])->assertCommandIsSuccessful();

        $raw = (new ConfigLoader())->load($this->project?->dir . '/arco.php');
        foreach (['staging', 'production'] as $env) {
            self::assertTrue(ConfigResolver::resolve($raw, $env)->console);
            self::assertNull(ConfigResolver::resolve($raw, $env)->scheduler);
        }
    }

    public function test_app_level_change_lines_name_the_app_in_the_user_language(): void
    {
        $this->project();
        $run = function (string $lang): string {
            $app = \Multiapi\Arco\Cli\Application::create();
            $app->addCommands([new LogsRetentionCommand()]);
            $app->setAutoExit(false);
            $tester = new \Symfony\Component\Console\Tester\ApplicationTester($app);
            $tester->run(['command' => 'logs:retention', 'days' => $lang === 'es' ? '30' : '14', '--app' => true, '--lang' => $lang], ['interactive' => false]);
            return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        };

        self::assertStringContainsString('Log retention (app): default → 14 days', $run('en'));
        self::assertStringContainsString('Retención de logs (toda la app): 14 días → 30 días', $run('es'));
    }

    public function test_logs_retention_one_day_is_singular(): void
    {
        $this->project('->timeout(60)', '->timeout(60)->logRetention(14)');

        $tester = $this->run_(new LogsRetentionCommand(), ['days' => '1', '--env' => 'production']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Log retention (production): 14 days → 1 day', self::display($tester));
        self::assertStringNotContainsString('1 days', self::display($tester));
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

        $tester = $this->run_(new SchedulerSetCommand($inspector), ['--env' => 'production'], ['', 'no']);

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

        $tester = $this->run_(new SchedulerSetCommand(fn (string $dir) => null), ['--env' => 'production'], ['', 'no']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('->scheduler(1)', $this->source());
    }

    public function test_scheduler_set_names_the_tasks_a_combined_interval_protects(): void
    {
        $this->project();
        $inspector = fn (string $dir): array => [['task' => 'half-hourly', 'minutes' => [0, 30]], ['task' => 'every-twenty', 'minutes' => [0, 20, 40]]];

        $tester = $this->run_(new SchedulerSetCommand($inspector), ['--env' => 'production'], ['', 'no']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('->scheduler(10)', $this->source());
        self::assertStringContainsString('every 10 minutes', self::display($tester));
        self::assertStringContainsString('half-hourly, every-twenty', self::display($tester));
    }

    public function test_scheduler_set_names_every_task_when_hourly_is_enough(): void
    {
        $this->project();
        $inspector = fn (string $dir): array => [['task' => 'backup:run', 'minutes' => [0]], ['task' => 'report', 'minutes' => []]];

        $tester = $this->run_(new SchedulerSetCommand($inspector), ['--env' => 'production'], ['', 'no']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('->scheduler(60)', $this->source());
        self::assertStringContainsString('backup:run, report', self::display($tester));
    }

    private static function noAws(): \Closure
    {
        return fn () => throw new \LogicException('No AWS expected.');
    }

    private function consoleProject(string $production = '->scheduler(1)'): ProjectFixture
    {
        return $this->project = new ProjectFixture(str_replace('->timeout(60)', '->timeout(60)' . $production, ProjectFixture::consoleConfig()));
    }

    public function test_scheduler_set_apply_puts_the_rule_on_the_live_alias(): void
    {
        $this->consoleProject();
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['Account' => '123456789012']),
            new Result(['AliasArn' => 'arn:aws:lambda:us-east-1:123456789012:function:shop-production-console:live', 'FunctionVersion' => '3']),
            new Result(['State' => 'ENABLED']),
            new Result(['RuleArn' => 'arn:rule']),
            new Result(['FailedEntryCount' => 0]),
            new Result([]),
        );

        $tester = $this->run_(new SchedulerSetCommand(awsFactory: fn () => $mocks->clients()), ['minutes' => '5', '--env' => 'production', '--profile' => 'p', '--apply' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('->scheduler(5)', $this->source());
        self::assertSame(['GetCallerIdentity', 'GetAlias', 'DescribeRule', 'PutRule', 'PutTargets', 'AddPermission'], $mocks->calls);
        self::assertSame('cron(0/5 * * * ? *)', $mocks->params[3]['ScheduleExpression']);
        self::assertSame('ENABLED', $mocks->params[3]['State']);
        self::assertStringContainsString('Applied in AWS: the scheduler of production runs every 5 min.', self::display($tester));
        self::assertStringNotContainsString('arco deploy --env=production', self::display($tester));
    }

    public function test_scheduler_set_apply_keeps_a_paused_rule_paused(): void
    {
        $this->consoleProject();
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['Account' => '123456789012']),
            new Result(['AliasArn' => 'arn:alias', 'FunctionVersion' => '3']),
            new Result(['State' => 'DISABLED']),
            new Result(['RuleArn' => 'arn:rule']),
            new Result(['FailedEntryCount' => 0]),
            new Result([]),
        );

        $tester = $this->run_(new SchedulerSetCommand(awsFactory: fn () => $mocks->clients()), ['minutes' => '5', '--env' => 'production', '--profile' => 'p', '--apply' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertSame('DISABLED', $mocks->params[3]['State']);
        self::assertStringContainsString('scheduler:resume --env=production', self::display($tester));
        self::assertStringContainsString('Applied in AWS: the scheduler of production is set to every 5 min and is still paused.', self::display($tester));
        self::assertStringNotContainsString('runs every 5 min', self::display($tester));
    }

    public function test_scheduler_set_apply_without_the_console_alias_asks_for_a_deploy(): void
    {
        $this->consoleProject();
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['Account' => '123456789012']),
            AwsMocks::error(LambdaException::class, 'GetAlias', 'ResourceNotFoundException', 404),
        );

        $tester = $this->run_(new SchedulerSetCommand(awsFactory: fn () => $mocks->clients()), ['minutes' => '5', '--env' => 'production', '--profile' => 'p', '--apply' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('->scheduler(5)', $this->source());
        self::assertSame(['GetCallerIdentity', 'GetAlias'], $mocks->calls, 'no EventBridge call');
        self::assertStringContainsString('not deployed yet', self::display($tester));
        self::assertStringContainsString('arco deploy --env=production', self::display($tester));
    }

    public function test_scheduler_set_asks_before_applying_and_no_touches_nothing_in_aws(): void
    {
        $this->consoleProject();

        $tester = $this->run_(new SchedulerSetCommand(awsFactory: self::noAws()), ['minutes' => '5', '--env' => 'production'], ['no']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Apply now in AWS without a deploy?', self::display($tester));
        self::assertStringContainsString('Run `arco deploy --env=production` to apply.', self::display($tester));
    }

    public function test_scheduler_set_asked_yes_applies(): void
    {
        $this->consoleProject();
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['Account' => '123456789012']),
            new Result(['AliasArn' => 'arn:alias', 'FunctionVersion' => '3']),
            new Result(['State' => 'ENABLED']),
            new Result(['RuleArn' => 'arn:rule']),
            new Result(['FailedEntryCount' => 0]),
            new Result([]),
        );

        $tester = $this->run_(new SchedulerSetCommand(awsFactory: fn () => $mocks->clients()), ['minutes' => '5', '--env' => 'production', '--profile' => 'p'], ['yes']);

        $tester->assertCommandIsSuccessful();
        self::assertContains('PutRule', $mocks->calls);
    }

    public function test_scheduler_set_not_interactive_without_apply_touches_nothing_in_aws(): void
    {
        $this->consoleProject();

        $tester = $this->run_(new SchedulerSetCommand(awsFactory: self::noAws()), ['minutes' => '5', '--env' => 'production']);

        $tester->assertCommandIsSuccessful();
        self::assertStringNotContainsString('Apply now', self::display($tester));
        self::assertStringContainsString('Run `arco deploy --env=production` to apply.', self::display($tester));
    }

    public function test_scheduler_set_apply_at_app_level_needs_an_environment(): void
    {
        $this->consoleProject();

        $tester = $this->run_(new SchedulerSetCommand(awsFactory: self::noAws()), ['minutes' => '5', '--app' => true, '--apply' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('--apply needs --env', self::display($tester));
    }

    public function test_scheduler_set_apply_with_an_unchanged_interval_still_applies(): void
    {
        $this->consoleProject('->scheduler(5)');
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['Account' => '123456789012']),
            new Result(['AliasArn' => 'arn:alias', 'FunctionVersion' => '3']),
            AwsMocks::error(EventBridgeException::class, 'DescribeRule', 'ResourceNotFoundException', 400),
            new Result(['RuleArn' => 'arn:rule']),
            new Result(['FailedEntryCount' => 0]),
            new Result([]),
        );

        $tester = $this->run_(new SchedulerSetCommand(awsFactory: fn () => $mocks->clients()), ['minutes' => '5', '--env' => 'production', '--profile' => 'p', '--apply' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('already has this value', self::display($tester));
        self::assertContains('PutRule', $mocks->calls);
    }

    public function test_scheduler_remove_apply_deletes_the_rule_and_the_permission(): void
    {
        $this->consoleProject('->scheduler(5)');
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['Account' => '123456789012']),
            new Result(['Name' => 'arco-shop-production-scheduler', 'State' => 'ENABLED']),
            new Result(['FailedEntryCount' => 0]),
            new Result([]),
            new Result([]),
        );

        $tester = $this->run_(new SchedulerRemoveCommand(awsFactory: fn () => $mocks->clients()), ['--env' => 'production', '--profile' => 'p', '--apply' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('->scheduler(false)', $this->source());
        self::assertSame(['GetCallerIdentity', 'DescribeRule', 'RemoveTargets', 'DeleteRule', 'RemovePermission'], $mocks->calls);
        self::assertStringContainsString('Applied in AWS: the scheduler of production is off.', self::display($tester));
    }

    public function test_scheduler_remove_apply_warns_when_eventbridge_cannot_be_read(): void
    {
        $this->consoleProject('->scheduler(5)');
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['Account' => '123456789012']),
            AwsMocks::error(EventBridgeException::class, 'DescribeRule', 'AccessDeniedException', 400, 'not authorized to perform: events:DescribeRule'),
        );

        $tester = $this->run_(new SchedulerRemoveCommand(awsFactory: fn () => $mocks->clients()), ['--env' => 'production', '--profile' => 'p', '--apply' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame(['GetCallerIdentity', 'DescribeRule'], $mocks->calls);
        self::assertStringContainsString('could not be checked', self::display($tester));
        self::assertStringNotContainsString('Applied in AWS', self::display($tester));
        self::assertStringContainsString('Nothing was applied in AWS: the scheduler rule arco-shop-production-scheduler may still run.', self::display($tester));
    }

    public function test_scheduler_remove_asks_and_no_touches_nothing_in_aws(): void
    {
        $this->consoleProject('->scheduler(5)');

        $tester = $this->run_(new SchedulerRemoveCommand(awsFactory: self::noAws()), ['--env' => 'production'], ['no']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Apply now in AWS without a deploy?', self::display($tester));
        self::assertStringContainsString('Run `arco deploy --env=production` to apply.', self::display($tester));
    }

    public function test_scheduler_remove_apply_when_already_off_still_deletes_a_left_rule(): void
    {
        $this->consoleProject('->scheduler(false)');
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['Account' => '123456789012']),
            new Result(['Name' => 'arco-shop-production-scheduler', 'State' => 'ENABLED']),
            new Result(['FailedEntryCount' => 0]),
            new Result([]),
            new Result([]),
        );

        $tester = $this->run_(new SchedulerRemoveCommand(awsFactory: fn () => $mocks->clients()), ['--env' => 'production', '--profile' => 'p', '--apply' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('already has this value', self::display($tester));
        self::assertSame(['GetCallerIdentity', 'DescribeRule', 'RemoveTargets', 'DeleteRule', 'RemovePermission'], $mocks->calls);
    }

    public function test_an_aws_error_while_applying_fails_and_still_names_the_deploy(): void
    {
        $this->consoleProject();
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['Account' => '123456789012']),
            new Result(['AliasArn' => 'arn:alias', 'FunctionVersion' => '3']),
            AwsMocks::error(EventBridgeException::class, 'DescribeRule', 'AccessDeniedException', 400, 'not authorized'),
        );

        $tester = $this->run_(new SchedulerSetCommand(awsFactory: fn () => $mocks->clients()), ['minutes' => '5', '--env' => 'production', '--profile' => 'p', '--apply' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('->scheduler(5)', $this->source());
        self::assertStringContainsString('not authorized', self::display($tester));
        self::assertStringNotContainsString('Applied in AWS', self::display($tester));
        self::assertStringContainsString('Run `arco deploy --env=production` to apply.', self::display($tester));
    }
}
