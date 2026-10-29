<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Config;

use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\ConfigException;
use Multiapi\Arco\Config\ConfigLayer;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Config\Environment;
use Multiapi\Arco\Config\LayerConflicts;
use PHPUnit\Framework\TestCase;

final class ConfigResolverLayerTest extends TestCase
{
    /** @param array<string, mixed> $entry */
    private static function layer(array $entry, ?string $name = 'shop', ?string $region = 'us-east-1', string $environment = 'production'): ConfigLayer
    {
        /** @var array{settings?: array<string, mixed>, variables?: array<string, string>, permissions?: list<array<string, mixed>>, domains?: list<string>, queues?: array<string, array<string, mixed>>, storage?: array<string, array<string, mixed>>, tables?: array<string, array<string, mixed>>} $entry */
        return new ConfigLayer('layer.json', $name, $region, $environment, $entry);
    }

    /**
     * @param (callable(Environment): mixed)|null $env
     * @return array<string, mixed>
     */
    private static function raw(?callable $app = null, ?callable $env = null): array
    {
        $builder = App::make('shop')->region('us-east-1');
        if ($app !== null) {
            $app($builder);
        }
        return $builder->environment('production', $env)->toArray();
    }

    /**
     * @param array<string, mixed> $raw
     * @return list<array{key: string, params: array<string, string>}>
     */
    private static function conflicts(array $raw, ConfigLayer $layer): array
    {
        try {
            ConfigResolver::resolve($raw, 'production', $layer);
        } catch (LayerConflicts $e) {
            return $e->errors;
        }
        self::fail('Expected LayerConflicts.');
    }

    /** @return array{key: string, params: array<string, string>} */
    private static function conflict(string $kind, string $name): array
    {
        return ['key' => 'config.layer_conflict', 'params' => ['kind' => $kind, 'name' => $name]];
    }

    public function test_layer_adds_queue_after_arco_php_queues(): void
    {
        $config = ConfigResolver::resolve(
            self::raw(fn (App $a) => $a->queue('jobs')),
            'production',
            self::layer(['queues' => ['emails' => ['timeout' => 90]]]),
        );

        self::assertSame(['jobs', 'emails'], array_map(fn ($q) => $q->name, $config->queues));
        self::assertTrue($config->queues[0]->default);
        self::assertFalse($config->queues[1]->default);
        self::assertSame(90, $config->queues[1]->timeout);
        self::assertSame('cloud', $config->sources['queue:emails']);
        self::assertSame('app', $config->sources['queue:jobs']);
        self::assertSame([], $config->notes);
    }

    public function test_same_queue_name_is_a_conflict(): void
    {
        self::assertSame(
            [self::conflict('queue', 'emails')],
            self::conflicts(self::raw(fn (App $a) => $a->queue('emails')), self::layer(['queues' => ['emails' => []]])),
        );
        self::assertSame(
            [self::conflict('storage', 'uploads')],
            self::conflicts(self::raw(fn (App $a) => $a->storage('uploads')), self::layer(['storage' => ['uploads' => []]])),
        );
        self::assertSame(
            [self::conflict('table', 'state')],
            self::conflicts(self::raw(env: fn (Environment $e) => $e->table('state')), self::layer(['tables' => ['state' => []]])),
        );
    }

    public function test_all_conflicts_are_listed(): void
    {
        $raw = self::raw(
            fn (App $a) => $a->scheduler(1)->variables(['APP_ENV' => 'production'])->queue('emails')->storage('uploads')->table('state'),
        );
        $layer = self::layer([
            'tables' => ['state' => []],
            'storage' => ['uploads' => []],
            'queues' => ['emails' => []],
            'variables' => ['APP_ENV' => 'staging'],
            'settings' => ['scheduler' => 5],
        ], 'other', 'eu-west-1');

        self::assertSame([
            self::conflict('name', 'other'),
            self::conflict('region', 'eu-west-1'),
            self::conflict('setting', 'scheduler'),
            self::conflict('variable', 'APP_ENV'),
            self::conflict('queue', 'emails'),
            self::conflict('storage', 'uploads'),
            self::conflict('table', 'state'),
        ], self::conflicts($raw, $layer));

        self::assertCount(2, self::conflicts(
            self::raw(fn (App $a) => $a->queue('a')->queue('b')),
            self::layer(['queues' => ['a' => [], 'b' => []]]),
        ));
    }

