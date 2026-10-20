<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy;

use Aws\Iam\Exception\IamException;
use Aws\Result;
use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Deploy\ExecutionRole;
use Multiapi\Arco\Deploy\DeployException;
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

    public function test_new_role_is_tagged_with_its_region(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            AwsMocks::error(IamException::class, 'GetRole', 'NoSuchEntity', 404),
            new Result(['Role' => ['Arn' => 'arn:x']]),
            new Result([]),
        );

        (new ExecutionRole($mocks->clients()->iam()))->ensure('r', ['Version' => '2012-10-17', 'Statement' => []], ['arco:app' => 'shop'], 'eu-west-1');

        self::assertSame(['GetRole', 'CreateRole', 'PutRolePolicy'], $mocks->calls);
        self::assertContains(['Key' => 'arco:region', 'Value' => 'eu-west-1'], $mocks->params[1]['Tags']);
    }

    public function test_a_role_of_another_region_is_refused_before_its_policy_is_rewritten(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(new Result(['Role' => ['Arn' => 'arn:x', 'Tags' => [['Key' => 'arco:region', 'Value' => 'us-east-1']]]]));

        try {
            (new ExecutionRole($mocks->clients()->iam()))->ensure('r', ['Version' => '2012-10-17', 'Statement' => []], [], 'eu-west-1');
            self::fail('expected a refusal');
        } catch (DeployException $e) {
            self::assertSame('deploy.region_changed', $e->key);
            self::assertSame(['resource' => 'r', 'current' => 'us-east-1', 'region' => 'eu-west-1'], $e->params);
        }
        self::assertSame(['GetRole'], $mocks->calls, 'no TagRole, no PutRolePolicy');
    }

    public function test_a_role_of_the_same_region_needs_no_extra_call(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(new Result(['Role' => ['Arn' => 'arn:x', 'Tags' => [['Key' => 'arco:region', 'Value' => 'eu-west-1']]]]), new Result([]));

        (new ExecutionRole($mocks->clients()->iam()))->ensure('r', ['Version' => '2012-10-17', 'Statement' => []], [], 'eu-west-1');

        self::assertSame(['GetRole', 'PutRolePolicy'], $mocks->calls);
    }

    public function test_an_untagged_role_proceeds_and_gets_the_region_tag(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(new Result(['Role' => ['Arn' => 'arn:x', 'Tags' => [['Key' => 'arco:app', 'Value' => 'shop']]]]), new Result([]), new Result([]));

        $role = (new ExecutionRole($mocks->clients()->iam()))->ensure('r', ['Version' => '2012-10-17', 'Statement' => []], [], 'eu-west-1');

        self::assertFalse($role['created']);
        self::assertSame(['GetRole', 'TagRole', 'PutRolePolicy'], $mocks->calls);
        self::assertSame([['Key' => 'arco:region', 'Value' => 'eu-west-1']], $mocks->params[1]['Tags']);
    }

    public function test_the_region_refusal_reads_in_both_languages(): void
    {
        foreach (['en', 'es'] as $lang) {
            $table = require dirname(__DIR__, 3) . "/packages/arco/lang/$lang.php";
            self::assertStringContainsString('{current}', $table['deploy.region_changed']);
            self::assertStringContainsString('{region}', $table['deploy.region_changed']);
        }
    }

    public function test_a_refused_tag_on_an_untagged_role_is_warned_not_silent(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['Role' => ['Arn' => 'arn:x']]),
            AwsMocks::error(IamException::class, 'TagRole', 'AccessDenied', 403),
            new Result([]),
        );
        $steps = [];

        (new ExecutionRole($mocks->clients()->iam()))->ensure('r', ['Version' => '2012-10-17', 'Statement' => []], [], 'eu-west-1', function (string $key, array $params) use (&$steps): void { $steps[] = [$key, $params]; });

        self::assertSame([['deploy.tags_not_added', ['resource' => 'r', 'error' => 'AccessDenied']]], $steps);
        self::assertSame(['GetRole', 'TagRole', 'PutRolePolicy'], $mocks->calls);
    }
}
