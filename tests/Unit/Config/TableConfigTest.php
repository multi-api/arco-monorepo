<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Config;

use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Config\TableConfig;
use PHPUnit\Framework\TestCase;

final class TableConfigTest extends TestCase
{
    private function config(): AppConfig
    {
        $raw = App::make('shop')->region('us-east-1')->environment('production', fn ($e) => $e->account('123456789012'))->toArray();
        return ConfigResolver::resolve($raw, 'production');
    }

    public function test_table_names_and_arns(): void
    {
        $c = $this->config();

        self::assertSame('shop-production-cache', (new TableConfig('cache'))->tableName($c));
        self::assertSame('shop-production-failed-jobs', (new TableConfig('failed-jobs'))->tableName($c));
        self::assertSame('arn:aws:dynamodb:us-east-1:123456789012:table/shop-production-cache', (new TableConfig('cache'))->arn($c));
    }

    public function test_existing_table_is_used_as_is(): void
    {
        $t = new TableConfig('cache', existing: 'Legacy_Cache');
        $c = $this->config();

        self::assertSame('Legacy_Cache', $t->tableName($c));
        self::assertSame('arn:aws:dynamodb:us-east-1:123456789012:table/Legacy_Cache', $t->arn($c));
    }

    public function test_table_name_rules(): void
    {
        foreach (['abc', 'My_Table.v-2', str_repeat('a', 255)] as $name) {
            self::assertTrue(TableConfig::isTableName($name), $name);
        }
        foreach (['ab', str_repeat('a', 256), 'a b', 'a/b', 'arn:aws:dynamodb:us-east-1:123456789012:table/x'] as $name) {
            self::assertFalse(TableConfig::isTableName($name), $name);
        }
    }
}
