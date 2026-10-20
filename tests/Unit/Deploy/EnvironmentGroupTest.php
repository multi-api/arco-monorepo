<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy;

use Aws\ResourceGroups\Exception\ResourceGroupsException;
use Aws\Result;
use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Deploy\DeployException;
use Multiapi\Arco\Deploy\EnvironmentGroup;
use PHPUnit\Framework\TestCase;

final class EnvironmentGroupTest extends TestCase
{
    private const QUERY = '{"ResourceTypeFilters":["AWS::AllSupported"],"TagFilters":[{"Key":"arco:app","Values":["shop"]},{"Key":"arco:environment","Values":["production"]}]}';

    private function config(): AppConfig
    {
        return ConfigResolver::resolve(App::make('shop')->region('us-east-1')->environment('production')->toArray(), 'production');
    }

    private static function notFound(string $operation = 'GetGroupQuery'): ResourceGroupsException
    {
        return AwsMocks::error(ResourceGroupsException::class, $operation, 'NotFoundException', 404);
    }

    private static function query(string $query, string $type = 'TAG_FILTERS_1_0'): Result
    {
        return new Result(['GroupQuery' => ['GroupName' => 'arco-shop-production', 'ResourceQuery' => ['Type' => $type, 'Query' => $query]]]);
    }

    public function test_the_group_is_named_after_the_environment_and_queries_both_tags(): void
    {
        self::assertSame('arco-shop-production', EnvironmentGroup::name($this->config()));
        self::assertSame(json_decode(self::QUERY, true), json_decode(EnvironmentGroup::query($this->config()), true));
    }

    public function test_ensure_creates_a_missing_group_tagged_for_the_environment(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(self::notFound(), new Result(['Group' => ['GroupArn' => 'arn:aws:resource-groups:us-east-1:111111111111:group/arco-shop-production']]));

        self::assertTrue((new EnvironmentGroup($mocks->clients()->resourceGroups()))->ensure($this->config()), 'created');

        self::assertSame(['GetGroupQuery', 'CreateGroup'], $mocks->calls);
        self::assertSame('arco-shop-production', $mocks->params[0]['Group']);
        self::assertSame('arco-shop-production', $mocks->params[1]['Name']);
        self::assertSame('TAG_FILTERS_1_0', $mocks->params[1]['ResourceQuery']['Type']);
        self::assertSame(json_decode(self::QUERY, true), json_decode($mocks->params[1]['ResourceQuery']['Query'], true));
        self::assertSame(['arco:app' => 'shop', 'arco:environment' => 'production'], $mocks->params[1]['Tags']);
    }

    public function test_ensure_leaves_a_group_with_the_same_query_alone(): void
    {
        // Same filters in another order and spacing: no update.
        $mocks = new AwsMocks();
        $mocks->handler->append(self::query('{"TagFilters": [{"Key": "arco:environment", "Values": ["production"]}, {"Key": "arco:app", "Values": ["shop"]}], "ResourceTypeFilters": ["AWS::AllSupported"]}'));

        self::assertFalse((new EnvironmentGroup($mocks->clients()->resourceGroups()))->ensure($this->config()), 'unchanged');

        self::assertSame(['GetGroupQuery'], $mocks->calls);
    }

    public function test_ensure_updates_a_changed_query_of_the_environments_own_group(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::query('{"ResourceTypeFilters":["AWS::Lambda::Function"],"TagFilters":[{"Key":"arco:app","Values":["shop"]}]}'),
            new Result(['Group' => ['GroupArn' => 'arn:aws:resource-groups:us-east-1:111111111111:group/arco-shop-production', 'Name' => 'arco-shop-production']]),
            new Result(['Arn' => 'arn:aws:resource-groups:us-east-1:111111111111:group/arco-shop-production', 'Tags' => ['arco:app' => 'shop', 'arco:environment' => 'production']]),
            new Result([]),
        );

        self::assertTrue((new EnvironmentGroup($mocks->clients()->resourceGroups()))->ensure($this->config()), 'updated');

        self::assertSame(['GetGroupQuery', 'GetGroup', 'GetTags', 'UpdateGroupQuery'], $mocks->calls);
        self::assertSame('arn:aws:resource-groups:us-east-1:111111111111:group/arco-shop-production', $mocks->params[2]['Arn']);
        self::assertSame(json_decode(self::QUERY, true), json_decode($mocks->params[3]['ResourceQuery']['Query'], true));
    }

    public function test_ensure_never_changes_a_group_with_the_name_someone_else_made(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::query('{"ResourceTypeFilters":["AWS::AllSupported"],"TagFilters":[{"Key":"team","Values":["shop"]}]}'),
            new Result(['Group' => ['GroupArn' => 'arn:aws:resource-groups:us-east-1:111111111111:group/arco-shop-production']]),
            new Result(['Tags' => ['team' => 'shop']]),
        );

        try {
            (new EnvironmentGroup($mocks->clients()->resourceGroups()))->ensure($this->config());
            self::fail('expected deploy.group_not_ours');
        } catch (DeployException $e) {
            self::assertSame('deploy.group_not_ours', $e->key);
            self::assertSame(['group' => 'arco-shop-production'], $e->params);
        }
        self::assertNotContains('UpdateGroupQuery', $mocks->calls);
    }

    public function test_a_refused_lookup_propagates_for_the_caller_to_note(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(AwsMocks::error(ResourceGroupsException::class, 'GetGroupQuery', 'ForbiddenException', 403));

        $this->expectException(ResourceGroupsException::class);
        (new EnvironmentGroup($mocks->clients()->resourceGroups()))->ensure($this->config());
    }

    public function test_delete_removes_the_environments_group(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(self::query(self::QUERY), new Result([]));

        self::assertTrue((new EnvironmentGroup($mocks->clients()->resourceGroups()))->delete($this->config()));

        self::assertSame(['GetGroupQuery', 'DeleteGroup'], $mocks->calls);
        self::assertSame('arco-shop-production', $mocks->params[1]['Group']);
    }

    public function test_delete_without_a_group_does_nothing(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(self::notFound());

        self::assertFalse((new EnvironmentGroup($mocks->clients()->resourceGroups()))->delete($this->config()));
        self::assertSame(['GetGroupQuery'], $mocks->calls);
    }

    public function test_delete_never_deletes_a_group_whose_query_is_not_the_environments(): void
    {
        foreach ([
            self::query('{"ResourceTypeFilters":["AWS::AllSupported"],"TagFilters":[{"Key":"arco:app","Values":["shop"]},{"Key":"arco:environment","Values":["staging"]}]}'),
            self::query(self::QUERY, 'CLOUDFORMATION_STACK_1_0'),
        ] as $foreign) {
            $mocks = new AwsMocks();
            $mocks->handler->append($foreign);
            try {
                (new EnvironmentGroup($mocks->clients()->resourceGroups()))->delete($this->config());
                self::fail('expected remove.group_not_ours');
            } catch (DeployException $e) {
                self::assertSame('remove.group_not_ours', $e->key);
            }
            self::assertSame(['GetGroupQuery'], $mocks->calls);
        }
    }
}
