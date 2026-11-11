<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy;

use Aws\EventBridge\Exception\EventBridgeException;
use Aws\Lambda\Exception\LambdaException;
use Aws\Result;
use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Config\Environment;
use Multiapi\Arco\Config\ScheduleExpression;
use Multiapi\Arco\Deploy\DeployException;
use Multiapi\Arco\Deploy\ProcessSchedules;
use PHPUnit\Framework\TestCase;

final class ProcessSchedulesTest extends TestCase
{
    private const ALIAS = 'arn:aws:lambda:us-east-1:111111111111:function:shop-production-console:live';
    private const TAGS = ['arco:app' => 'shop', 'arco:environment' => 'production'];

    /** @param array<string, array<string, mixed>> $schedules */
    private static function config(array $schedules, string $app = 'shop', string $environment = 'production'): AppConfig
    {
        $raw = App::make($app)->region('us-east-1')->environment($environment, fn (Environment $e) => $e->account('111111111111'))->toArray();
        $raw['process'] = ['language' => 'go'];
        $raw['schedules'] = $schedules;
        return ConfigResolver::resolve($raw, $environment);
    }

    private static function schedules(AwsMocks $mocks): ProcessSchedules
    {
        $clients = $mocks->clients();
        return new ProcessSchedules($clients->eventBridge(), $clients->lambda(), $clients->taggingApi());
    }

    private static function arn(string $rule): string
    {
        return "arn:aws:events:us-east-1:111111111111:rule/$rule";
    }

    /**
     * A GetResources page listing rules with their tags.
     *
     * @param array<string, array<string, string>> $rules rule name => tags
     */
    private static function listed(array $rules): Result
    {
        $list = [];
        foreach ($rules as $rule => $tags) {
            $list[] = ['ResourceARN' => self::arn($rule), 'Tags' => array_map(fn (string $k, string $v) => ['Key' => $k, 'Value' => $v], array_keys($tags), array_values($tags))];
        }
        return new Result(['ResourceTagMappingList' => $list]);
    }

    private static function noRule(): EventBridgeException
    {
        return AwsMocks::error(EventBridgeException::class, 'DescribeRule', 'ResourceNotFoundException', 400);
    }

    /** @return list<Result> PutRule, PutTargets, AddPermission */
    private static function put(string $rule): array
    {
        return [new Result(['RuleArn' => self::arn($rule)]), new Result(['FailedEntryCount' => 0, 'FailedEntries' => []]), new Result(['Statement' => '{}'])];
    }

    /** @param list<array{0: string, 1: array<string, mixed>}> $steps */
    private static function recorder(array &$steps): \Closure
    {
        return function (string $key, array $params = []) use (&$steps): void {
            $steps[] = [$key, $params];
        };
    }

    public function test_rule_names_differ_for_names_that_concatenate_alike(): void
    {
        $a = ProcessSchedules::ruleName(self::config([], 'shop', 'prod'), 'x-daily');
        $b = ProcessSchedules::ruleName(self::config([], 'shop', 'prod-x'), 'daily');

        self::assertNotSame($a, $b);
        self::assertMatchesRegularExpression('/^arco-.*-scheduler$/', $a);
        self::assertMatchesRegularExpression('/^arco-.*-scheduler$/', $b);
        self::assertSame('arco-shop-prod-x-daily-' . substr(hash('sha256', 'shop/prod/x-daily'), 0, 8) . '-scheduler', $a);
    }

    public function test_a_long_rule_name_is_cut_to_64_and_keeps_the_hash(): void
    {
        $app = str_repeat('a', 40);
        $schedule = str_repeat('s', 40);
        $name = ProcessSchedules::ruleName(self::config([], $app), $schedule);

        self::assertSame(64, strlen($name));
        self::assertStringEndsWith('-' . substr(hash('sha256', "$app/production/$schedule"), 0, 8) . '-scheduler', $name);
        self::assertStringStartsWith("arco-$app-", $name);
    }

