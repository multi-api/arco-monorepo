<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Config;

use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Config\Environment;
use Multiapi\Arco\Config\GenericTableConfig;
use Multiapi\Arco\Config\Validator;
use Multiapi\Arco\Deploy\DeployPipeline;
use Multiapi\Arco\Deploy\PolicyBuilder;
use Multiapi\Arco\Deploy\ResourcesStack;
use PHPUnit\Framework\TestCase;

/** The generic single-table DynamoDB resource: ->table('<name>', ttl:, existing:). */
final class GenericTableTest extends TestCase
{
    private static function resolve(App $app, string $environment = 'production'): AppConfig
    {
        return ConfigResolver::resolve($app->toArray(), $environment);
    }

    private static function app(): App
    {
        return App::make('shop')->region('us-east-1')->environment('production', fn (Environment $e) => $e->account('111111111111'))->environment('staging');
    }

    /** @return list<array{key: string, params: array<string, string>}> */
    private static function errors(App $app, string $environment = 'production'): array
    {
        $result = (new Validator())->validate(self::resolve($app, $environment), sys_get_temp_dir());
        return array_values(array_filter($result->errors, fn (array $e): bool => str_starts_with($e['key'], 'validation.generic_table')));
    }

    public function test_table_resolves_with_its_physical_name_ttl_and_arn(): void
    {
        $config = self::resolve(self::app()->table('state', ttl: 'expires_at'));

        self::assertCount(1, $config->tables);
        $table = $config->tables[0];
        self::assertSame('state', $table->name);
        self::assertSame('expires_at', $table->ttl);
        self::assertNull($table->existing);
        self::assertSame('shop-production-state', $table->tableName($config));
        self::assertSame('arn:aws:dynamodb:us-east-1:111111111111:table/shop-production-state', $table->arn($config));
    }

    public function test_app_and_environment_entries_merge_per_name_and_false_drops_one(): void
    {
        $app = App::make('shop')->region('us-east-1')
            ->table('state', ttl: 'expires_at')
            ->table('events')
            ->environment('production', fn (Environment $e) => $e->table('state', existing: 'legacy-state')->table('events', false)->table('audit'))
            ->environment('staging');

        $production = self::resolve($app);
        self::assertSame(['state', 'audit'], array_map(fn (GenericTableConfig $t) => $t->name, $production->tables));
        self::assertSame('expires_at', $production->tables[0]->ttl, 'the app-level ttl is kept');
        self::assertSame('legacy-state', $production->tables[0]->existing);

        $staging = self::resolve($app, 'staging');
        self::assertSame(['state', 'events'], array_map(fn (GenericTableConfig $t) => $t->name, $staging->tables));
    }

    public function test_repeated_calls_accumulate_options(): void
    {
        $config = self::resolve(self::app()->table('state')->table('state', ttl: 'ttl'));
        self::assertSame('ttl', $config->tables[0]->ttl);
    }

    public function test_to_array_leaves_tables_out_when_there_are_none(): void
    {
        self::assertArrayNotHasKey('tables', App::make('shop')->toArray());
        self::assertArrayNotHasKey('tables', (new Environment())->toArray());
        self::assertSame(['state' => ['ttl' => 'expires_at']], App::make('shop')->table('state', ttl: 'expires_at')->toArray()['tables']);
        self::assertSame(['state' => false], (new Environment())->table('state', false)->toArray()['tables']);
    }

    public function test_existing_name_or_arn_is_used_as_is(): void
    {
        $config = self::resolve(self::app()->table('state', existing: 'legacy-state')->table('shared', existing: 'arn:aws:dynamodb:eu-west-1:222222222222:table/Shared'));

        self::assertSame('legacy-state', $config->tables[0]->tableName($config));
        self::assertSame('arn:aws:dynamodb:us-east-1:111111111111:table/legacy-state', $config->tables[0]->arn($config));
        self::assertSame('arn:aws:dynamodb:eu-west-1:222222222222:table/Shared', $config->tables[1]->tableName($config));
        self::assertSame('arn:aws:dynamodb:eu-west-1:222222222222:table/Shared', $config->tables[1]->arn($config));
        self::assertSame([], self::errors(self::app()->table('state', existing: 'legacy-state')->table('shared', existing: 'arn:aws:dynamodb:eu-west-1:222222222222:table/Shared')));
    }

