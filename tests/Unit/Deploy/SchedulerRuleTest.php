<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy;

use Aws\EventBridge\Exception\EventBridgeException;
use Aws\Lambda\Exception\LambdaException;
use Aws\Result;
use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Deploy\SchedulerRule;
use PHPUnit\Framework\TestCase;

final class SchedulerRuleTest extends TestCase
{
    private function config(string $app = 'shop', int $every = 5): AppConfig
    {
        return ConfigResolver::resolve(App::make($app)->region('us-east-1')->scheduler($every)->environment('production')->toArray(), 'production');
    }

    private static function rule(AwsMocks $mocks): SchedulerRule
    {
        $clients = $mocks->clients();
        return new SchedulerRule($clients->eventBridge(), $clients->lambda());
    }

    public function test_expressions_are_aligned_to_the_clock(): void
    {
        self::assertSame('cron(* * * * ? *)', SchedulerRule::expression(1));
        self::assertSame('cron(0/5 * * * ? *)', SchedulerRule::expression(5));
        self::assertSame('cron(0 * * * ? *)', SchedulerRule::expression(60));
    }

    public function test_name(): void
    {
        self::assertSame('arco-shop-production-scheduler', SchedulerRule::name($this->config()));

        $long = $this->config(str_repeat('x', 50));
        $name = SchedulerRule::name($long);
        self::assertLessThanOrEqual(64, strlen($name));
        self::assertSame(64, strlen($name));
        self::assertStringStartsWith('arco-xxxx', $name);
        self::assertSame($name, SchedulerRule::name($this->config(str_repeat('x', 50))), 'stable');
        self::assertNotSame($name, SchedulerRule::name($this->config(str_repeat('x', 49) . 'y')));
    }

    public function test_put_creates_the_rule_its_target_and_the_invoke_permission(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            AwsMocks::error(EventBridgeException::class, 'DescribeRule', 'ResourceNotFoundException', 400),
            new Result(['RuleArn' => 'arn:aws:events:us-east-1:123456789012:rule/arco-shop-production-scheduler']),
            new Result(['FailedEntryCount' => 0, 'FailedEntries' => []]),
            new Result(['Statement' => '{}']),
        );

        self::rule($mocks)->put($this->config(), 'arn:aws:lambda:us-east-1:123456789012:function:shop-production-console:live', ['arco:app' => 'shop', 'arco:environment' => 'production']);

