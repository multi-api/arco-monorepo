<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy;

use Aws\CloudWatchLogs\Exception\CloudWatchLogsException;
use Aws\Result;
use Multiapi\Arco\Deploy\LogRetention;
use PHPUnit\Framework\TestCase;

final class LogRetentionTest extends TestCase
{
    private const GROUPS = ['/aws/lambda/web/f', '/aws/lambda/web/f.local', '/aws/lambda/f-console'];

    public function test_creates_each_group_then_puts_the_retention(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(...array_merge(...array_fill(0, 3, [self::none(), new Result(), new Result()])));

        $result = (new LogRetention($mocks->clients()->logs()))->apply(self::GROUPS, 30);

        self::assertSame(['DescribeLogGroups', 'CreateLogGroup', 'PutRetentionPolicy', 'DescribeLogGroups', 'CreateLogGroup', 'PutRetentionPolicy', 'DescribeLogGroups', 'CreateLogGroup', 'PutRetentionPolicy'], $mocks->calls);
        self::assertSame('/aws/lambda/web/f', $mocks->params[0]['logGroupNamePrefix']);
        self::assertEquals(['logGroupName' => '/aws/lambda/web/f'], array_intersect_key($mocks->params[1], ['logGroupName' => '/aws/lambda/web/f']));
        self::assertEquals(['logGroupName' => '/aws/lambda/web/f', 'retentionInDays' => 30], array_intersect_key($mocks->params[2], ['logGroupName' => '/aws/lambda/web/f', 'retentionInDays' => 30]));
        self::assertEquals(['logGroupName' => '/aws/lambda/f-console', 'retentionInDays' => 30], array_intersect_key($mocks->params[8], ['logGroupName' => '/aws/lambda/f-console', 'retentionInDays' => 30]));
        self::assertSame(['untagged' => [], 'changed' => self::GROUPS], $result);
    }

    public function test_false_deletes_the_retention_policy(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(self::kept('/aws/lambda/web/f', 30), self::exists(), new Result());

        (new LogRetention($mocks->clients()->logs()))->apply(['/aws/lambda/web/f'], false);

        self::assertSame(['DescribeLogGroups', 'CreateLogGroup', 'DeleteRetentionPolicy'], $mocks->calls);
        self::assertEquals(['logGroupName' => '/aws/lambda/web/f'], array_intersect_key($mocks->params[2], ['logGroupName' => '/aws/lambda/web/f']));
    }

    public function test_existing_group_is_tolerated(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(self::kept('/aws/lambda/web/f', null), self::exists(), new Result());

        (new LogRetention($mocks->clients()->logs()))->apply(['/aws/lambda/web/f'], 7);

        self::assertSame(['DescribeLogGroups', 'CreateLogGroup', 'PutRetentionPolicy'], $mocks->calls);
    }

    public function test_other_errors_propagate(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(self::none(), AwsMocks::error(CloudWatchLogsException::class, 'CreateLogGroup', 'AccessDeniedException', 400));

        $this->expectException(CloudWatchLogsException::class);
        (new LogRetention($mocks->clients()->logs()))->apply(['/aws/lambda/web/f'], 7);
    }
    private const TAGS = ['arco:app' => 'shop', 'arco:environment' => 'production'];

    public function test_a_new_group_is_created_with_both_tags(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(self::none(), new Result(), new Result());

        $failed = (new LogRetention($mocks->clients()->logs()))->apply(['/aws/lambda/web/f'], 30, self::TAGS, fn (string $group) => "arn:aws:logs:us-east-1:111111111111:log-group:$group")['untagged'];

        self::assertSame(['DescribeLogGroups', 'CreateLogGroup', 'PutRetentionPolicy'], $mocks->calls);
        self::assertSame(self::TAGS, $mocks->params[1]['tags']);
        self::assertSame([], $failed);
    }

    public function test_an_existing_group_is_adopted_and_gets_both_tags(): void
    {
        // Lambda creates the group untagged on the first invoke: Arco adopts it.
        $mocks = new AwsMocks();
        $mocks->handler->append(self::kept('/aws/lambda/web/f', null), self::exists(), new Result(), new Result());

        $failed = (new LogRetention($mocks->clients()->logs()))->apply(['/aws/lambda/web/f'], 30, self::TAGS, fn (string $group) => "arn:aws:logs:us-east-1:111111111111:log-group:$group")['untagged'];

        self::assertSame(['DescribeLogGroups', 'CreateLogGroup', 'TagResource', 'PutRetentionPolicy'], $mocks->calls);
        self::assertSame('arn:aws:logs:us-east-1:111111111111:log-group:/aws/lambda/web/f', $mocks->params[2]['resourceArn']);
        self::assertSame(self::TAGS, $mocks->params[2]['tags']);
        self::assertSame([], $failed);
    }

