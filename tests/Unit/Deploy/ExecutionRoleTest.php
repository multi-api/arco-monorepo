<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy;

use Aws\Iam\Exception\IamException;
use Aws\Result;
use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Deploy\ExecutionRole;
use PHPUnit\Framework\TestCase;

final class ExecutionRoleTest extends TestCase
{
    public function test_creates_role_and_always_rewrites_policy(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            AwsMocks::error(IamException::class, 'GetRole', 'NoSuchEntity', 404),
            new Result(['Role' => ['Arn' => 'arn:aws:iam::1:role/arco-shop-production']]),
            new Result([]),
        );

        $role = (new ExecutionRole($mocks->clients()->iam()))->ensure('arco-shop-production', ['Version' => '2012-10-17', 'Statement' => []], ['arco:app' => 'shop']);

        self::assertSame(['arn' => 'arn:aws:iam::1:role/arco-shop-production', 'created' => true], $role);
        self::assertSame(['GetRole', 'CreateRole', 'PutRolePolicy'], $mocks->calls);
        self::assertStringContainsString('lambda.amazonaws.com', $mocks->params[1]['AssumeRolePolicyDocument']);
        self::assertSame('arco', $mocks->params[2]['PolicyName']);
    }

    public function test_existing_role(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(new Result(['Role' => ['Arn' => 'arn:x']]), new Result([]));

        $role = (new ExecutionRole($mocks->clients()->iam()))->ensure('r', ['Version' => '2012-10-17', 'Statement' => []], []);

        self::assertSame(['arn' => 'arn:x', 'created' => false], $role);
        self::assertSame(['GetRole', 'PutRolePolicy'], $mocks->calls);
    }

    public function test_role_name_is_truncated_to_64(): void
    {
        $config = ConfigResolver::resolve(App::make(str_repeat('a', 40))->region('us-east-1')->environment(str_repeat('b', 23))->toArray(), str_repeat('b', 23));
        self::assertSame(64, strlen(ExecutionRole::roleName($config)));
    }
}
