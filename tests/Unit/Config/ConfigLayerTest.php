<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Config;

use Multiapi\Arco\Config\ConfigException;
use Multiapi\Arco\Config\ConfigLayer;
use Multiapi\Arco\Tests\Support\TempPaths;
use PHPUnit\Framework\TestCase;

final class ConfigLayerTest extends TestCase
{
    /** @param array<string, mixed>|string $document */
    private function write(array|string $document): string
    {
        $path = TempPaths::path('arco-layer-', '.json');
        file_put_contents($path, is_string($document) ? $document : json_encode($document, JSON_THROW_ON_ERROR));
        return $path;
    }

    /**
     * @param array<string, mixed> $environment
     * @param array<string, mixed> $top
     * @return array<string, mixed>
     */
    private function layer(array $environment = [], array $top = []): array
    {
        return ['schema' => 1, ...$top, 'environments' => ['production' => $environment]];
    }

    /** @param array<string, mixed>|string $document */
    private function invalid(array|string $document, string $path): void
    {
        try {
            ConfigLayer::load($this->write($document));
            self::fail('the layer should be refused');
        } catch (ConfigException $e) {
            self::assertSame('config.layer_invalid', $e->key);
            self::assertSame($path, $e->params['path']);
            self::assertNotSame('', $e->params['reason']);
        }
    }

    public function test_loads_the_documented_example(): void
    {
        $path = $this->write([
            'schema' => 1,
            'name' => 'shop',
            'region' => 'us-east-1',
            'environments' => ['production' => [
                'settings' => [
                    'account' => '123456789012', 'cache' => true, 'failedJobsTable' => ['existing' => 'jobs'], 'scheduler' => 1,
                    'console' => true, 'consoleTimeout' => 900, 'dns' => 'cloudflare',
                    'vpc' => ['subnets' => ['subnet-a', 'subnet-b'], 'securityGroups' => ['sg-1'], 'endpoints' => ['s3', 'dynamodb']],
                ],
                'variables' => ['MAIL_FROM_ADDRESS' => 'hi@example.com', 'STRIPE_SECRET' => 'arco-ssm:/arco-cloud/shop/production/STRIPE_SECRET'],
                'permissions' => [['Effect' => 'Allow', 'Action' => 'dsql:DbConnect', 'Resource' => 'arn:aws:dsql:x']],
                'domains' => ['shop.example.com'],
                'queues' => ['emails' => ['timeout' => 60, 'tries' => 3, 'dlq' => true]],
                'storage' => ['uploads' => ['public' => ['avatars/'], 'adopt' => true]],
                'tables' => ['state' => ['ttl' => 'expires_at']],
            ]],
        ]);

        $layer = ConfigLayer::load($path);

        self::assertSame($path, $layer->path);
        self::assertSame('shop', $layer->name);
        self::assertSame('us-east-1', $layer->region);
        self::assertSame('production', $layer->environment);
        self::assertSame(60, $layer->entry['queues']['emails']['timeout']);
        self::assertTrue($layer->entry['storage']['uploads']['adopt']);
    }

    public function test_name_and_region_are_optional(): void
    {
        $layer = ConfigLayer::load($this->write($this->layer()));

        self::assertNull($layer->name);
        self::assertNull($layer->region);
        self::assertSame([], $layer->entry);
    }

    public function test_missing_file(): void
    {
        $path = TempPaths::path('arco-layer-', '.json');

        try {
            ConfigLayer::load($path);
            self::fail('no file');
        } catch (ConfigException $e) {
            self::assertSame('config.layer_not_found', $e->key);
            self::assertSame(['path' => $path], $e->params);
        }
    }

    public function test_invalid_json(): void
    {
        $this->invalid('{"schema": 1,', '');
    }