    public function test_env_level_drop_is_not_a_conflict(): void
    {
        $config = ConfigResolver::resolve(
            self::raw(fn (App $a) => $a->queue('emails'), fn (Environment $e) => $e->queue('emails', false)),
            'production',
            self::layer(['queues' => ['emails' => ['tries' => 5]]]),
        );

        self::assertSame(['emails'], array_map(fn ($q) => $q->name, $config->queues));
        self::assertSame(5, $config->queues[0]->tries);
        self::assertSame('cloud', $config->sources['queue:emails']);
    }

    public function test_setting_equal_is_fine_different_conflicts_unset_takes_layer(): void
    {
        $equal = ConfigResolver::resolve(self::raw(env: fn (Environment $e) => $e->scheduler(1)), 'production', self::layer(['settings' => ['scheduler' => 1]]));
        self::assertSame(1, $equal->scheduler);
        self::assertSame('environment', $equal->sources['scheduler']);
        $app = ConfigResolver::resolve(self::raw(fn (App $a) => $a->scheduler(1)), 'production', self::layer(['settings' => ['scheduler' => 1]]));
        self::assertSame('app', $app->sources['scheduler']);

        self::assertSame(
            [self::conflict('setting', 'scheduler')],
            self::conflicts(self::raw(env: fn (Environment $e) => $e->scheduler(1)), self::layer(['settings' => ['scheduler' => 5]])),
        );

        $unset = ConfigResolver::resolve(self::raw(), 'production', self::layer(['settings' => ['scheduler' => 5, 'account' => '123456789012']]));
        self::assertSame(5, $unset->scheduler);
        self::assertSame('123456789012', $unset->account);
        self::assertSame('cloud', $unset->sources['scheduler']);
        self::assertSame('cloud', $unset->sources['account']);
        self::assertSame('default', $unset->sources['timeout']);
    }

    public function test_equal_vpc_in_another_key_order_is_not_a_conflict(): void
    {
        $raw = self::raw(env: fn (Environment $e) => $e->vpc(['subnet-a', 'subnet-b'], ['sg-1'], ['s3']));
        $config = ConfigResolver::resolve($raw, 'production', self::layer(['settings' => ['vpc' => [
            'endpoints' => ['s3'],
            'securityGroups' => ['sg-1'],
            'subnets' => ['subnet-a', 'subnet-b'],
        ]]]));
        self::assertSame('environment', $config->sources['vpc']);

        // List order is significant.
        self::assertSame([self::conflict('setting', 'vpc')], self::conflicts($raw, self::layer(['settings' => ['vpc' => [
            'subnets' => ['subnet-b', 'subnet-a'],
            'securityGroups' => ['sg-1'],
            'endpoints' => ['s3'],
        ]]])));
    }

    public function test_int_and_float_differ(): void
    {
        self::assertSame(
            [self::conflict('setting', 'timeout')],
            self::conflicts(self::raw(fn (App $a) => $a->timeout(30)), self::layer(['settings' => ['timeout' => 30.0]])),
        );
    }

    public function test_variable_rules(): void
    {
        $raw = self::raw(fn (App $a) => $a->variables(['APP_ENV' => 'production']), fn (Environment $e) => $e->variables(['LOG' => 'x']));

        $config = ConfigResolver::resolve($raw, 'production', self::layer(['variables' => ['APP_ENV' => 'production', 'LOG' => 'x', 'MAIL' => 'hi']]));
        self::assertSame(['APP_ENV' => 'production', 'LOG' => 'x', 'MAIL' => 'hi'], $config->variables);
        self::assertSame('app', $config->sources['variable:APP_ENV']);
        self::assertSame('environment', $config->sources['variable:LOG']);
        self::assertSame('cloud', $config->sources['variable:MAIL']);

        try {
            ConfigResolver::resolve($raw, 'production', self::layer(['variables' => ['APP_ENV' => 'super-secret-value']]));
            self::fail('Expected LayerConflicts.');
        } catch (LayerConflicts $e) {
            self::assertSame([self::conflict('variable', 'APP_ENV')], $e->errors);
            self::assertStringNotContainsString('super-secret-value', $e->getMessage());
            self::assertStringNotContainsString('production', $e->getMessage());
        }
    }

