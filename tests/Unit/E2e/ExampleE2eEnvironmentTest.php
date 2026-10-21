<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\E2e;

use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ConfigLoader;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Tests\E2e\AwsE2eTest;
use PHPUnit\Framework\TestCase;

/** examples/laravel/arco.php's `e2e` environment as the real-AWS run loads it, and the dry run's listing of it. */
final class ExampleE2eEnvironmentTest extends TestCase
{
    private const ARCO_PHP = __DIR__ . '/../../../examples/laravel/arco.php';

    protected function tearDown(): void
    {
        AwsE2eTest::setEnv('ARCO_E2E_APP', null);
        AwsE2eTest::setEnv('ARCO_E2E_ACCOUNT', null);
    }

    private static function load(): AppConfig
    {
        return ConfigResolver::resolve((new ConfigLoader())->load(self::ARCO_PHP), 'e2e');
    }

    public function test_the_run_app_name_and_account_come_from_the_environment(): void
    {
        AwsE2eTest::setEnv('ARCO_E2E_APP', 'arco-e2e-0123abcd');
        AwsE2eTest::setEnv('ARCO_E2E_ACCOUNT', '111122223333');

        $config = self::load();

        self::assertSame('arco-e2e-0123abcd', $config->app);
        self::assertSame('111122223333', $config->account);
        self::assertSame('arco-ssm:/arco-e2e-0123abcd/e2e/app-key', $config->variables['APP_KEY']);
        self::assertSame([], $config->domains);
        self::assertCount(1, $config->tables);
        self::assertSame('state', $config->tables[0]->name);
        self::assertSame('expires_at', $config->tables[0]->ttl);
        self::assertSame('arco-e2e-0123abcd-e2e-state', $config->tables[0]->tableName($config));
    }

    public function test_defaults_without_a_run(): void
    {
        $config = self::load();

        self::assertSame('arco-example', $config->app);
        self::assertNull($config->account);
        self::assertSame('arco-ssm:/arco-example/e2e/app-key', $config->variables['APP_KEY']);
    }

    public function test_dry_run_lists_what_the_run_creates_and_the_cleanup_deletes(): void
    {
        AwsE2eTest::setEnv('ARCO_E2E_APP', 'arco-e2e-0123abcd');
        AwsE2eTest::setEnv('ARCO_E2E_ACCOUNT', '111122223333');

        $create = AwsE2eTest::resourcesToCreate(self::load());

        foreach ([
            'ssm-parameter /arco-e2e-0123abcd/e2e/app-key (created by the run)',
            'sqs-queue arco-e2e-0123abcd-e2e-default',
            'sqs-queue arco-e2e-0123abcd-e2e-default-dlq',
            's3-bucket arco-e2e-0123abcd-e2e-files-111122223333',
            'dynamodb-table arco-e2e-0123abcd-e2e-cache',
            'dynamodb-table arco-e2e-0123abcd-e2e-state',
        ] as $resource) {
            self::assertContains($resource, $create);
        }
        // Every retained resource carries the prefix the cleanup deletes under.
        foreach ($create as $line) {
            if (preg_match('/^(sqs-queue|s3-bucket|dynamodb-table) /', $line) === 1) {
                self::assertStringStartsWith('arco-e2e-0123abcd-e2e-', explode(' ', $line)[1]);
            }
        }
    }
}
