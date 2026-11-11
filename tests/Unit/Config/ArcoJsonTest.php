<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Config;

use Multiapi\Arco\Config\ArcoJson;
use Multiapi\Arco\Config\ConfigException;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Tests\Support\TempPaths;
use PHPUnit\Framework\TestCase;

final class ArcoJsonTest extends TestCase
{
    /** @param array<string, mixed>|string $document */
    private function write(array|string $document): string
    {
        $path = TempPaths::path('arco-json-', '.json');
        file_put_contents($path, is_string($document) ? $document : json_encode($document, JSON_THROW_ON_ERROR));
        return $path;
    }

    /** @param array<string, mixed>|string $document */
    private function invalid(array|string $document, string $pointer, bool $hasGoMod = true): void
    {
        try {
            ArcoJson::load($this->write($document), $hasGoMod);
            self::fail('arco.json should be refused');
        } catch (ConfigException $e) {
            self::assertSame('config.json_invalid', $e->key);
            self::assertSame($pointer, $e->params['pointer']);
            self::assertNotSame('', $e->params['reason']);
        }
    }

    public function test_the_spec_example_resolves_for_production(): void
    {
        $raw = ArcoJson::load($this->write([
            'name' => 'orders',
            'region' => 'us-east-1',
            'runtime' => ['kind' => 'process', 'language' => 'go', 'main' => './cmd/api', 'healthPath' => '/healthz'],
            'include' => ['templates/**'],
            'settings' => ['timeout' => 30, 'accessLog' => true],
            'variables' => ['LOG_LEVEL' => 'info'],
            'queues' => ['emails' => ['route' => '/_arco/queues/emails', 'tries' => 3, 'timeout' => 30]],
            'schedules' => ['cleanup' => ['every' => '1h', 'route' => '/_arco/schedule/cleanup', 'timeout' => 60]],
            'storage' => ['uploads' => []],
            'tables' => ['state' => ['ttl' => 'expires_at']],
            'environments' => ['production' => ['settings' => ['maxInstances' => 50], 'domains' => ['orders.example.com']]],
        ]), false);

        self::assertSame([], $raw['runtime']);
        self::assertSame(['language' => 'go', 'main' => './cmd/api', 'healthPath' => '/healthz'], $raw['process']);

        $config = ConfigResolver::resolve($raw, 'production');
        self::assertSame('orders', $config->app);
        self::assertSame('us-east-1', $config->region);
        self::assertSame(50, $config->maxInstances);
        self::assertSame(['orders.example.com'], $config->domains);
        self::assertTrue($config->accessLog);
        self::assertSame(['templates/**'], $config->include);
        self::assertSame(['LOG_LEVEL' => 'info'], $config->variables);
        self::assertNotNull($config->process);
        self::assertSame('go', $config->process->language);
        self::assertSame('/healthz', $config->process->healthPath);
        self::assertSame('cleanup', $config->schedules[0]->name);
        self::assertSame('/_arco/queues/emails', $config->queues[0]->route);
    }

    public function test_the_raw_array_has_the_shape_of_app_to_array(): void
    {
        $raw = ArcoJson::load($this->write(['name' => 'orders', 'region' => 'us-east-1', 'runtime' => ['language' => 'go'], 'environments' => ['production' => []]]), false);

        $expected = \Multiapi\Arco\Config\App::make('orders')->region('us-east-1')->environment('production')->toArray();
        self::assertSame([], array_diff(array_keys($expected), array_keys($raw)));
        self::assertSame($expected['environments'], $raw['environments']);
        self::assertSame(['language' => 'go'], $raw['process']);
        self::assertSame([], $raw['schedules']);
    }

    public function test_missing_runtime_with_go_mod_is_go(): void
    {
        $raw = ArcoJson::load($this->write(['name' => 'orders', 'region' => 'us-east-1']), true);

        self::assertSame(['language' => 'go'], $raw['process']);
    }

    public function test_missing_runtime_without_go_mod_is_refused(): void
    {
        try {
            ArcoJson::load($this->write(['name' => 'orders']), false);
            self::fail('expected config.json_runtime_missing');
        } catch (ConfigException $e) {
            self::assertSame('config.json_runtime_missing', $e->key);
        }
    }

    public function test_the_php_runtime_kind_is_refused(): void
    {
        try {
            ArcoJson::load($this->write(['runtime' => ['kind' => 'php']]), true);
            self::fail('expected config.json_php_runtime');
        } catch (ConfigException $e) {
            self::assertSame('config.json_php_runtime', $e->key);
        }
    }

