<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Logs;

use Multiapi\Arco\Logs\LogGroups;
use PHPUnit\Framework\TestCase;

final class LogGroupsTest extends TestCase
{
    public function test_the_arn_partition_follows_the_region(): void
    {
        self::assertSame('arn:aws:logs:eu-west-1:111111111111:log-group:/aws/lambda/x', LogGroups::arn('eu-west-1', '111111111111', '/aws/lambda/x'));
        self::assertSame('arn:aws-cn:logs:cn-north-1:111111111111:log-group:/aws/lambda/x', LogGroups::arn('cn-north-1', '111111111111', '/aws/lambda/x'));
        self::assertSame('arn:aws-us-gov:logs:us-gov-west-1:111111111111:log-group:/aws/lambda/x', LogGroups::arn('us-gov-west-1', '111111111111', '/aws/lambda/x'));
    }
}