    public function test_a_valid_table_has_no_errors(): void
    {
        self::assertSame([], self::errors(self::app()->table('state', ttl: 'expires_at')));
    }

    public function test_name_rule(): void
    {
        self::assertSame([['key' => 'validation.generic_table_name', 'params' => ['name' => 'State_1']]], self::errors(self::app()->table('State_1')));
    }

    public function test_cache_and_failed_jobs_are_reserved(): void
    {
        self::assertSame([['key' => 'validation.generic_table_reserved', 'params' => ['name' => 'cache']]], self::errors(self::app()->table('cache')));
        self::assertSame([['key' => 'validation.generic_table_reserved', 'params' => ['name' => 'failed-jobs']]], self::errors(self::app()->table('failed-jobs')));
    }

    public function test_a_physical_name_equal_to_the_cache_or_failed_jobs_table_is_rejected(): void
    {
        // The cache table is an existing table named like the generic table Arco would create.
        self::assertSame(
            [['key' => 'validation.generic_table_clash', 'params' => ['table' => 'state', 'name' => 'shop-production-state', 'other' => 'cache()']]],
            self::errors(self::app()->cache(existing: 'shop-production-state')->table('state')),
        );
        // A table referencing the failed-jobs table Arco creates.
        self::assertSame(
            [['key' => 'validation.generic_table_clash', 'params' => ['table' => 'jobs', 'name' => 'shop-production-failed-jobs', 'other' => 'failedJobsTable()']]],
            self::errors(self::app()->failedJobsTable()->table('jobs', existing: 'shop-production-failed-jobs')),
        );
        // Through an ARN as well.
        self::assertSame(
            [['key' => 'validation.generic_table_clash', 'params' => ['table' => 'c', 'name' => 'shop-production-cache', 'other' => 'cache()']]],
            self::errors(self::app()->cache()->table('c', existing: 'arn:aws:dynamodb:us-east-1:111111111111:table/shop-production-cache')),
        );
        // Two generic tables on one DynamoDB table.
        self::assertSame(
            [['key' => 'validation.generic_table_clash', 'params' => ['table' => 'other', 'name' => 'shop-production-state', 'other' => "table('state')"]]],
            self::errors(self::app()->table('state')->table('other', existing: 'shop-production-state')),
        );
        // The same name in another region or account is another table.
        self::assertSame([], self::errors(self::app()->cache()->table('c', existing: 'arn:aws:dynamodb:eu-west-1:111111111111:table/shop-production-cache')));
    }

    public function test_physical_name_length_is_within_dynamodbs_255(): void
    {
        $name = 'a' . str_repeat('b', 240);
        self::assertSame(
            [['key' => 'validation.generic_table_name_length', 'params' => ['table' => $name, 'name' => "shop-production-$name"]]],
            self::errors(self::app()->table($name)),
        );
        self::assertSame([], self::errors(self::app()->table('a' . str_repeat('b', 254 - strlen('shop-production-')))));
    }

    public function test_existing_must_be_a_table_name_or_arn(): void
    {
        self::assertSame(
            [['key' => 'validation.generic_table_existing', 'params' => ['table' => 'state', 'value' => 'no spaces']]],
            self::errors(self::app()->table('state', existing: 'no spaces')),
        );
        self::assertSame(
            [['key' => 'validation.generic_table_existing', 'params' => ['table' => 'state', 'value' => 'arn:aws:s3:::bucket']]],
            self::errors(self::app()->table('state', existing: 'arn:aws:s3:::bucket')),
        );
    }

    public function test_ttl_must_be_an_attribute_name(): void
    {
        self::assertSame(
            [['key' => 'validation.generic_table_ttl', 'params' => ['table' => 'state', 'value' => '']]],
            self::errors(self::app()->table('state', ttl: '')),
        );
        $long = str_repeat('t', 256);
        self::assertSame(
            [['key' => 'validation.generic_table_ttl', 'params' => ['table' => 'state', 'value' => $long]]],
            self::errors(self::app()->table('state', ttl: $long)),
        );
    }

    public function test_ttl_on_an_existing_table_is_a_warning(): void
    {
        $result = (new Validator())->validate(self::resolve(self::app()->table('state', ttl: 'expires_at', existing: 'legacy')), sys_get_temp_dir());
        self::assertSame([], array_filter($result->errors, fn (array $e): bool => str_starts_with($e['key'], 'validation.generic_table')));
        self::assertContains(['key' => 'validation.generic_table_existing_ttl', 'params' => ['table' => 'state', 'existing' => 'legacy']], $result->warnings);
    }