    public function test_tags_the_profile_may_not_add_are_reported_and_the_retention_is_still_set(): void
    {
        // Without logs:TagResource: a new group is created untagged, an existing one keeps its tags.
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::none(),
            AwsMocks::error(CloudWatchLogsException::class, 'CreateLogGroup', 'AccessDeniedException', 400, 'not authorized to perform: logs:TagResource'),
            new Result(),
            new Result(),
            self::kept('/aws/lambda/f-console', null),
            self::exists(),
            AwsMocks::error(CloudWatchLogsException::class, 'TagResource', 'AccessDeniedException', 400, 'not authorized to perform: logs:TagResource'),
            new Result(),
        );

        $failed = (new LogRetention($mocks->clients()->logs()))->apply(['/aws/lambda/web/f', '/aws/lambda/f-console'], 7, self::TAGS, fn (string $group) => "arn:aws:logs:us-east-1:111111111111:log-group:$group")['untagged'];

        self::assertSame(['DescribeLogGroups', 'CreateLogGroup', 'CreateLogGroup', 'PutRetentionPolicy', 'DescribeLogGroups', 'CreateLogGroup', 'TagResource', 'PutRetentionPolicy'], $mocks->calls);
        self::assertArrayNotHasKey('tags', $mocks->params[2], 'created again without tags');
        self::assertSame([
            ['group' => '/aws/lambda/web/f', 'error' => 'not authorized to perform: logs:TagResource'],
            ['group' => '/aws/lambda/f-console', 'error' => 'not authorized to perform: logs:TagResource'],
        ], $failed);
    }

    private static function none(): Result
    {
        return new Result(['logGroups' => []]);
    }

    private static function kept(string $group, ?int $days): Result
    {
        // Sorted by name, as CloudWatch answers: the exact name before the longer ones it is a prefix of.
        return new Result(['logGroups' => [['logGroupName' => $group] + ($days === null ? [] : ['retentionInDays' => $days]), ['logGroupName' => "$group-other", 'retentionInDays' => 1]]]);
    }

    private static function exists(): CloudWatchLogsException
    {
        return AwsMocks::error(CloudWatchLogsException::class, 'CreateLogGroup', 'ResourceAlreadyExistsException', 400);
    }

    public function test_a_group_that_already_keeps_the_retention_gets_no_call_and_is_not_changed(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(self::kept('/aws/lambda/web/f', 30), self::exists(), new Result(), self::kept('/aws/lambda/f-console', 14), self::exists(), new Result(), new Result());

        $result = (new LogRetention($mocks->clients()->logs()))->apply(['/aws/lambda/web/f', '/aws/lambda/f-console'], 30, self::TAGS, fn (string $group) => "arn:aws:logs:us-east-1:111111111111:log-group:$group");

        self::assertSame(['DescribeLogGroups', 'CreateLogGroup', 'TagResource', 'DescribeLogGroups', 'CreateLogGroup', 'TagResource', 'PutRetentionPolicy'], $mocks->calls, 'only the 14-day group gets PutRetentionPolicy');
        self::assertSame('/aws/lambda/f-console', $mocks->params[6]['logGroupName']);
        self::assertSame(['untagged' => [], 'changed' => ['/aws/lambda/f-console']], $result);
    }

    public function test_never_expire_on_a_group_without_a_policy_or_a_new_one_makes_no_delete_call(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(self::kept('/aws/lambda/web/f', null), self::exists(), self::none(), new Result());

        $result = (new LogRetention($mocks->clients()->logs()))->apply(['/aws/lambda/web/f', '/aws/lambda/f-console'], false);

        self::assertSame(['DescribeLogGroups', 'CreateLogGroup', 'DescribeLogGroups', 'CreateLogGroup'], $mocks->calls);
        self::assertSame(['/aws/lambda/f-console'], $result['changed'], 'the new group');
    }

    public function test_a_refused_retention_read_keeps_the_calls_of_before_0_9(): void
    {
        $denied = fn () => AwsMocks::error(CloudWatchLogsException::class, 'DescribeLogGroups', 'AccessDeniedException', 400, 'not authorized to perform: logs:DescribeLogGroups');
        $mocks = new AwsMocks();
        $mocks->handler->append($denied(), self::exists(), new Result(), $denied(), self::exists(), new Result());

        $result = (new LogRetention($mocks->clients()->logs()))->apply(['/aws/lambda/web/f', '/aws/lambda/f-console'], false);

        self::assertSame(['DescribeLogGroups', 'CreateLogGroup', 'DeleteRetentionPolicy', 'DescribeLogGroups', 'CreateLogGroup', 'DeleteRetentionPolicy'], $mocks->calls);
        self::assertSame(['/aws/lambda/web/f', '/aws/lambda/f-console'], $result['changed'], 'unknown: may have changed');
    }
}