    public function test_sync_creates_one_rule_per_schedule(): void
    {
        $config = self::config(['cleanup' => ['every' => '1h'], 'report' => ['every' => '30 2 * * 1']]);
        $cleanup = ProcessSchedules::ruleName($config, 'cleanup');
        $report = ProcessSchedules::ruleName($config, 'report');
        $mocks = new AwsMocks();
        $mocks->handler->append(self::listed([]), self::noRule(), self::noRule(), ...self::put($cleanup), ...self::put($report));
        $steps = [];

        self::schedules($mocks)->sync($config, self::ALIAS, self::TAGS, self::recorder($steps));

        self::assertSame(['GetResources', 'DescribeRule', 'DescribeRule', 'PutRule', 'PutTargets', 'AddPermission', 'PutRule', 'PutTargets', 'AddPermission'], $mocks->calls);
        self::assertSame(['events:rule'], $mocks->params[0]['ResourceTypeFilters']);
        self::assertSame($cleanup, $mocks->params[3]['Name']);
        self::assertSame(ScheduleExpression::toEventBridge('1h'), $mocks->params[3]['ScheduleExpression']);
        self::assertSame('ENABLED', $mocks->params[3]['State']);
        self::assertSame([['Key' => 'arco:app', 'Value' => 'shop'], ['Key' => 'arco:environment', 'Value' => 'production'], ['Key' => 'arco:schedule', 'Value' => 'cleanup']], $mocks->params[3]['Tags']);
        self::assertSame([['Id' => 'arco-console', 'Arn' => self::ALIAS, 'Input' => '{"arco":"schedule","name":"cleanup"}']], $mocks->params[4]['Targets']);
        self::assertSame($cleanup, $mocks->params[4]['Rule']);
        self::assertSame('shop-production-console', $mocks->params[5]['FunctionName']);
        self::assertSame('live', $mocks->params[5]['Qualifier']);
        self::assertSame('arco-schedule-' . substr(hash('sha256', 'shop/production/cleanup'), 0, 8), $mocks->params[5]['StatementId']);
        self::assertSame('events.amazonaws.com', $mocks->params[5]['Principal']);
        self::assertSame(self::arn($cleanup), $mocks->params[5]['SourceArn']);
        self::assertSame(ScheduleExpression::toEventBridge('30 2 * * 1'), $mocks->params[6]['ScheduleExpression']);
        self::assertSame('{"arco":"schedule","name":"report"}', $mocks->params[7]['Targets'][0]['Input']);
        self::assertSame([
            ['deploy.schedule_created', ['schedule' => 'cleanup', 'rule' => $cleanup]],
            ['deploy.schedule_created', ['schedule' => 'report', 'rule' => $report]],
        ], $steps);
    }

    public function test_an_existing_disabled_rule_stays_disabled_and_a_new_one_starts_disabled(): void
    {
        $config = self::config(['cleanup' => ['every' => '1h'], 'report' => ['every' => '1d']]);
        $cleanup = ProcessSchedules::ruleName($config, 'cleanup');
        $report = ProcessSchedules::ruleName($config, 'report');
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::listed([$cleanup => [...self::TAGS, 'arco:schedule' => 'cleanup']]),
            new Result(['Name' => $cleanup, 'State' => 'DISABLED']),                     // DescribeRule (state)
            self::noRule(),                                                              // report: no rule yet
            ...[...self::put($cleanup),
            new Result(['RuleArn' => self::arn($report)]), new Result(['FailedEntryCount' => 0]),
            AwsMocks::error(LambdaException::class, 'AddPermission', 'ResourceConflictException', 409)],
        );
        $steps = [];

        self::schedules($mocks)->sync($config, self::ALIAS, self::TAGS, self::recorder($steps));

