<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Config;

use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ConfigItems;
use Multiapi\Arco\Config\ConfigLayer;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Config\Environment;
use PHPUnit\Framework\TestCase;

final class ConfigItemsTest extends TestCase
{
    /**
     * @param (callable(Environment): mixed)|null $env
     * @param array<string, mixed>|null $layer
     */
    private static function config(?callable $env = null, ?array $layer = null): AppConfig
    {
        /** @var array{settings?: array<string, mixed>, variables?: array<string, string>, permissions?: list<array<string, mixed>>, domains?: list<string>, queues?: array<string, array<string, mixed>>, storage?: array<string, array<string, mixed>>, tables?: array<string, array<string, mixed>>}|null $layer */
        return ConfigResolver::resolve(
            App::make('shop')->region('us-east-1')->environment('production', $env)->toArray(),
            'production',
            $layer === null ? null : new ConfigLayer('layer.json', 'shop', 'us-east-1', 'production', $layer),
        );
    }

    /**
     * @param list<array{kind: string, name: string, source: string, options: array<string, mixed>}> $items
     * @return array<string, array{source: string, options: array<string, mixed>}>
     */
    private static function byKey(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $out[$item['kind'] . ' ' . $item['name']] = ['source' => $item['source'], 'options' => $item['options']];
        }
        return $out;
    }

    public function test_queue_options_have_every_key_with_defaults(): void
    {
        $items = ConfigItems::list(self::config(fn (Environment $e) => $e->queue('emails')));

        self::assertSame([[
            'kind' => 'queue',
            'name' => 'emails',
            'source' => 'arco.php',
            'options' => ['timeout' => 60, 'memory' => 1024, 'maxInstances' => null, 'batchSize' => 1, 'tries' => 3, 'dlq' => true, 'fifo' => false, 'existing' => null, 'default' => true],
        ]], $items);
    }

    public function test_storage_table_cache_console_scheduler_domain_vpc_options(): void
    {
        $config = self::config(fn (Environment $e) => $e
            ->queue('emails')
            ->storage('uploads', public: ['avatars/'])
            ->table('state', ttl: 'expires_at')
            ->cache()
            ->failedJobsTable(existing: 'legacy-failed')
            ->console()
            ->scheduler()
            ->domain('a.com')
            ->vpc(['subnet-1', 'subnet-2'], ['sg-1'], ['s3', 'dynamodb' => 'vpce-1']));

        $items = ConfigItems::list($config);

        self::assertSame(
            ['queue', 'storage', 'table', 'cache', 'failedJobsTable', 'console', 'scheduler', 'domain', 'vpc'],
            array_column($items, 'kind'),
            'in this order',
        );
        $byKey = self::byKey($items);
        self::assertSame(['source' => 'arco.php', 'options' => ['public' => ['avatars/'], 'expire' => [], 'cors' => [], 'existing' => null, 'default' => true]], $byKey['storage uploads']);
        self::assertSame(['source' => 'arco.php', 'options' => ['ttl' => 'expires_at', 'existing' => null]], $byKey['table state']);
        self::assertSame(['source' => 'arco.php', 'options' => ['existing' => null]], $byKey['cache cache']);
        self::assertSame(['source' => 'arco.php', 'options' => ['existing' => 'legacy-failed']], $byKey['failedJobsTable failedJobsTable']);
        self::assertSame(['source' => 'arco.php', 'options' => ['console' => true, 'consoleTimeout' => 900, 'consoleMemory' => 2048]], $byKey['console console']);
        self::assertSame(['source' => 'arco.php', 'options' => ['scheduler' => 1]], $byKey['scheduler scheduler']);
        self::assertSame(['source' => 'arco.php', 'options' => ['domain' => 'a.com', 'dns' => null, 'certificate' => null]], $byKey['domain a.com']);
        self::assertSame(['source' => 'arco.php', 'options' => ['subnets' => ['subnet-1', 'subnet-2'], 'securityGroups' => ['sg-1'], 'endpoints' => ['s3', 'dynamodb' => 'vpce-1']]], $byKey['vpc vpc']);
    }

    public function test_an_empty_config_declares_nothing(): void
    {
        self::assertSame([], ConfigItems::list(self::config()));
    }

    public function test_layer_items_are_cloud_and_dns_is_the_stored_value(): void
    {
        $config = self::config(
            fn (Environment $e) => $e->queue('jobs')->domain('a.com')->dns('cloudflare'),
            ['queues' => ['emails' => ['timeout' => 90]], 'domains' => ['b.com'], 'settings' => ['scheduler' => 5]],
        );

        $byKey = self::byKey(ConfigItems::list($config));

        self::assertSame('arco.php', $byKey['queue jobs']['source']);
        self::assertSame('cloud', $byKey['queue emails']['source']);
        self::assertSame(90, $byKey['queue emails']['options']['timeout']);
        self::assertFalse($byKey['queue emails']['options']['default']);
        self::assertSame(['source' => 'arco.php', 'options' => ['domain' => 'a.com', 'dns' => 'cloudflare', 'certificate' => null]], $byKey['domain a.com']);
        self::assertSame(['source' => 'cloud', 'options' => ['domain' => 'b.com', 'dns' => 'cloudflare', 'certificate' => null]], $byKey['domain b.com']);
        self::assertSame(['source' => 'cloud', 'options' => ['scheduler' => 5]], $byKey['scheduler scheduler']);
        self::assertSame(['source' => 'cloud', 'options' => ['console' => null, 'consoleTimeout' => 900, 'consoleMemory' => 2048]], $byKey['console console'], 'on for the scheduler, from where the scheduler comes');
        self::assertArrayNotHasKey('adopt', $byKey['queue emails']['options']);

        self::assertSame('profile-x', self::byKey(ConfigItems::list(self::config(fn (Environment $e) => $e->domain('a.com')->dns('profile-x'))))['domain a.com']['options']['dns']);
        self::assertFalse(self::byKey(ConfigItems::list(self::config(fn (Environment $e) => $e->domain('a.com')->dns(false))))['domain a.com']['options']['dns']);
    }

    public function test_variables_have_names_and_sources_only(): void
    {
        $config = self::config(
            fn (Environment $e) => $e->variables(['ZETA' => 'zeta-secret-value', 'ALPHA' => 'alpha-secret-value']),
            ['variables' => ['MIDDLE' => 'middle-secret-value']],
        );

        $variables = ConfigItems::variables($config);

        self::assertSame([
            ['name' => 'ALPHA', 'source' => 'arco.php'],
            ['name' => 'MIDDLE', 'source' => 'cloud'],
            ['name' => 'ZETA', 'source' => 'arco.php'],
        ], $variables);
        $json = (string) json_encode(['variables' => $variables, 'config' => ConfigItems::list($config)]);
        self::assertStringNotContainsString('secret-value', $json);
    }

    public function test_for_resource_maps_by_tag_and_by_known_names(): void
    {
        $config = self::config(fn (Environment $e) => $e->queue('emails')->console()->scheduler()->domain('a.com')->vpc(['subnet-1'], ['sg-1']));
        $queue = ConfigItems::list($config)[0]['options'];

        self::assertSame(['source' => 'arco.php', 'options' => $queue], self::forResource($config, 'sqs', 'whatever', ['arco:resource' => 'queue:emails']));
        self::assertSame(['source' => 'arco.php', 'options' => $queue], self::forResource($config, 'sqs', 'whatever', ['arco:resource' => 'queue:emails:dlq']));
        self::assertSame(['source' => 'arco.php', 'options' => $queue], self::forResource($config, 'sqs', 'shop-production-emails-dlq', []), 'by name without the tag');
        self::assertSame(['source' => 'arco.php', 'options' => $queue], self::forResource($config, 'lambda:function', 'shop-production-queue-emails', []));
        self::assertSame(['source' => null, 'options' => null], self::forResource($config, 'sqs', 'x', ['arco:resource' => 'queue:gone']));
        self::assertSame(['source' => null, 'options' => null], self::forResource($config, 'lambda:function', 'shop-production-queue-gone', []));
        self::assertSame(['source' => 'default', 'options' => null], self::forResource($config, 'lambda:function', 'shop-production', []));
        self::assertSame('arco.php', self::forResource($config, 'lambda:function', 'shop-production-console', [])['source']);
        self::assertSame(['scheduler' => 1], self::forResource($config, 'events:rule', 'arco-shop-production-scheduler', [])['options']);
        self::assertSame(['subnets' => ['subnet-1'], 'securityGroups' => ['sg-1'], 'endpoints' => []], self::forResource($config, 'lambda:network-connector', 'nc-1', [])['options']);
        $domains = ['source' => 'arco.php', 'options' => ['domains' => ['a.com'], 'dns' => null, 'certificate' => null]];
        self::assertSame($domains, self::forResource($config, 'cloudfront:distribution', 'E1', []));
        self::assertSame($domains, self::forResource($config, 'acm:certificate', 'abc', []));
        self::assertSame($domains, self::forResource($config, 'cloudfront:function', 'arco-shop-production-host', []));
        foreach (['iam:role', 'logs:log-group', 'cloudformation:stack', 'resource-groups:group'] as $type) {
            self::assertSame(['source' => 'default', 'options' => null], self::forResource($config, $type, 'arco-shop-production', []), $type);
        }
        self::assertSame(['source' => null, 'options' => null], self::forResource($config, 'ssm:parameter', '/shop/production/key', []));
    }

    public function test_domain_resources_are_cloud_only_when_every_domain_is(): void
    {
        $config = self::config(layer: ['domains' => ['a.com', 'b.com']]);
        self::assertSame('cloud', self::forResource($config, 'cloudfront:distribution', 'E1', [])['source']);

        // A domain whose source is missing is Arco's default, so the domains are not all the layer's.
        $sources = $config->sources;
        unset($sources['domain:b.com']);
        $partial = new AppConfig(...[...get_object_vars($config), 'sources' => $sources]);
        self::assertSame('default', self::forResource($partial, 'cloudfront:distribution', 'E1', [])['source']);
        self::assertSame('default', self::forResource($partial, 'acm:certificate', 'abc', [])['source']);
        self::assertSame('default', self::forResource($partial, 'cloudfront:function', 'arco-shop-production-host', [])['source']);

        $mixed = self::config(fn (Environment $e) => $e->domain('a.com'), ['domains' => ['b.com']]);
        self::assertSame('arco.php', self::forResource($mixed, 'cloudfront:distribution', 'E1', [])['source']);
    }

    /**
     * @param array<string, string> $tags
     * @return array{source: ?string, options: ?array<string, mixed>}
     */
    private static function forResource(AppConfig $config, string $type, string $name, array $tags): array
    {
        return ConfigItems::forResource($config, $type, $name, $tags, 'arco-shop-production-scheduler', 'arco-shop-production-host');
    }
}
