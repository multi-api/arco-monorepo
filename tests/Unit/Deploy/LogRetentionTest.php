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
        $mocks->handler->append(...array_fill(0, 6, new Result()));

        (new LogRetention($mocks->clients()->logs()))->apply(self::GROUPS, 30);

        self::assertSame(['CreateLogGroup', 'PutRetentionPolicy', 'CreateLogGroup', 'PutRetentionPolicy', 'CreateLogGroup', 'PutRetentionPolicy'], $mocks->calls);
        self::assertEquals(['logGroupName' => '/aws/lambda/web/f'], array_intersect_key($mocks->params[0], ['logGroupName' => '/aws/lambda/web/f']));
        self::assertEquals(['logGroupName' => '/aws/lambda/web/f', 'retentionInDays' => 30], array_intersect_key($mocks->params[1], ['logGroupName' => '/aws/lambda/web/f', 'retentionInDays' => 30]));
        self::assertEquals(['logGroupName' => '/aws/lambda/f-console', 'retentionInDays' => 30], array_intersect_key($mocks->params[5], ['logGroupName' => '/aws/lambda/f-console', 'retentionInDays' => 30]));
    }

    public function test_false_deletes_the_retention_policy(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(new Result(), new Result());

        (new LogRetention($mocks->clients()->logs()))->apply(['/aws/lambda/web/f'], false);

        self::assertSame(['CreateLogGroup', 'DeleteRetentionPolicy'], $mocks->calls);
        self::assertEquals(['logGroupName' => '/aws/lambda/web/f'], array_intersect_key($mocks->params[1], ['logGroupName' => '/aws/lambda/web/f']));
    }

    public function test_existing_group_is_tolerated(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(AwsMocks::error(CloudWatchLogsException::class, 'CreateLogGroup', 'ResourceAlreadyExistsException', 400), new Result());

        (new LogRetention($mocks->clients()->logs()))->apply(['/aws/lambda/web/f'], 7);

        self::assertSame(['CreateLogGroup', 'PutRetentionPolicy'], $mocks->calls);
    }

    public function test_other_errors_propagate(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(AwsMocks::error(CloudWatchLogsException::class, 'CreateLogGroup', 'AccessDeniedException', 400));

        $this->expectException(CloudWatchLogsException::class);
        (new LogRetention($mocks->clients()->logs()))->apply(['/aws/lambda/web/f'], 7);
    }
    private const TAGS = ['arco:app' => 'shop', 'arco:environment' => 'production'];

    public function test_a_new_group_is_created_with_both_tags(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(new Result(), new Result());

        $failed = (new LogRetention($mocks->clients()->logs()))->apply(['/aws/lambda/web/f'], 30, self::TAGS, fn (string $group) => "arn:aws:logs:us-east-1:111111111111:log-group:$group");

        self::assertSame(['CreateLogGroup', 'PutRetentionPolicy'], $mocks->calls);
        self::assertSame(self::TAGS, $mocks->params[0]['tags']);
        self::assertSame([], $failed);
    }

    public function test_an_existing_group_is_adopted_and_gets_both_tags(): void
    {
        // Lambda creates the group untagged on the first invoke: Arco adopts it.
        $mocks = new AwsMocks();
        $mocks->handler->append(AwsMocks::error(CloudWatchLogsException::class, 'CreateLogGroup', 'ResourceAlreadyExistsException', 400), new Result(), new Result());

        $failed = (new LogRetention($mocks->clients()->logs()))->apply(['/aws/lambda/web/f'], 30, self::TAGS, fn (string $group) => "arn:aws:logs:us-east-1:111111111111:log-group:$group");

        self::assertSame(['CreateLogGroup', 'TagResource', 'PutRetentionPolicy'], $mocks->calls);
        self::assertSame('arn:aws:logs:us-east-1:111111111111:log-group:/aws/lambda/web/f', $mocks->params[1]['resourceArn']);
        self::assertSame(self::TAGS, $mocks->params[1]['tags']);
        self::assertSame([], $failed);
    }

    public function test_tags_the_profile_may_not_add_are_reported_and_the_retention_is_still_set(): void
    {
        // Without logs:TagResource: a new group is created untagged, an existing one keeps its tags.
        $mocks = new AwsMocks();
        $mocks->handler->append(
            AwsMocks::error(CloudWatchLogsException::class, 'CreateLogGroup', 'AccessDeniedException', 400, 'not authorized to perform: logs:TagResource'),
            new Result(),
            new Result(),
            AwsMocks::error(CloudWatchLogsException::class, 'CreateLogGroup', 'ResourceAlreadyExistsException', 400),
            AwsMocks::error(CloudWatchLogsException::class, 'TagResource', 'AccessDeniedException', 400, 'not authorized to perform: logs:TagResource'),
            new Result(),
        );

        $failed = (new LogRetention($mocks->clients()->logs()))->apply(['/aws/lambda/web/f', '/aws/lambda/f-console'], 7, self::TAGS, fn (string $group) => "arn:aws:logs:us-east-1:111111111111:log-group:$group");

        self::assertSame(['CreateLogGroup', 'CreateLogGroup', 'PutRetentionPolicy', 'CreateLogGroup', 'TagResource', 'PutRetentionPolicy'], $mocks->calls);
        self::assertArrayNotHasKey('tags', $mocks->params[1], 'created again without tags');
        self::assertSame([
            ['group' => '/aws/lambda/web/f', 'error' => 'not authorized to perform: logs:TagResource'],
            ['group' => '/aws/lambda/f-console', 'error' => 'not authorized to perform: logs:TagResource'],
        ], $failed);
    }
}