    public function test_the_stack_creates_the_table_with_pk_sk_ttl_tags_and_retention(): void
    {
        $config = self::resolve(self::app()->table('state', ttl: 'expires_at')->table('events')->table('legacy', existing: 'legacy'));
        $template = ResourcesStack::template($config);

        self::assertSame(['AppTableState', 'AppTableEvents'], array_keys($template['Resources']));
        self::assertSame([
            'Type' => 'AWS::DynamoDB::Table',
            'DeletionPolicy' => 'RetainExceptOnCreate',
            'UpdateReplacePolicy' => 'Retain',
            'Properties' => [
                'TableName' => 'shop-production-state',
                'BillingMode' => 'PAY_PER_REQUEST',
                'AttributeDefinitions' => [['AttributeName' => 'PK', 'AttributeType' => 'S'], ['AttributeName' => 'SK', 'AttributeType' => 'S']],
                'KeySchema' => [['AttributeName' => 'PK', 'KeyType' => 'HASH'], ['AttributeName' => 'SK', 'KeyType' => 'RANGE']],
                'TimeToLiveSpecification' => ['AttributeName' => 'expires_at', 'Enabled' => true],
                'Tags' => [
                    ['Key' => 'arco:app', 'Value' => 'shop'],
                    ['Key' => 'arco:environment', 'Value' => 'production'],
                    ['Key' => 'arco:resource', 'Value' => 'table:state'],
                ],
            ],
        ], $template['Resources']['AppTableState']);
        self::assertArrayNotHasKey('TimeToLiveSpecification', $template['Resources']['AppTableEvents']['Properties'], 'no ttl, no TTL');
        self::assertSame(['Value' => ['Ref' => 'AppTableState']], $template['Outputs']['AppTableStateName']);
        self::assertSame(['Value' => ['Fn::GetAtt' => ['AppTableState', 'Arn']]], $template['Outputs']['AppTableStateArn']);
        self::assertSame('AppTableAuditXlog', ResourcesStack::genericTableLogicalId('audit-log'));
    }

    public function test_generic_tables_come_after_arcos_own_tables_which_are_unchanged(): void
    {
        $without = ResourcesStack::template(self::resolve(self::app()->cache()->failedJobsTable()));
        $with = ResourcesStack::template(self::resolve(self::app()->cache()->failedJobsTable()->table('state')));

        self::assertSame(['TableCache', 'TableFailedXjobs', 'AppTableState'], array_keys($with['Resources']));
        self::assertSame($without['Resources']['TableCache'], $with['Resources']['TableCache']);
        self::assertSame($without['Resources']['TableFailedXjobs'], $with['Resources']['TableFailedXjobs']);
    }

    public function test_arco_tables_maps_each_name_to_its_table(): void
    {
        $config = self::resolve(self::app()->table('state', ttl: 'expires_at')->table('shared', existing: 'arn:aws:dynamodb:eu-west-1:222222222222:table/Shared'));

        self::assertSame(
            '{"state":"shop-production-state","shared":"arn:aws:dynamodb:eu-west-1:222222222222:table/Shared"}',
            DeployPipeline::injectedVariables($config)['ARCO_TABLES'],
        );
        self::assertArrayNotHasKey('ARCO_TABLES', DeployPipeline::injectedVariables(self::resolve(self::app()->cache())), 'only with a table()');
        self::assertSame(['ARCO_TABLES' => '{"state":"shop-production-state"}'], DeployPipeline::genericTableVariables(self::resolve(self::app()->table('state'))));
    }

    public function test_arco_tables_cannot_be_set_by_the_user(): void
    {
        $result = (new Validator())->validate(self::resolve(self::app()->variables(['ARCO_TABLES' => '{}'])), sys_get_temp_dir());
        self::assertContains('validation.reserved_arco_variable', array_column($result->errors, 'key'));
    }