        self::assertSame(['GetResources', 'DescribeRule', 'DescribeRule', 'PutRule', 'PutTargets', 'AddPermission', 'PutRule', 'PutTargets', 'AddPermission'], $mocks->calls);
        self::assertSame('DISABLED', $mocks->params[3]['State'], 'an existing rule keeps its state');
        self::assertSame('DISABLED', $mocks->params[6]['State'], 'the environment is paused: a new rule starts disabled');
        self::assertSame([
            ['deploy.schedule_updated', ['schedule' => 'cleanup', 'rule' => $cleanup]],
            ['deploy.schedule_paused', ['schedule' => 'cleanup']],
            ['deploy.schedule_created', ['schedule' => 'report', 'rule' => $report]],
            ['deploy.schedule_paused', ['schedule' => 'report']],
        ], $steps);
    }

    public function test_a_rule_for_a_removed_schedule_is_deleted_and_the_laravel_rule_is_untouched(): void
    {
        $config = self::config(['cleanup' => ['every' => '1h']]);
        $cleanup = ProcessSchedules::ruleName($config, 'cleanup');
        $gone = ProcessSchedules::ruleName($config, 'gone');
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::listed([
                $cleanup => [...self::TAGS, 'arco:schedule' => 'cleanup'],
                $gone => [...self::TAGS, 'arco:schedule' => 'gone'],
                'arco-shop-production-scheduler' => self::TAGS,                               // the Laravel rule: no arco:schedule tag
                'arco-shop-staging-x-12345678-scheduler' => ['arco:app' => 'shop', 'arco:environment' => 'staging', 'arco:schedule' => 'x'],
            ]),
            new Result(['Name' => $cleanup, 'State' => 'ENABLED']),
            new Result(['Name' => $gone, 'State' => 'ENABLED']),
            new Result(['FailedEntryCount' => 0]), new Result([]), new Result([]),           // RemoveTargets, DeleteRule, RemovePermission
            ...self::put($cleanup),
        );
        $steps = [];

        self::schedules($mocks)->sync($config, self::ALIAS, self::TAGS, self::recorder($steps));

        self::assertSame(['GetResources', 'DescribeRule', 'DescribeRule', 'RemoveTargets', 'DeleteRule', 'RemovePermission', 'PutRule', 'PutTargets', 'AddPermission'], $mocks->calls);
        self::assertSame([$cleanup, $gone], [$mocks->params[1]['Name'], $mocks->params[2]['Name']], 'only this environment\'s schedule rules are read');
        self::assertSame(['Rule' => $gone, 'Ids' => ['arco-console']], array_intersect_key($mocks->params[3], ['Rule' => 1, 'Ids' => 1]));
        self::assertSame($gone, $mocks->params[4]['Name']);
        self::assertSame('arco-schedule-' . substr(hash('sha256', 'shop/production/gone'), 0, 8), $mocks->params[5]['StatementId']);
        self::assertSame('ENABLED', $mocks->params[6]['State']);
        foreach ($mocks->params as $params) {
            self::assertNotSame('arco-shop-production-scheduler', $params['Name'] ?? $params['Rule'] ?? null);
        }
        self::assertSame([
            ['deploy.schedule_deleted', ['schedule' => 'gone', 'rule' => $gone]],
            ['deploy.schedule_updated', ['schedule' => 'cleanup', 'rule' => $cleanup]],
        ], $steps);
    }

    public function test_no_schedules_left_only_deletes_the_stale_rules(): void
    {
        $config = self::config([]);
        $gone = ProcessSchedules::ruleName($config, 'gone');
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::listed([$gone => [...self::TAGS, 'arco:schedule' => 'gone']]),
            new Result(['Name' => $gone, 'State' => 'DISABLED']),
            new Result([]), new Result([]),
            AwsMocks::error(LambdaException::class, 'RemovePermission', 'ResourceNotFoundException', 404), // the console is already gone
        );
        $steps = [];

        self::schedules($mocks)->sync($config, null, self::TAGS, self::recorder($steps));

        self::assertSame(['GetResources', 'DescribeRule', 'RemoveTargets', 'DeleteRule', 'RemovePermission'], $mocks->calls);
        self::assertSame([['deploy.schedule_deleted', ['schedule' => 'gone', 'rule' => $gone]]], $steps);
    }

    public function test_a_rule_of_the_same_name_owned_by_another_environment_stops_before_any_change(): void
    {
        $config = self::config(['cleanup' => ['every' => '1h']]);
        $cleanup = ProcessSchedules::ruleName($config, 'cleanup');
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::listed([]),
            new Result(['Name' => $cleanup, 'Arn' => self::arn($cleanup), 'State' => 'ENABLED']),
            new Result(['Tags' => [['Key' => 'arco:app', 'Value' => 'shop'], ['Key' => 'arco:environment', 'Value' => 'staging']]]),
        );

        try {
            self::schedules($mocks)->sync($config, self::ALIAS, self::TAGS, fn () => null);
            self::fail('expected a refusal');
        } catch (DeployException $e) {
            self::assertSame('deploy.not_owned', $e->key);
        }
        self::assertSame(['GetResources', 'DescribeRule', 'ListTagsForResource'], $mocks->calls);
    }

    public function test_a_rule_the_tag_listing_misses_but_tagged_for_this_environment_is_updated(): void
    {
        $config = self::config(['cleanup' => ['every' => '1h']]);
        $cleanup = ProcessSchedules::ruleName($config, 'cleanup');
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::listed([]),
            new Result(['Name' => $cleanup, 'Arn' => self::arn($cleanup), 'State' => 'DISABLED']),
            new Result(['Tags' => [['Key' => 'arco:app', 'Value' => 'shop'], ['Key' => 'arco:environment', 'Value' => 'production'], ['Key' => 'arco:schedule', 'Value' => 'cleanup']]]),
            ...self::put($cleanup),
        );
        $steps = [];

        self::schedules($mocks)->sync($config, self::ALIAS, self::TAGS, self::recorder($steps));

        self::assertSame('DISABLED', $mocks->params[3]['State']);
        self::assertSame('deploy.schedule_updated', $steps[0][0]);
    }

    public function test_rules_pause_resume_and_delete_all_by_tag(): void
    {
        $config = self::config(['cleanup' => ['every' => '1h'], 'report' => ['every' => '1d']]);
        $cleanup = ProcessSchedules::ruleName($config, 'cleanup');
        $report = ProcessSchedules::ruleName($config, 'report');
        $listing = self::listed([$report => [...self::TAGS, 'arco:schedule' => 'report'], $cleanup => [...self::TAGS, 'arco:schedule' => 'cleanup']]);
        $mocks = new AwsMocks();
        $mocks->handler->append(
            // rules()
            $listing, new Result(['State' => 'ENABLED']), new Result(['State' => 'DISABLED']),
            // pauseAll(): report is enabled, cleanup already paused
            $listing, new Result(['State' => 'ENABLED']), new Result(['State' => 'DISABLED']), new Result([]),
            // resumeAll()
            $listing, new Result(['State' => 'DISABLED']), new Result(['State' => 'DISABLED']), new Result([]), new Result([]),
            // deleteAll()
            $listing, new Result(['State' => 'ENABLED']), new Result(['State' => 'ENABLED']),
            new Result([]), new Result([]), new Result([]), new Result([]), new Result([]), new Result([]),
        );
        $schedules = self::schedules($mocks);

        self::assertSame(['cleanup' => ['rule' => $cleanup, 'state' => 'DISABLED'], 'report' => ['rule' => $report, 'state' => 'ENABLED']], $schedules->rules($config));

        $mocks->calls = [];
        self::assertSame([['schedule' => 'cleanup', 'rule' => $cleanup, 'changed' => false], ['schedule' => 'report', 'rule' => $report, 'changed' => true]], $schedules->pauseAll($config));
        self::assertSame(['GetResources', 'DescribeRule', 'DescribeRule', 'DisableRule'], $mocks->calls);

        $mocks->calls = [];
        self::assertSame([['schedule' => 'cleanup', 'rule' => $cleanup, 'changed' => true], ['schedule' => 'report', 'rule' => $report, 'changed' => true]], $schedules->resumeAll($config));
        self::assertSame(['GetResources', 'DescribeRule', 'DescribeRule', 'EnableRule', 'EnableRule'], $mocks->calls);

        $mocks->calls = [];
        $schedules->deleteAll($config);
        self::assertSame(['GetResources', 'DescribeRule', 'DescribeRule', 'RemoveTargets', 'DeleteRule', 'RemovePermission', 'RemoveTargets', 'DeleteRule', 'RemovePermission'], $mocks->calls);
        self::assertCount(0, $mocks->handler);
    }
}