    public function test_the_document_must_be_an_object(): void
    {
        $this->invalid('[1]', '');
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function badSchemas(): array
    {
        return [
            'two' => [['schema' => 2, 'environments' => ['p' => []]], '2'],
            'string' => [['schema' => '1', 'environments' => ['p' => []]], '"1"'],
            'missing' => [['environments' => ['p' => []]], 'missing'],
        ];
    }

    /** @param array<string, mixed> $document */
    #[\PHPUnit\Framework\Attributes\DataProvider('badSchemas')]
    public function test_schema_must_be_1(array $document, string $shown): void
    {
        try {
            ConfigLayer::load($this->write($document));
            self::fail('bad schema');
        } catch (ConfigException $e) {
            self::assertSame('config.layer_schema', $e->key);
            self::assertSame(['schema' => $shown], $e->params);
        }
    }

    public function test_exactly_one_environment(): void
    {
        $this->invalid(['schema' => 1, 'environments' => []], '/environments');
        $this->invalid(['schema' => 1, 'environments' => ['a' => [], 'b' => []]], '/environments');
        $this->invalid(['schema' => 1], '/environments');
    }

    public function test_environments_must_be_an_object_not_a_list(): void
    {
        // [ {...} ] would otherwise name the environment "0".
        $this->invalid(['schema' => 1, 'environments' => [['settings' => ['timeout' => 30]]]], '/environments');
        $this->invalid('{"schema": 1, "environments": [{}]}', '/environments');
    }

    public function test_unknown_keys_are_refused(): void
    {
        $this->invalid($this->layer([], ['exclude' => []]), '/exclude');
        $this->invalid($this->layer(['runtime' => []]), '/environments/production/runtime');
        $this->invalid($this->layer(['deployCommands' => []]), '/environments/production/deployCommands');
        $this->invalid($this->layer(['queues' => ['emails' => ['concurrency' => 2]]]), '/environments/production/queues/emails/concurrency');
        $this->invalid($this->layer(['settings' => ['foo' => 1]]), '/environments/production/settings/foo');
        $this->invalid($this->layer(['settings' => ['vpc' => ['subnets' => ['s'], 'securityGroups' => ['g'], 'x' => 1]]]), '/environments/production/settings/vpc/x');
    }

    public function test_wrong_types_are_refused(): void
    {
        $this->invalid($this->layer(['queues' => ['emails' => ['timeout' => '60']]]), '/environments/production/queues/emails/timeout');
        $this->invalid($this->layer(['variables' => ['A' => 1]]), '/environments/production/variables/A');
        $this->invalid($this->layer(['domains' => 'a.com']), '/environments/production/domains');
        $this->invalid($this->layer(['queues' => ['emails' => false]]), '/environments/production/queues/emails');
        $this->invalid($this->layer(['settings' => ['timeout' => 'x']]), '/environments/production/settings/timeout');
        $this->invalid($this->layer(['settings' => ['cache' => 'yes']]), '/environments/production/settings/cache');
        $this->invalid($this->layer(['settings' => ['vpc' => ['subnets' => 'a', 'securityGroups' => []]]]), '/environments/production/settings/vpc/subnets');
        $this->invalid($this->layer(['storage' => ['u' => ['expire' => ['a/' => 'x']]]]), '/environments/production/storage/u/expire/a~1');
        $this->invalid($this->layer(['permissions' => ['x']]), '/environments/production/permissions/0');
        $this->invalid($this->layer([], ['name' => 5]), '/name');
        $this->invalid($this->layer(['tables' => ['t' => ['ttl' => 1]]]), '/environments/production/tables/t/ttl');
    }

    public function test_settings_accept_the_false_forms(): void
    {
        $layer = ConfigLayer::load($this->write($this->layer(['settings' => [
            'dns' => false, 'scheduler' => false, 'logRetention' => false, 'permissionsBoundary' => false,
            'vpc' => false, 'cache' => false, 'protected' => true, 'timeout' => 30,
        ]])));

        self::assertFalse($layer->entry['settings']['dns']);
    }

    public function test_vpc_endpoints_may_be_a_map(): void
    {
        $layer = ConfigLayer::load($this->write($this->layer(['settings' => ['vpc' => [
            'subnets' => ['subnet-a'], 'securityGroups' => ['sg-1'], 'endpoints' => ['s3' => 'vpce-1'],
        ]]])));

        self::assertSame(['s3' => 'vpce-1'], $layer->entry['settings']['vpc']['endpoints']);
    }

    public function test_adopt_with_existing_is_refused(): void
    {
        $this->invalid(
            $this->layer(['storage' => ['uploads' => ['existing' => 'bucket', 'adopt' => true]]]),
            '/environments/production/storage/uploads/adopt',
        );
    }

    public function test_option_wins_over_env(): void
    {
        $option = $this->write($this->layer());
        $env = $this->write($this->layer([], ['name' => 'fromenv']));

        $layer = ConfigLayer::fromInput($option, [ConfigLayer::ENV => $env]);
        self::assertNotNull($layer);
        self::assertSame($option, $layer->path);

        $layer = ConfigLayer::fromInput(null, [ConfigLayer::ENV => $env]);
        self::assertNotNull($layer);
        self::assertSame('fromenv', $layer->name);
        self::assertSame('config-layer', ConfigLayer::OPTION);
    }

    public function test_empty_values_mean_no_layer(): void
    {
        self::assertNull(ConfigLayer::fromInput(null, []));
        self::assertNull(ConfigLayer::fromInput('', [ConfigLayer::ENV => '']));
        self::assertNull(ConfigLayer::fromInput(null, [ConfigLayer::ENV => '']));
    }

    public function test_errors_never_contain_variable_values(): void
    {
        $path = $this->write($this->layer(['variables' => ['SECRET' => 's3cr3t', 'N' => 1], 'queues' => ['q' => ['timeout' => 's3cr3t']]]));

        try {
            ConfigLayer::load($path);
            self::fail('refused');
        } catch (ConfigException $e) {
            self::assertStringNotContainsString('s3cr3t', $e->getMessage());
            self::assertStringNotContainsString('s3cr3t', json_encode($e->params, JSON_THROW_ON_ERROR));
        }
    }
}