    public function test_name_and_region_may_be_missing(): void
    {
        $raw = ArcoJson::load($this->write(['runtime' => ['language' => 'go']]), false);

        self::assertNull($raw['name']);
        self::assertNull($raw['region']);
    }

    public function test_command_is_a_list_or_a_string_split_on_spaces(): void
    {
        $list = ArcoJson::load($this->write(['runtime' => ['language' => 'go', 'command' => ['bin/app', '--serve']]]), false);
        $string = ArcoJson::load($this->write(['runtime' => ['language' => 'go', 'command' => 'bin/app  --serve']]), false);

        self::assertSame(['bin/app', '--serve'], $list['process']['command']);
        self::assertSame(['bin/app', '--serve'], $string['process']['command']);
    }

    public function test_environment_level_false_frees_a_name(): void
    {
        $raw = ArcoJson::load($this->write([
            'runtime' => ['language' => 'go'],
            'schedules' => ['cleanup' => ['every' => '1h']],
            'queues' => ['emails' => []],
            'environments' => ['production' => ['schedules' => ['cleanup' => false], 'queues' => ['emails' => false]]],
        ]), false);

        self::assertFalse($raw['environments']['production']['schedules']['cleanup']);
        self::assertFalse($raw['environments']['production']['queues']['emails']);
    }

    public function test_schema_other_than_1_is_refused(): void
    {
        $this->invalid(['schema' => 2, 'runtime' => ['language' => 'go']], '/schema');
        ArcoJson::load($this->write(['schema' => 1, 'runtime' => ['language' => 'go']]), false);
    }

    public function test_unknown_keys_and_wrong_types_carry_a_json_pointer(): void
    {
        $this->invalid(['runtime' => ['language' => 'go'], 'nope' => 1], '/nope');
        $this->invalid(['runtime' => ['language' => 'go', 'php' => []]], '/runtime/php');
        $this->invalid(['runtime' => ['language' => 'rust']], '/runtime/language');
        $this->invalid(['runtime' => ['kind' => 'wasm']], '/runtime/kind');
        $this->invalid(['runtime' => ['language' => 'go', 'main' => 3]], '/runtime/main');
        $this->invalid(['runtime' => ['language' => 'go', 'healthPath' => false]], '/runtime/healthPath');
        $this->invalid(['runtime' => ['language' => 'go', 'command' => ['a', 1]]], '/runtime/command/1');
        $this->invalid(['runtime' => ['language' => 'go', 'command' => 7]], '/runtime/command');
        $this->invalid(['runtime' => ['language' => 'go'], 'name' => 5], '/name');
        $this->invalid(['runtime' => ['language' => 'go'], 'settings' => ['timeout' => 'x']], '/settings/timeout');
        $this->invalid(['runtime' => ['language' => 'go'], 'include' => 'a'], '/include');
        $this->invalid(['runtime' => ['language' => 'go'], 'exclude' => [1]], '/exclude/0');
        $this->invalid(['runtime' => ['language' => 'go'], 'schedules' => ['a' => ['every' => 1]]], '/schedules/a/every');
        $this->invalid(['runtime' => ['language' => 'go'], 'queues' => ['a' => ['route' => 1]]], '/queues/a/route');
        $this->invalid(['runtime' => ['language' => 'go'], 'environments' => ['production' => ['runtime' => []]]], '/environments/production/runtime');
        $this->invalid(['runtime' => ['language' => 'go'], 'environments' => ['production' => ['domains' => 'x']]], '/environments/production/domains');
        $this->invalid(['runtime' => ['language' => 'go'], 'environments' => ['a/b' => ['bad' => 1]]], '/environments/a~1b/bad');
        $this->invalid(['runtime' => ['language' => 'go'], 'environments' => [['domains' => []]]], '/environments');
    }

    public function test_a_parse_error_and_a_non_object_are_refused_at_the_root(): void
    {
        $this->invalid('{nope', '');
        $this->invalid('[1]', '');
    }

    public function test_a_missing_file_is_refused(): void
    {
        $this->invalid_missing();
    }

    private function invalid_missing(): void
    {
        try {
            ArcoJson::load(TempPaths::path('arco-json-missing-', '.json'), true);
            self::fail('expected config.json_invalid');
        } catch (ConfigException $e) {
            self::assertSame('config.json_invalid', $e->key);
            self::assertSame('', $e->params['pointer']);
        }
    }
}