    public function test_the_role_gets_exactly_the_item_actions_on_each_table_and_its_indexes(): void
    {
        $arns = ['arn:aws:dynamodb:us-east-1:111111111111:table/shop-production-state', 'arn:aws:dynamodb:eu-west-1:222222222222:table/Shared'];
        $doc = PolicyBuilder::document('us-east-1', '111111111111', 'shop-production', [], [], null, [], [], [], [], $arns);

        $bySid = array_column($doc['Statement'], null, 'Sid');
        self::assertSame([
            'Sid' => 'ArcoAppTables',
            'Effect' => 'Allow',
            'Action' => [
                'dynamodb:GetItem', 'dynamodb:PutItem', 'dynamodb:UpdateItem', 'dynamodb:DeleteItem', 'dynamodb:Query', 'dynamodb:Scan',
                'dynamodb:BatchGetItem', 'dynamodb:BatchWriteItem', 'dynamodb:ConditionCheckItem', 'dynamodb:DescribeTable',
            ],
            'Resource' => [
                'arn:aws:dynamodb:us-east-1:111111111111:table/shop-production-state',
                'arn:aws:dynamodb:us-east-1:111111111111:table/shop-production-state/index/*',
                'arn:aws:dynamodb:eu-west-1:222222222222:table/Shared',
                'arn:aws:dynamodb:eu-west-1:222222222222:table/Shared/index/*',
            ],
        ], $bySid['ArcoAppTables']);
        self::assertArrayNotHasKey('ArcoAppTables', array_column(PolicyBuilder::document('us-east-1', '111111111111', 'shop-production', [])['Statement'], null, 'Sid'));
    }

    public function test_the_clash_check_does_not_need_a_locked_account(): void
    {
        // No ->account(): validation runs before the credentials resolve it.
        $app = fn () => App::make('shop')->region('us-east-1')->environment('production');
        self::assertSame(
            [['key' => 'validation.generic_table_clash', 'params' => ['table' => 'c', 'name' => 'shop-production-cache', 'other' => 'cache()']]],
            self::errors($app()->cache()->table('c', existing: 'arn:aws:dynamodb:us-east-1:111111111111:table/shop-production-cache')),
        );
        self::assertSame(
            [['key' => 'validation.generic_table_clash', 'params' => ['table' => 'j', 'name' => 'shop-production-failed-jobs', 'other' => 'failedJobsTable()']]],
            self::errors($app()->failedJobsTable()->table('j', existing: 'arn:aws:dynamodb:us-east-1:111111111111:table/shop-production-failed-jobs')),
        );
        self::assertSame(
            [['key' => 'validation.generic_table_clash', 'params' => ['table' => 'other', 'name' => 'shop-production-state', 'other' => "table('state')"]]],
            self::errors($app()->table('state')->table('other', existing: 'arn:aws:dynamodb:us-east-1:111111111111:table/shop-production-state')),
        );
        // Another region is another table, account or not.
        self::assertSame([], self::errors($app()->cache()->table('c', existing: 'arn:aws:dynamodb:eu-west-1:111111111111:table/shop-production-cache')));
        // With the account locked, an ARN of another account is another table.
        self::assertSame([], self::errors(self::app()->cache()->table('c', existing: 'arn:aws:dynamodb:us-east-1:222222222222:table/shop-production-cache')));
    }

    public function test_same_table_treats_an_empty_account_as_any(): void
    {
        self::assertTrue(GenericTableConfig::sameTable('arn:aws:dynamodb:us-east-1::table/t1', 'arn:aws:dynamodb:us-east-1:111111111111:table/t1'));
        self::assertTrue(GenericTableConfig::sameTable('arn:aws:dynamodb:us-east-1:111111111111:table/t1', 'arn:aws:dynamodb:us-east-1:111111111111:table/t1'));
        self::assertFalse(GenericTableConfig::sameTable('arn:aws:dynamodb:us-east-1:222222222222:table/t1', 'arn:aws:dynamodb:us-east-1:111111111111:table/t1'));
        self::assertFalse(GenericTableConfig::sameTable('arn:aws:dynamodb:us-east-1::table/t1', 'arn:aws:dynamodb:us-east-1::table/t2'));
        self::assertFalse(GenericTableConfig::sameTable('arn:aws:dynamodb:eu-west-1::table/t1', 'arn:aws:dynamodb:us-east-1::table/t1'));
        $config = self::resolve(self::app());
        self::assertSame('arn:aws:dynamodb:us-east-1:111111111111:table/t1', GenericTableConfig::arnOf('t1', $config));
        self::assertSame('arn:aws:dynamodb:eu-west-1:222222222222:table/Shared', GenericTableConfig::arnOf('arn:aws:dynamodb:eu-west-1:222222222222:table/Shared', $config));
    }
}