    public function test_domains_are_appended_and_a_shared_one_listed_once(): void
    {
        $env = ConfigResolver::resolve(self::raw(env: fn (Environment $e) => $e->domain(['a.com'])), 'production', self::layer(['domains' => ['a.com', 'b.com']]));
        self::assertSame(['a.com', 'b.com'], $env->domains);
        self::assertSame('environment', $env->sources['domain:a.com']);
        self::assertSame('cloud', $env->sources['domain:b.com']);

        $app = ConfigResolver::resolve(self::raw(fn (App $a) => $a->domain(['a.com'])), 'production', self::layer(['domains' => ['a.com', 'b.com']]));
        self::assertSame(['a.com', 'b.com'], $app->domains);
        self::assertSame('app', $app->sources['domain:a.com']);
        self::assertSame('cloud', $app->sources['domain:b.com']);
    }

    public function test_permissions_are_appended(): void
    {
        $mine = ['Effect' => 'Allow', 'Action' => 's3:GetObject', 'Resource' => '*'];
        $cloud = ['Effect' => 'Allow', 'Action' => 'dsql:DbConnect', 'Resource' => '*'];

        $config = ConfigResolver::resolve(self::raw(fn (App $a) => $a->permissions([$mine])), 'production', self::layer(['permissions' => [$cloud, $mine]]));

        self::assertSame([$mine, $cloud, $mine], $config->permissions);
    }

    public function test_name_and_region_must_match(): void
    {
        self::assertSame([self::conflict('name', 'other')], self::conflicts(self::raw(), self::layer([], 'other')));
        self::assertSame([self::conflict('region', 'eu-west-1')], self::conflicts(self::raw(), self::layer([], 'shop', 'eu-west-1')));

        $config = ConfigResolver::resolve(self::raw(), 'production', self::layer([], null, null));
        self::assertSame(['shop', 'us-east-1'], [$config->app, $config->region]);
    }

    public function test_adopt_waits_while_arco_php_declares_the_name(): void
    {
        $config = ConfigResolver::resolve(
            self::raw(fn (App $a) => $a->queue('emails')),
            'production',
            self::layer(['queues' => ['emails' => ['adopt' => true, 'timeout' => 90]]]),
        );

        self::assertCount(1, $config->queues);
        self::assertSame(60, $config->queues[0]->timeout);
        self::assertFalse($config->queues[0]->adopt);
        self::assertSame('app', $config->sources['queue:emails']);
        self::assertSame([['key' => 'config.layer_adopt_waiting', 'params' => ['kind' => 'queue', 'name' => 'emails']]], $config->notes);
    }

    public function test_adopt_entry_is_used_when_arco_php_does_not_declare_it(): void
    {
        $config = ConfigResolver::resolve(self::raw(), 'production', self::layer([
            'queues' => ['emails' => ['adopt' => true, 'timeout' => 90]],
            'storage' => ['uploads' => ['adopt' => true, 'public' => ['avatars/']]],
            'tables' => ['state' => ['adopt' => true, 'ttl' => 'expires_at'], 'plain' => []],
        ]));

        self::assertTrue($config->queues[0]->adopt);
        self::assertSame(90, $config->queues[0]->timeout);
        self::assertSame('cloud', $config->sources['queue:emails']);
        self::assertTrue($config->storage[0]->adopt);
        self::assertSame(['avatars/'], $config->storage[0]->public);
        self::assertSame('cloud', $config->sources['storage:uploads']);
        self::assertTrue($config->tables[0]->adopt);
        self::assertSame('expires_at', $config->tables[0]->ttl);
        self::assertFalse($config->tables[1]->adopt);
        self::assertSame('cloud', $config->sources['table:state']);
        self::assertSame([], $config->notes);
    }

