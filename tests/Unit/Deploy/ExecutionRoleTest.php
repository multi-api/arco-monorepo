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

    private const BOUNDARY = 'arn:aws:iam::123456789012:policy/arco-boundary';
    private const POLICY = ['Version' => '2012-10-17', 'Statement' => []];

    /**
     * @param array<string, mixed> $role
     */
    private static function existing(array $role = []): Result
    {
        return new Result(['Role' => ['Arn' => 'arn:x', 'Tags' => [['Key' => 'arco:region', 'Value' => 'eu-west-1']], ...$role]]);
    }

    /** @return array{0: list<array{0: string, 1: array<string, string|int>}>, 1: \Closure(string, array<string, string|int>): void} */
    private static function recorder(): array
    {
        $steps = new \ArrayObject();
        return [$steps, function (string $key, array $params) use ($steps): void { $steps[] = [$key, $params]; }];
    }

    public function test_a_new_role_is_created_with_the_boundary_before_its_policy_is_written(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(AwsMocks::error(IamException::class, 'GetRole', 'NoSuchEntity', 404), new Result(['Role' => ['Arn' => 'arn:x']]), new Result([]));
        [$steps, $record] = self::recorder();

        $role = (new ExecutionRole($mocks->clients()->iam()))->ensure('r', self::POLICY, [], 'eu-west-1', $record, self::BOUNDARY);

        self::assertTrue($role['created']);
        self::assertSame(['GetRole', 'CreateRole', 'PutRolePolicy'], $mocks->calls, 'the boundary rides on CreateRole: no PutRolePermissionsBoundary');
        self::assertSame(self::BOUNDARY, $mocks->params[1]['PermissionsBoundary']);
        self::assertSame([], $steps->getArrayCopy());
    }

    public function test_an_existing_role_without_a_boundary_gets_it_before_its_policy_is_written(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(self::existing(), new Result([]), new Result([]));
        [$steps, $record] = self::recorder();

        (new ExecutionRole($mocks->clients()->iam()))->ensure('r', self::POLICY, [], 'eu-west-1', $record, self::BOUNDARY);

        self::assertSame(['GetRole', 'PutRolePermissionsBoundary', 'PutRolePolicy'], $mocks->calls, 'an account guard conditioned on PutRolePolicy is satisfied once the boundary is on');
        self::assertSame(['RoleName' => 'r', 'PermissionsBoundary' => self::BOUNDARY], array_diff_key($mocks->params[1], ['@http' => 1, '@context' => 1]));
        self::assertSame([['deploy.boundary_set', ['role' => 'r', 'boundary' => self::BOUNDARY]]], $steps->getArrayCopy());
    }

    public function test_an_existing_role_with_another_boundary_gets_the_configured_one(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(self::existing(['PermissionsBoundary' => ['PermissionsBoundaryType' => 'Policy', 'PermissionsBoundaryArn' => 'arn:aws:iam::123456789012:policy/old']]), new Result([]), new Result([]));
        [$steps, $record] = self::recorder();

        (new ExecutionRole($mocks->clients()->iam()))->ensure('r', self::POLICY, [], 'eu-west-1', $record, self::BOUNDARY);

        self::assertSame(['GetRole', 'PutRolePermissionsBoundary', 'PutRolePolicy'], $mocks->calls);
        self::assertSame(self::BOUNDARY, $mocks->params[1]['PermissionsBoundary']);
        self::assertSame([['deploy.boundary_set', ['role' => 'r', 'boundary' => self::BOUNDARY]]], $steps->getArrayCopy());
    }

    public function test_an_existing_role_with_the_same_boundary_needs_no_extra_call(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(self::existing(['PermissionsBoundary' => ['PermissionsBoundaryType' => 'Policy', 'PermissionsBoundaryArn' => self::BOUNDARY]]), new Result([]));
        [$steps, $record] = self::recorder();

        (new ExecutionRole($mocks->clients()->iam()))->ensure('r', self::POLICY, [], 'eu-west-1', $record, self::BOUNDARY);

        self::assertSame(['GetRole', 'PutRolePolicy'], $mocks->calls);
        self::assertSame([], $steps->getArrayCopy());
    }

    public function test_a_boundary_dropped_from_the_config_is_kept_on_the_role_with_a_note(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(self::existing(['PermissionsBoundary' => ['PermissionsBoundaryType' => 'Policy', 'PermissionsBoundaryArn' => self::BOUNDARY]]), new Result([]));
        [$steps, $record] = self::recorder();

        (new ExecutionRole($mocks->clients()->iam()))->ensure('r', self::POLICY, [], 'eu-west-1', $record);

        self::assertSame(['GetRole', 'PutRolePolicy'], $mocks->calls, 'never DeleteRolePermissionsBoundary: removing it is the account owner\'s call');
        self::assertSame([['deploy.boundary_kept', ['role' => 'r', 'boundary' => self::BOUNDARY]]], $steps->getArrayCopy());
        self::assertNotContains('deploy.boundary_kept', \Multiapi\Arco\Cli\StepPrinter::WARNINGS, 'a note (bullet), not a warning block');
        self::assertNotContains('deploy.boundary_set', \Multiapi\Arco\Cli\StepPrinter::WARNINGS, 'a step (bullet), not a warning block');
    }

    /** @return iterable<string, array{list<mixed>, string, list<string>}> */
    public static function boundaryDenials(): iterable
    {
        foreach (['AccessDenied', 'AccessDeniedException'] as $code) {
            yield "CreateRole $code" => [
                [AwsMocks::error(IamException::class, 'GetRole', 'NoSuchEntity', 404), AwsMocks::error(IamException::class, 'CreateRole', $code, 403)],
                'CreateRole', ['GetRole', 'CreateRole'],
            ];
            yield "PutRolePermissionsBoundary $code" => [
                [self::existing(), AwsMocks::error(IamException::class, 'PutRolePermissionsBoundary', $code, 403)],
                'PutRolePermissionsBoundary', ['GetRole', 'PutRolePermissionsBoundary'],
            ];
            yield "PutRolePolicy $code" => [
                [self::existing(['PermissionsBoundary' => ['PermissionsBoundaryArn' => self::BOUNDARY]]), AwsMocks::error(IamException::class, 'PutRolePolicy', $code, 403)],
                'PutRolePolicy', ['GetRole', 'PutRolePolicy'],
            ];
        }
    }

    /**
     * @param list<mixed> $responses
     * @param list<string> $calls
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('boundaryDenials')]
    public function test_a_denial_while_a_boundary_is_configured_names_the_boundary(array $responses, string $operation, array $calls): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(...$responses);

        try {
            (new ExecutionRole($mocks->clients()->iam()))->ensure('r', self::POLICY, [], 'eu-west-1', null, self::BOUNDARY);
            self::fail('expected deploy.boundary_denied');
        } catch (DeployException $e) {
            self::assertSame('deploy.boundary_denied', $e->key);
            self::assertSame(['role' => 'r', 'boundary' => self::BOUNDARY], $e->params);
            self::assertInstanceOf(IamException::class, $e->getPrevious());
        }
        self::assertSame($calls, $mocks->calls, "$operation is the last call");
    }

    public function test_a_denial_without_a_boundary_is_the_aws_error_as_before(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(self::existing(), AwsMocks::error(IamException::class, 'PutRolePolicy', 'AccessDenied', 403));

        $this->expectException(IamException::class);
        (new ExecutionRole($mocks->clients()->iam()))->ensure('r', self::POLICY, [], 'eu-west-1');
    }

    public function test_another_error_with_a_boundary_is_not_reported_as_a_denial(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(self::existing(), AwsMocks::error(IamException::class, 'PutRolePermissionsBoundary', 'NoSuchEntity', 404));

        $this->expectException(IamException::class);
        (new ExecutionRole($mocks->clients()->iam()))->ensure('r', self::POLICY, [], 'eu-west-1', null, self::BOUNDARY);
    }

    public function test_the_boundary_messages_read_in_both_languages(): void
    {
        foreach (['en', 'es'] as $lang) {
            $translator = new \Multiapi\Arco\Cli\Translator($lang, \Multiapi\Arco\Arco::packageRoot() . '/lang');
            foreach (['deploy.boundary_set', 'deploy.boundary_kept', 'deploy.boundary_denied'] as $key) {
                $text = $translator->trans($key, ['role' => 'arco-shop-production', 'boundary' => self::BOUNDARY]);
                self::assertNotSame($key, $text, "$lang: $key");
                self::assertStringContainsString('arco-shop-production', $text, "$lang: $key");
                self::assertStringContainsString(self::BOUNDARY, $text, "$lang: $key");
            }
            self::assertStringContainsString('iam:PermissionsBoundary', $translator->trans('deploy.boundary_denied', ['role' => 'r', 'boundary' => self::BOUNDARY]), "$lang names the condition key");
        }
    }
}