        self::assertSame(['DescribeRule', 'PutRule', 'PutTargets', 'AddPermission'], $mocks->calls);
        array_shift($mocks->params);
        self::assertSame('arco-shop-production-scheduler', $mocks->params[0]['Name']);
        self::assertSame('cron(0/5 * * * ? *)', $mocks->params[0]['ScheduleExpression']);
        self::assertSame('ENABLED', $mocks->params[0]['State']);
        self::assertSame([['Key' => 'arco:app', 'Value' => 'shop'], ['Key' => 'arco:environment', 'Value' => 'production']], $mocks->params[0]['Tags']);
        self::assertSame('arco-shop-production-scheduler', $mocks->params[1]['Rule']);
        self::assertSame([[
            'Id' => 'arco-console',
            'Arn' => 'arn:aws:lambda:us-east-1:123456789012:function:shop-production-console:live',
            'Input' => '{"command":"schedule:run"}',
        ]], $mocks->params[1]['Targets']);
        self::assertSame('shop-production-console', $mocks->params[2]['FunctionName']);
        self::assertSame('live', $mocks->params[2]['Qualifier']);
        self::assertSame('arco-scheduler', $mocks->params[2]['StatementId']);
        self::assertSame('lambda:InvokeFunction', $mocks->params[2]['Action']);
        self::assertSame('events.amazonaws.com', $mocks->params[2]['Principal']);
        self::assertSame('arn:aws:events:us-east-1:123456789012:rule/arco-shop-production-scheduler', $mocks->params[2]['SourceArn']);
    }

    public function test_put_tolerates_an_existing_permission(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['Name' => 'arco-shop-production-scheduler', 'State' => 'ENABLED']),
            new Result(['RuleArn' => 'arn:rule']),
            new Result(['FailedEntryCount' => 0]),
            AwsMocks::error(LambdaException::class, 'AddPermission', 'ResourceConflictException', 409),
        );

        self::rule($mocks)->put($this->config(), 'arn:alias', []);

        self::assertSame(['DescribeRule', 'PutRule', 'PutTargets', 'AddPermission'], $mocks->calls);
        self::assertCount(0, $mocks->handler);
    }

    public function test_put_fails_when_the_target_is_refused(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['Name' => 'arco-shop-production-scheduler', 'State' => 'ENABLED']),
            new Result(['RuleArn' => 'arn:rule']),
            new Result(['FailedEntryCount' => 1, 'FailedEntries' => [['TargetId' => 'arco-console', 'ErrorCode' => 'X', 'ErrorMessage' => 'bad target']]]),
        );

        $this->expectExceptionMessage('bad target');
        self::rule($mocks)->put($this->config(), 'arn:alias', []);
    }

    public function test_put_keeps_a_paused_rule_paused_and_creates_new_rules_enabled(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['Name' => 'arco-shop-production-scheduler', 'State' => 'DISABLED']),
            new Result(['RuleArn' => 'arn:rule']),
            new Result(['FailedEntryCount' => 0]),
            new Result([]),
            AwsMocks::error(EventBridgeException::class, 'DescribeRule', 'ResourceNotFoundException', 400),
            new Result(['RuleArn' => 'arn:rule']),
            new Result(['FailedEntryCount' => 0]),
            new Result([]),
        );
        $rule = self::rule($mocks);

        self::assertTrue($rule->put($this->config(), 'arn:alias', []), 'kept paused');
        self::assertSame('DISABLED', $mocks->params[1]['State']);
        self::assertFalse($rule->put($this->config(), 'arn:alias', []));
        self::assertSame('ENABLED', $mocks->params[5]['State']);
    }

    public function test_state_pause_and_resume(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['Name' => 'arco-shop-production-scheduler', 'State' => 'DISABLED']),
            AwsMocks::error(EventBridgeException::class, 'DescribeRule', 'ResourceNotFoundException', 400),
            new Result([]),
            new Result([]),
        );
        $rule = self::rule($mocks);

        self::assertSame('DISABLED', $rule->state($this->config()));
        self::assertNull($rule->state($this->config()));
        $rule->pause($this->config());
        $rule->resume($this->config());

        self::assertSame(['DescribeRule', 'DescribeRule', 'DisableRule', 'EnableRule'], $mocks->calls);
        self::assertSame('arco-shop-production-scheduler', $mocks->params[2]['Name']);
        self::assertSame('arco-shop-production-scheduler', $mocks->params[3]['Name']);
    }

    public function test_delete_removes_target_rule_and_permission(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(new Result(['FailedEntryCount' => 0]), new Result([]), new Result([]));

        self::rule($mocks)->delete($this->config());

        self::assertSame(['RemoveTargets', 'DeleteRule', 'RemovePermission'], $mocks->calls);
        self::assertSame(['Rule' => 'arco-shop-production-scheduler', 'Ids' => ['arco-console']], array_intersect_key($mocks->params[0], ['Rule' => 1, 'Ids' => 1]));
        self::assertSame('arco-shop-production-scheduler', $mocks->params[1]['Name']);
        self::assertSame('shop-production-console', $mocks->params[2]['FunctionName']);
        self::assertSame('live', $mocks->params[2]['Qualifier']);
        self::assertSame('arco-scheduler', $mocks->params[2]['StatementId']);
    }

    public function test_delete_of_missing_pieces_is_fine(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            AwsMocks::error(EventBridgeException::class, 'RemoveTargets', 'ResourceNotFoundException', 400),
            AwsMocks::error(EventBridgeException::class, 'DeleteRule', 'ResourceNotFoundException', 400),
            AwsMocks::error(LambdaException::class, 'RemovePermission', 'ResourceNotFoundException', 404),
        );

        self::rule($mocks)->delete($this->config());

        self::assertSame(['RemoveTargets', 'DeleteRule', 'RemovePermission'], $mocks->calls);
    }

    public function test_delete_raises_other_errors(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(AwsMocks::error(EventBridgeException::class, 'RemoveTargets', 'AccessDeniedException', 400, 'denied'));

        $this->expectException(EventBridgeException::class);
        self::rule($mocks)->delete($this->config());
    }

    public function test_exists(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['Name' => 'arco-shop-production-scheduler']),
            AwsMocks::error(EventBridgeException::class, 'DescribeRule', 'ResourceNotFoundException', 400),
        );
        $rule = self::rule($mocks);

        self::assertTrue($rule->exists($this->config()));
        self::assertFalse($rule->exists($this->config()));
        self::assertSame(['DescribeRule', 'DescribeRule'], $mocks->calls);
        self::assertSame('arco-shop-production-scheduler', $mocks->params[0]['Name']);
    }

    public function test_has_invoke_permission_reads_the_alias_policy(): void
    {
        $policy = fn (string ...$sids) => new Result(['Policy' => json_encode(['Statement' => array_map(fn (string $sid) => ['Sid' => $sid], $sids)])]);
        $mocks = new AwsMocks();
        $mocks->handler->append(
            $policy('other', 'arco-scheduler'),
            $policy('other'),
            AwsMocks::error(LambdaException::class, 'GetPolicy', 'ResourceNotFoundException', 404),
            AwsMocks::error(LambdaException::class, 'GetPolicy', 'AccessDeniedException', 403),
        );
        $rule = self::rule($mocks);

        self::assertTrue($rule->hasInvokePermission($this->config()));
        self::assertFalse($rule->hasInvokePermission($this->config()));
        self::assertFalse($rule->hasInvokePermission($this->config()));
        self::assertFalse($rule->hasInvokePermission($this->config()));
        self::assertSame('shop-production-console', $mocks->params[0]['FunctionName']);
        self::assertSame('live', $mocks->params[0]['Qualifier']);
    }
}