    public function test_region_source(): void
    {
        self::assertSame('app', ConfigResolver::resolve(self::raw(), 'production')->sources['region'], 'without a layer');
        self::assertSame('app', ConfigResolver::resolve(self::raw(), 'production', self::layer([]))->sources['region'], 'arco.php has it too');
        $noRegion = App::make('shop')->environment('production')->toArray();
        self::assertSame('cloud', ConfigResolver::resolve($noRegion, 'production', self::layer([]))->sources['region']);
        $layer = self::layer([]);
        $config = ConfigResolver::resolve(ConfigResolver::layerOnly($layer), 'production', $layer);
        self::assertSame(['us-east-1', 'cloud'], [$config->region, $config->sources['region']], 'without arco.php');
    }

    public function test_layer_only(): void
    {
        $layer = self::layer(['queues' => ['emails' => []]]);
        $config = ConfigResolver::resolve(ConfigResolver::layerOnly($layer), 'production', $layer);

        self::assertSame(['shop', 'us-east-1', 'production'], [$config->app, $config->region, $config->environment]);
        self::assertSame(30, $config->timeout);
        self::assertSame(['emails'], array_map(fn ($q) => $q->name, $config->queues));
        self::assertSame('cloud', $config->sources['queue:emails']);
        self::assertTrue($config->hasArcoPhp);
        self::assertFalse($config->withoutArcoPhp()->hasArcoPhp);

        foreach (['name' => self::layer([], null), 'region' => self::layer([], 'shop', null)] as $key => $incomplete) {
            try {
                ConfigResolver::layerOnly($incomplete);
                self::fail('Expected ConfigException.');
            } catch (ConfigException $e) {
                self::assertSame(['config.layer_missing', ['key' => $key]], [$e->key, $e->params]);
            }
        }
    }

    public function test_environment_missing_from_arco_php_is_added_by_the_layer(): void
    {
        $raw = App::make('shop')->region('us-east-1')->timeout(45)->environment('staging')->toArray();
        $layer = self::layer(['settings' => ['scheduler' => 1]]);

        self::assertSame(['staging'], ConfigResolver::environments($raw));
        self::assertSame(['staging', 'production'], ConfigResolver::environments($raw, $layer));
        self::assertSame(['staging'], ConfigResolver::environments($raw, self::layer([], environment: 'staging')));

        $config = ConfigResolver::resolve($raw, 'production', $layer);
        self::assertSame(['production', 45, 1], [$config->environment, $config->timeout, $config->scheduler]);
    }

    public function test_layer_for_another_environment_is_refused(): void
    {
        try {
            ConfigResolver::resolve(self::raw(), 'production', self::layer([], environment: 'staging'));
            self::fail('Expected ConfigException.');
        } catch (ConfigException $e) {
            self::assertSame(['config.layer_environment', ['layer' => 'staging', 'environment' => 'production']], [$e->key, $e->params]);
        }
    }

    public function test_no_layer_is_unchanged(): void
    {
        $raw = self::raw(fn (App $a) => $a->queue('jobs')->storage('files')->domain(['a.com'])->variables(['A' => 'b']));

        $config = ConfigResolver::resolve($raw, 'production');
        self::assertEquals($config, ConfigResolver::resolve($raw, 'production', null));
        self::assertSame([], $config->notes);
        self::assertTrue($config->hasArcoPhp);
        // Per-item sources are always there (the inventory reads them), never `cloud` without a layer.
        self::assertSame(['app', 'app', 'app', 'app'], [
            $config->sources['queue:jobs'], $config->sources['storage:files'], $config->sources['variable:A'], $config->sources['domain:a.com'],
        ]);
        self::assertNotContains('cloud', $config->sources);
        self::assertSame(['app', 'app', 'app'], [$config->sources['queues'], $config->sources['storage'], $config->sources['domains']]);
        self::assertFalse($config->queues[0]->adopt);
    }
}
