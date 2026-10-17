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
}
