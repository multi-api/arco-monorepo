<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Config;

use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\ConfigException;
use Multiapi\Arco\Config\ConfigItems;
use Multiapi\Arco\Config\ConfigLayer;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Config\LayerConflicts;
use PHPUnit\Framework\TestCase;

final class ProcessConfigTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function raw(): array
    {
        return App::make('shop')->region('us-east-1')->environment('production')->toArray();
    }

    /** @param array<string, mixed> $entry */
    private static function layer(array $entry): ConfigLayer
    {
        /** @var array{settings?: array<string, mixed>, variables?: array<string, string>, permissions?: list<array<string, mixed>>, domains?: list<string>, queues?: array<string, array<string, mixed>>, schedules?: array<string, array<string, mixed>>, storage?: array<string, array<string, mixed>>, tables?: array<string, array<string, mixed>>} $entry */
        return new ConfigLayer('layer.json', 'shop', 'us-east-1', 'production', $entry);
    }

    public function test_process_and_schedules_resolve(): void
    {
        $raw = self::raw();
        $raw['process'] = ['language' => 'go', 'main' => './cmd/app', 'command' => ['bin/app', '--serve'], 'healthPath' => '/up'];
        $raw['schedules'] = ['cleanup' => ['every' => 'rate(1 hour)'], 'report' => ['every' => 'cron(0 8 * * ? *)', 'route' => '/jobs/report', 'timeout' => 120]];
        $raw['queues'] = ['emails' => []];

        $config = ConfigResolver::resolve($raw, 'production');

        self::assertTrue($config->isProcess());
        self::assertNotNull($config->process);
        self::assertSame('go', $config->process->language);
        self::assertSame('./cmd/app', $config->process->main);
        self::assertSame(['bin/app', '--serve'], $config->process->command);
        self::assertSame('/up', $config->process->healthPath);
        self::assertSame(['cleanup', 'report'], array_map(fn ($s) => $s->name, $config->schedules));
        self::assertSame('/_arco/schedule/cleanup', $config->schedules[0]->route());
        self::assertSame(60, $config->schedules[0]->timeout);
        self::assertSame('rate(1 hour)', $config->schedules[0]->every);
        self::assertSame('/jobs/report', $config->schedules[1]->route());
        self::assertSame(120, $config->schedules[1]->timeout);
        self::assertSame('/_arco/queues/emails', $config->queues[0]->route());
        self::assertTrue($config->consoleEnabled());
    }

    public function test_process_defaults(): void
    {
        $raw = self::raw();
        $raw['process'] = ['language' => 'go'];

        $config = ConfigResolver::resolve($raw, 'production');

        self::assertNotNull($config->process);
        self::assertNull($config->process->main);
        self::assertSame(['bin/app'], $config->process->command);
        self::assertSame('/', $config->process->healthPath);
        self::assertFalse($config->consoleEnabled(), 'a process app without schedules has no console function');
    }

    public function test_environment_level_false_drops_an_app_schedule(): void
    {
        $raw = self::raw();
        $raw['process'] = ['language' => 'go'];
        $raw['schedules'] = ['x' => ['every' => 'rate(1 day)'], 'y' => ['every' => 'rate(2 days)']];
        $raw['environments']['production']['schedules'] = ['x' => false, 'z' => ['every' => 'rate(3 days)']];

        $config = ConfigResolver::resolve($raw, 'production');

        self::assertSame(['y', 'z'], array_map(fn ($s) => $s->name, $config->schedules));
    }

    public function test_layer_schedule_is_added_after_the_base_ones(): void
    {
        $raw = self::raw();
        $raw['process'] = ['language' => 'go'];
        $raw['schedules'] = ['a' => ['every' => 'rate(1 day)']];

        $config = ConfigResolver::resolve($raw, 'production', self::layer(['schedules' => ['b' => ['every' => 'rate(5 minutes)', 'timeout' => 30]]]));

        self::assertSame(['a', 'b'], array_map(fn ($s) => $s->name, $config->schedules));
        self::assertSame(30, $config->schedules[1]->timeout);
        self::assertSame('cloud', $config->sources['schedule:b']);
        self::assertSame('app', $config->sources['schedule:a']);
    }

    public function test_same_schedule_name_in_layer_and_base_conflicts(): void
    {
        $raw = self::raw();
        $raw['process'] = ['language' => 'go'];
        $raw['schedules'] = ['cleanup' => ['every' => 'rate(1 day)']];

        try {
            ConfigResolver::resolve($raw, 'production', self::layer(['schedules' => ['cleanup' => ['every' => 'rate(5 minutes)']]]));
            self::fail('a conflict was expected');
        } catch (LayerConflicts $e) {
            self::assertSame([['key' => 'config.layer_conflict', 'params' => ['kind' => 'schedule', 'name' => 'cleanup', 'file' => 'arco.json']]], $e->errors);
        }
    }

    public function test_env_level_drop_frees_the_name_for_the_layer(): void
    {
        $raw = self::raw();
        $raw['process'] = ['language' => 'go'];
        $raw['schedules'] = ['cleanup' => ['every' => 'rate(1 day)']];
        $raw['environments']['production']['schedules'] = ['cleanup' => false];

        $config = ConfigResolver::resolve($raw, 'production', self::layer(['schedules' => ['cleanup' => ['every' => 'rate(5 minutes)']]]));

        self::assertSame('rate(5 minutes)', $config->schedules[0]->every);
    }

    public function test_adopt_schedule_waits_while_arco_json_declares_it(): void
    {
        $raw = self::raw();
        $raw['process'] = ['language' => 'go'];
        $raw['schedules'] = ['cleanup' => ['every' => 'rate(1 day)', 'route' => '/jobs/cleanup']];

        $config = ConfigResolver::resolve($raw, 'production', self::layer(['schedules' => ['cleanup' => ['every' => 'rate(5 minutes)', 'route' => '/jobs/other', 'timeout' => 30, 'adopt' => true]]]));

        self::assertCount(1, $config->schedules);
        self::assertSame('rate(1 day)', $config->schedules[0]->every, "the file's entry wins while it declares the name");
        self::assertSame('/jobs/cleanup', $config->schedules[0]->route());
        self::assertSame(60, $config->schedules[0]->timeout);
        self::assertSame('app', $config->sources['schedule:cleanup']);
        self::assertSame([['key' => 'config.layer_adopt_waiting', 'params' => ['kind' => 'schedule', 'name' => 'cleanup', 'file' => 'arco.json']]], $config->notes);
    }

    public function test_adopt_schedule_takes_over_once_arco_json_lets_it_go(): void
    {
        $raw = self::raw();
        $raw['process'] = ['language' => 'go'];

        $config = ConfigResolver::resolve($raw, 'production', self::layer(['schedules' => ['cleanup' => ['every' => 'rate(5 minutes)', 'route' => '/jobs/cleanup', 'timeout' => 30, 'adopt' => true]]]));

        self::assertSame(['cleanup'], array_map(fn ($s) => $s->name, $config->schedules));
        self::assertSame('rate(5 minutes)', $config->schedules[0]->every);
        self::assertSame('/jobs/cleanup', $config->schedules[0]->route());
        self::assertSame(30, $config->schedules[0]->timeout);
        self::assertSame('cloud', $config->sources['schedule:cleanup']);
        self::assertSame([], $config->notes);
        // The rule name comes from app/env/name alone: the rule arco.json made is the one kept.
        self::assertSame('arco-shop-production-cleanup-' . substr(hash('sha256', 'shop/production/cleanup'), 0, 8) . '-scheduler', \Multiapi\Arco\Deploy\ProcessSchedules::ruleName($config, 'cleanup'));
        $items = array_values(array_filter(ConfigItems::list($config), fn (array $i): bool => $i['kind'] === 'schedule'));
        self::assertSame([['kind' => 'schedule', 'name' => 'cleanup', 'source' => 'cloud', 'options' => ['every' => 'rate(5 minutes)', 'route' => '/jobs/cleanup', 'timeout' => 30]]], $items, 'adopt never reaches the options');
    }

    public function test_adopt_false_schedule_still_conflicts(): void
    {
        $raw = self::raw();
        $raw['process'] = ['language' => 'go'];
        $raw['schedules'] = ['cleanup' => ['every' => 'rate(1 day)']];

        try {
            ConfigResolver::resolve($raw, 'production', self::layer(['schedules' => ['cleanup' => ['every' => 'rate(1 day)', 'adopt' => false]]]));
            self::fail('a conflict was expected');
        } catch (LayerConflicts $e) {
            self::assertSame([['key' => 'config.layer_conflict', 'params' => ['kind' => 'schedule', 'name' => 'cleanup', 'file' => 'arco.json']]], $e->errors);
        }
    }

    public function test_layer_queue_route(): void
    {
        $raw = self::raw();
        $raw['process'] = ['language' => 'go'];

        $config = ConfigResolver::resolve($raw, 'production', self::layer(['queues' => ['emails' => ['route' => '/jobs/emails']]]));

        self::assertSame('/jobs/emails', $config->queues[0]->route());
        self::assertSame('/jobs/emails', ConfigItems::list($config)[0]['options']['route']);
    }

    public function test_layer_shape_errors_point_at_the_key(): void
    {
        $path = \Multiapi\Arco\Tests\Support\TempPaths::path('arco-layer-', '.json');
        file_put_contents($path, json_encode(['schema' => 1, 'environments' => ['production' => ['schedules' => ['x' => ['every' => 5]]]]]));

        try {
            ConfigLayer::load($path);
            self::fail('the layer should be refused');
        } catch (ConfigException $e) {
            self::assertSame('config.layer_invalid', $e->key);
            self::assertSame('/environments/production/schedules/x/every', $e->params['path']);
        }
    }

    public function test_a_php_config_has_no_process_and_is_unchanged(): void
    {
        $config = ConfigResolver::resolve(self::raw(), 'production');

        self::assertFalse($config->isProcess());
        self::assertNull($config->process);
        self::assertSame([], $config->schedules);
        self::assertFalse($config->consoleEnabled());
        self::assertSame([], ConfigItems::list($config));
    }

    public function test_items_list_schedules_after_the_vpc_and_queue_route_is_null_for_php(): void
    {
        $raw = self::raw();
        $raw['process'] = ['language' => 'go'];
        $raw['schedules'] = ['cleanup' => ['every' => 'rate(1 day)', 'timeout' => 90]];
        $raw['queues'] = ['emails' => []];
        $items = ConfigItems::list(ConfigResolver::resolve($raw, 'production'));

        self::assertSame('queue', $items[0]['kind']);
        self::assertSame('/_arco/queues/emails', $items[0]['options']['route']);
        $last = $items[array_key_last($items)];
        self::assertSame(['kind' => 'schedule', 'name' => 'cleanup', 'source' => 'arco.php', 'options' => ['every' => 'rate(1 day)', 'route' => '/_arco/schedule/cleanup', 'timeout' => 90]], $last);

        $php = self::raw();
        $php['queues'] = ['emails' => []];
        self::assertNull(ConfigItems::list(ConfigResolver::resolve($php, 'production'))[0]['options']['route']);
    }
}
