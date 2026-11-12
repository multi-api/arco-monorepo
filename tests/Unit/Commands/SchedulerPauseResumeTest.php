<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Commands;

use Aws\EventBridge\Exception\EventBridgeException;
use Aws\Result;
use Multiapi\Arco\Commands\SchedulerPauseCommand;
use Multiapi\Arco\Commands\SchedulerResumeCommand;
use Multiapi\Arco\Tests\Unit\Deploy\AwsMocks;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SchedulerPauseResumeTest extends TestCase
{
    private ?ProjectFixture $project = null;
    private AwsMocks $mocks;

    protected function setUp(): void
    {
        $this->project = new ProjectFixture(str_replace('->timeout(60)', '->timeout(60)->scheduler(5)', ProjectFixture::consoleConfig()));
        $this->mocks = new AwsMocks();
    }

    protected function tearDown(): void
    {
        $this->project?->restore();
    }

    private function run_(Command $command): CommandTester
    {
        $tester = $this->project?->tester($command) ?? throw new \LogicException('No project.');
        $tester->execute(['--env' => 'production', '--profile' => 'p'], ['interactive' => false]);
        return $tester;
    }

    private function awsFactory(): \Closure
    {
        return fn (?string $profile, string $region) => $this->mocks->clients($region);
    }

    private static function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    private function source(): string
    {
        return (string) file_get_contents($this->project?->dir . '/arco.php');
    }

    /** @param array<string, string> $tags @return list<Result> the ownership reads of the rule (DescribeRule, ListTagsForResource) */
    private static function ownRule(array $tags = ['arco:app' => 'shop', 'arco:environment' => 'production']): array
    {
        return [
            new Result(['Name' => 'arco-shop-production-scheduler', 'Arn' => 'arn:rule', 'Description' => 'Arco: schedule:run every 1 min on shop-production-console:live']),
            new Result(['Tags' => array_map(fn (string $k, string $v) => ['Key' => $k, 'Value' => $v], array_keys($tags), array_values($tags))]),
        ];
    }

    public function test_pause_refuses_a_rule_of_another_environment(): void
    {
        $this->mocks->handler->append(...[new Result(['Account' => '123456789012']), new Result(['State' => 'ENABLED']), ...self::ownRule(['arco:app' => 'shop', 'arco:environment' => 'staging'])]);

        $tester = $this->run_(new SchedulerPauseCommand($this->awsFactory()));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertNotContains('DisableRule', $this->mocks->calls);
        self::assertStringContainsString('environment "staging"', self::display($tester));
        self::assertStringContainsString('Nothing was run or changed', self::display($tester));
    }

    public function test_pause_disables_the_rule_without_editing_arco_php(): void
    {
        $before = $this->source();
        $this->mocks->handler->append(...[new Result(['Account' => '123456789012']), new Result(['State' => 'ENABLED']), ...self::ownRule(), new Result([])]);

        $tester = $this->run_(new SchedulerPauseCommand($this->awsFactory()));

        $tester->assertCommandIsSuccessful();
        self::assertSame(['GetCallerIdentity', 'DescribeRule', 'DescribeRule', 'ListTagsForResource', 'DisableRule'], $this->mocks->calls);
        self::assertSame('arco-shop-production-scheduler', $this->mocks->params[2]['Name']);
        self::assertStringContainsString('Scheduler of production paused', self::display($tester));
        self::assertStringContainsString('arco scheduler:resume', self::display($tester));
        self::assertSame($before, $this->source());
    }

    public function test_pause_of_a_paused_rule_changes_nothing(): void
    {
        $this->mocks->handler->append(...[new Result(['Account' => '123456789012']), new Result(['State' => 'DISABLED']), ...self::ownRule()]);

        $tester = $this->run_(new SchedulerPauseCommand($this->awsFactory()));

        $tester->assertCommandIsSuccessful();
        self::assertNotContains('DisableRule', $this->mocks->calls);
        self::assertStringContainsString('already paused; nothing to change', self::display($tester));
    }

    public function test_pause_without_a_rule_fails_and_changes_nothing(): void
    {
        $this->mocks->handler->append(new Result(['Account' => '123456789012']), AwsMocks::error(EventBridgeException::class, 'DescribeRule', 'ResourceNotFoundException', 400));

        $tester = $this->run_(new SchedulerPauseCommand($this->awsFactory()));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame(['GetCallerIdentity', 'DescribeRule'], $this->mocks->calls);
        self::assertStringContainsString('No scheduler rule arco-shop-production-scheduler in AWS', self::display($tester));
    }

    public function test_resume_enables_the_rule(): void
    {
        $this->mocks->handler->append(...[new Result(['Account' => '123456789012']), new Result(['State' => 'DISABLED']), ...self::ownRule(), new Result([])]);

        $tester = $this->run_(new SchedulerResumeCommand($this->awsFactory()));

        $tester->assertCommandIsSuccessful();
        self::assertSame(['GetCallerIdentity', 'DescribeRule', 'DescribeRule', 'ListTagsForResource', 'EnableRule'], $this->mocks->calls);
        self::assertSame('arco-shop-production-scheduler', $this->mocks->params[2]['Name']);
        self::assertStringContainsString('Scheduler of production resumed', self::display($tester));
    }

    public function test_resume_of_an_active_rule_changes_nothing(): void
    {
        $this->mocks->handler->append(...[new Result(['Account' => '123456789012']), new Result(['State' => 'ENABLED']), ...self::ownRule()]);

        $tester = $this->run_(new SchedulerResumeCommand($this->awsFactory()));

        $tester->assertCommandIsSuccessful();
        self::assertNotContains('EnableRule', $this->mocks->calls);
        self::assertStringContainsString('already active; nothing to change', self::display($tester));
    }

    public function test_resume_without_a_rule_fails(): void
    {
        $this->mocks->handler->append(new Result(['Account' => '123456789012']), AwsMocks::error(EventBridgeException::class, 'DescribeRule', 'ResourceNotFoundException', 400));

        $tester = $this->run_(new SchedulerResumeCommand($this->awsFactory()));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertNotContains('EnableRule', $this->mocks->calls);
    }

    public function test_the_account_lock_is_checked_before_any_eventbridge_call(): void
    {
        file_put_contents($this->project?->dir . '/arco.php', str_replace('->scheduler(5)', "->scheduler(5)->account('999999999999')", $this->source()));
        $this->mocks->handler->append(new Result(['Account' => '123456789012']));

        $tester = $this->run_(new SchedulerPauseCommand($this->awsFactory()));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame(['GetCallerIdentity'], $this->mocks->calls);
    }
    /** A process project (arco.json) with two schedules; the rules as GetResources lists them (report first). */
    private function processProject(): \Multiapi\Arco\Config\AppConfig
    {
        $this->project?->restore();
        $this->project = new ProjectFixture();
        file_put_contents($this->project->dir . '/arco.json', '{"name":"shop","region":"us-east-1","runtime":{"kind":"process","language":"go"},"schedules":{"cleanup":{"every":"1h"},"report":{"every":"1d"}}}');
        $raw = \Multiapi\Arco\Config\App::make('shop')->region('us-east-1')->environment('production')->toArray();
        $raw['process'] = ['language' => 'go'];
        $raw['schedules'] = ['cleanup' => ['every' => '1h'], 'report' => ['every' => '1d']];
        return \Multiapi\Arco\Config\ConfigResolver::resolve($raw, 'production');
    }

    /** @param list<string> $rules @param list<string> $schedules */
    private static function scheduleRules(array $rules, array $schedules): Result
    {
        $list = [];
        foreach ($rules as $i => $rule) {
            $list[] = ['ResourceARN' => "arn:aws:events:us-east-1:123456789012:rule/$rule", 'Tags' => [
                ['Key' => 'arco:app', 'Value' => 'shop'], ['Key' => 'arco:environment', 'Value' => 'production'], ['Key' => 'arco:schedule', 'Value' => $schedules[$i]],
            ]];
        }
        return new Result(['ResourceTagMappingList' => $list]);
    }

    public function test_pause_in_a_process_project_disables_every_schedule_rule_and_prints_one_line_each(): void
    {
        $config = $this->processProject();
        $cleanup = \Multiapi\Arco\Deploy\ProcessSchedules::ruleName($config, 'cleanup');
        $report = \Multiapi\Arco\Deploy\ProcessSchedules::ruleName($config, 'report');
        $this->mocks->handler->append(
            new Result(['Account' => '123456789012']),
            self::scheduleRules([$report, $cleanup], ['report', 'cleanup']),
            new Result(['State' => 'ENABLED']), new Result(['State' => 'DISABLED']),
            new Result([]),
        );

        $tester = $this->run_(new SchedulerPauseCommand($this->awsFactory()));

        $tester->assertCommandIsSuccessful();
        self::assertSame(['GetCallerIdentity', 'GetResources', 'DescribeRule', 'DescribeRule', 'DisableRule'], $this->mocks->calls);
        self::assertSame($report, $this->mocks->params[4]['Name']);
        self::assertNotContains('arco-shop-production-scheduler', array_map(fn (array $p) => $p['Name'] ?? null, $this->mocks->params), 'never the Laravel rule');
        $display = self::display($tester);
        self::assertStringContainsString("Schedule cleanup is already paused ($cleanup)", $display);
        self::assertStringContainsString("Schedule report paused ($report)", $display);
        self::assertStringContainsString('arco scheduler:resume --env=production', $display);
    }

    public function test_resume_in_a_process_project_enables_every_schedule_rule(): void
    {
        $config = $this->processProject();
        $cleanup = \Multiapi\Arco\Deploy\ProcessSchedules::ruleName($config, 'cleanup');
        $report = \Multiapi\Arco\Deploy\ProcessSchedules::ruleName($config, 'report');
        $this->mocks->handler->append(
            new Result(['Account' => '123456789012']),
            self::scheduleRules([$cleanup, $report], ['cleanup', 'report']),
            new Result(['State' => 'DISABLED']), new Result(['State' => 'DISABLED']),
            new Result([]), new Result([]),
        );

        $tester = $this->run_(new SchedulerResumeCommand($this->awsFactory()));

        $tester->assertCommandIsSuccessful();
        self::assertSame(['GetCallerIdentity', 'GetResources', 'DescribeRule', 'DescribeRule', 'EnableRule', 'EnableRule'], $this->mocks->calls);
        self::assertStringContainsString("Schedule cleanup resumed ($cleanup)", self::display($tester));
        self::assertStringContainsString("Schedule report resumed ($report)", self::display($tester));
    }

    public function test_pause_in_a_process_project_without_schedule_rules_fails(): void
    {
        $this->processProject();
        $this->mocks->handler->append(new Result(['Account' => '123456789012']), new Result(['ResourceTagMappingList' => []]));

        $tester = $this->run_(new SchedulerPauseCommand($this->awsFactory()));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame(['GetCallerIdentity', 'GetResources'], $this->mocks->calls);
        self::assertStringContainsString('No schedule rules in AWS for production', self::display($tester));
    }
    public function test_pause_in_a_process_project_takes_name_and_region_from_the_config_layer(): void
    {
        $this->project?->restore();
        $this->project = new ProjectFixture();
        file_put_contents($this->project->dir . '/arco.json', '{"runtime":{"kind":"process","language":"go"},"schedules":{"cleanup":{"every":"1h"}}}');
        $layer = $this->project->layer(['schema' => 1, 'name' => 'shop', 'region' => 'us-east-1', 'environments' => ['production' => (object) []]]);
        $raw = \Multiapi\Arco\Config\App::make('shop')->region('us-east-1')->environment('production')->toArray();
        $raw['process'] = ['language' => 'go'];
        $raw['schedules'] = ['cleanup' => ['every' => '1h']];
        $cleanup = \Multiapi\Arco\Deploy\ProcessSchedules::ruleName(\Multiapi\Arco\Config\ConfigResolver::resolve($raw, 'production'), 'cleanup');
        $this->mocks->handler->append(
            new Result(['Account' => '123456789012']),
            self::scheduleRules([$cleanup], ['cleanup']),
            new Result(['State' => 'ENABLED']),
            new Result([]),
        );

        $tester = $this->project->tester(new SchedulerPauseCommand($this->awsFactory()));
        $tester->execute(['--config-layer' => $layer, '--profile' => 'p'], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        self::assertSame(['GetCallerIdentity', 'GetResources', 'DescribeRule', 'DisableRule'], $this->mocks->calls);
        self::assertSame([['Key' => 'arco:app', 'Values' => ['shop']], ['Key' => 'arco:environment', 'Values' => ['production']], ['Key' => 'arco:schedule']], $this->mocks->params[1]['TagFilters']);
        self::assertSame($cleanup, $this->mocks->params[3]['Name']);
        self::assertStringContainsString("Schedule cleanup paused ($cleanup)", self::display($tester));
    }
}
