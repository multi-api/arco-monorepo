<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy;

use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\Result;
use Multiapi\Arco\Deploy\Tables;
use PHPUnit\Framework\TestCase;

final class TablesTest extends TestCase
{
    private function tables(AwsMocks $mocks): Tables
    {
        return new Tables($mocks->clients()->dynamoDb());
    }

    private static function missing(): DynamoDbException
    {
        return AwsMocks::error(DynamoDbException::class, 'DescribeTable', 'ResourceNotFoundException', 400);
    }

    public function test_exists(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(new Result(['Table' => ['TableName' => 'shop-production-cache', 'ItemCount' => 3]]), self::missing());
        $tables = $this->tables($mocks);

        self::assertTrue($tables->exists('shop-production-cache'));
        self::assertFalse($tables->exists('shop-production-cache'));
        self::assertSame(['DescribeTable', 'DescribeTable'], $mocks->calls);
        self::assertSame('shop-production-cache', $mocks->params[0]['TableName']);
    }

    public function test_exists_does_not_guess_on_other_errors(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(AwsMocks::error(DynamoDbException::class, 'DescribeTable', 'ValidationException', 400));

        $this->expectException(DynamoDbException::class);
        $this->tables($mocks)->exists('shop-production-cache');
    }

    public function test_a_profile_that_may_not_describe_tables_gets_an_arco_error_naming_the_permission(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(AwsMocks::error(DynamoDbException::class, 'DescribeTable', 'AccessDeniedException', 400));

        try {
            $this->tables($mocks)->exists('shop-production-cache');
            self::fail('expected deploy.lookup_denied');
        } catch (\Multiapi\Arco\Deploy\DeployException $e) {
            self::assertSame('deploy.lookup_denied', $e->key);
            self::assertSame(['permission' => 'dynamodb:DescribeTable', 'name' => 'shop-production-cache'], $e->params);
        }
    }

    public function test_item_count(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(new Result(['Table' => ['ItemCount' => 42]]), self::missing());
        $tables = $this->tables($mocks);

        self::assertSame(42, $tables->itemCount('shop-production-cache'));
        self::assertNull($tables->itemCount('shop-production-cache'), 'unreadable: unknown');
    }
}
