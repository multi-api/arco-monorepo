<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Config;

use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\ConfigException;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Config\Environment;
use Multiapi\Arco\Config\Runtime;
use PHPUnit\Framework\TestCase;

final class ConfigResolverTest extends TestCase
{
    /** @return array<string, mixed> */
    private function raw(): array
    {
        return App::make('shop')
            ->region('us-east-1')
            ->concurrency(48)
            ->variables(['APP_ENV' => 'production', 'LOG_LEVEL' => 'info'])
            ->runtime(fn (Runtime $r) => $r->workers(min: 4, max: 32)->phpIni(['memory_limit' => '256M']))
            ->exclude(['tests/**'])
            ->environment('staging', fn (Environment $e) => $e->variables(['APP_ENV' => 'staging']))
            ->environment('production', fn (Environment $e) => $e
                ->concurrency(64)
                ->account('222222222222')
                ->runtime(fn (Runtime $r) => $r->workers(min: 8, max: 64)->phpIni(['opcache.jit' => 'tracing'])))
            ->toArray();
    }

    public function test_lists_environments(): void
    {
        self::assertSame(['staging', 'production'], ConfigResolver::environments($this->raw()));
    }

    public function test_environment_overrides_app_which_overrides_defaults(): void
    {
        $config = ConfigResolver::resolve($this->raw(), 'production');

        self::assertSame('shop-production', $config->functionName());
        self::assertSame(64, $config->concurrency);
        self::assertSame('environment', $config->sources['concurrency']);
        self::assertSame(30, $config->timeout);
        self::assertSame('default', $config->sources['timeout']);
        self::assertSame('222222222222', $config->account);
        self::assertSame(8, $config->runtime->workersMin);
        self::assertSame(64, $config->runtime->workersMax);
        self::assertSame('environment', $config->sources['runtime.workers']);
        self::assertSame(8, $config->runtime->threads);
        self::assertNull($config->runtime->build);
        self::assertNull($config->runtime->frankenphp);
        self::assertSame('default', $config->sources['runtime.build']);
        self::assertSame(['memory_limit' => '256M', 'opcache.jit' => 'tracing'], $config->runtime->phpIni);
        self::assertSame('direct', $config->runtime->mode);
        self::assertSame('public', $config->auth);
        self::assertSame(['tests/**'], $config->exclude);
    }

    public function test_variables_merge_by_key(): void
    {
        $config = ConfigResolver::resolve($this->raw(), 'staging');

        self::assertSame(['APP_ENV' => 'staging', 'LOG_LEVEL' => 'info'], $config->variables);
        self::assertSame(48, $config->concurrency);
        self::assertSame('app', $config->sources['concurrency']);
        self::assertNull($config->account);
    }

    public function test_unknown_environment(): void
    {
        $this->expectException(ConfigException::class);
        ConfigResolver::resolve($this->raw(), 'qa');
    }

    public function test_build_and_frankenphp_resolve_with_inheritance(): void
    {
        $raw = App::make('shop')->region('us-east-1')
            ->runtime(fn (Runtime $r) => $r->build('1.13.0-r1'))
            ->environment('staging')
            ->environment('production', fn (Environment $e) => $e->runtime(fn (Runtime $r) => $r->frankenphp('1.13.0')))
            ->toArray();

        $staging = ConfigResolver::resolve($raw, 'staging');
        self::assertSame('1.13.0-r1', $staging->runtime->build);
        self::assertSame('app', $staging->sources['runtime.build']);

        $production = ConfigResolver::resolve($raw, 'production');
        self::assertSame('1.13.0-r1', $production->runtime->build);
        self::assertSame('1.13.0', $production->runtime->frankenphp);
        self::assertSame('environment', $production->sources['runtime.frankenphp']);
    }

    public function test_domains_default_to_none_and_deploy_account_dns(): void
    {
        $config = ConfigResolver::resolve($this->raw(), 'production');

        self::assertSame([], $config->domains);
        self::assertNull($config->dnsProfile);
        self::assertFalse($config->dnsManual);
        self::assertNull($config->certificate);
        self::assertSame('default', $config->sources['domains']);
    }

    public function test_environment_domains_replace_app_domains_as_a_whole(): void
    {
        $raw = App::make('shop')->region('us-east-1')
            ->domain(['shop.example.com', 'www.example.com'])
            ->dns('common')
            ->environment('staging', fn (Environment $e) => $e->domain('staging.example.com')->dns(false))
            ->environment('production', fn (Environment $e) => $e->certificate('arn:aws:acm:us-east-1:111111111111:certificate/x'))
            ->toArray();

        $staging = ConfigResolver::resolve($raw, 'staging');
        self::assertSame(['staging.example.com'], $staging->domains);
        self::assertSame('environment', $staging->sources['domains']);
        self::assertTrue($staging->dnsManual);
        self::assertNull($staging->dnsProfile);
        self::assertSame('environment', $staging->sources['dns']);

        $production = ConfigResolver::resolve($raw, 'production');
        self::assertSame(['shop.example.com', 'www.example.com'], $production->domains);
        self::assertSame('app', $production->sources['domains']);
        self::assertSame('common', $production->dnsProfile);
        self::assertFalse($production->dnsManual);
        self::assertSame('arn:aws:acm:us-east-1:111111111111:certificate/x', $production->certificate);
        self::assertSame('environment', $production->sources['certificate']);
        self::assertSame('app', $production->sources['dns']);
    }

    public function test_max_instances_and_its_max_environments_alias_resolve_to_the_same_setting(): void
    {
        $raw = App::make('shop')->region('us-east-1')
            ->maxInstances(10)
            ->environment('staging', fn (Environment $e) => $e->maxEnvironments(2))
            ->environment('production')
            ->toArray();

        self::assertSame(['maxInstances' => 10], $raw['settings']);
        $staging = ConfigResolver::resolve($raw, 'staging');
        self::assertSame(2, $staging->maxInstances);
        self::assertSame('environment', $staging->sources['maxInstances']);
        $production = ConfigResolver::resolve($raw, 'production');
        self::assertSame(10, $production->maxInstances);
        self::assertSame('app', $production->sources['maxInstances']);
    }

    public function test_concurrency_is_left_to_aws_by_default(): void
    {
        $config = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->environment('production')->toArray(), 'production');

        self::assertNull($config->concurrency);
        self::assertSame('default', $config->sources['concurrency']);
    }

    public function test_console_settings_and_deploy_commands(): void
    {
        $raw = App::make('shop')->region('us-east-1')
            ->deployCommands(['migrate --force'])
            ->consoleTimeout(600)
            ->environment('staging', fn (Environment $e) => $e->deployCommands([]))
            ->environment('production', fn (Environment $e) => $e->deployCommands(['migrate --force', 'config:cache']))
            ->environment('qa')
            ->toArray();

        $production = ConfigResolver::resolve($raw, 'production');
        self::assertSame(['migrate --force', 'config:cache'], $production->deployCommands);
        self::assertSame('environment', $production->sources['deployCommands']);
        self::assertSame(600, $production->consoleTimeout);
        self::assertSame('app', $production->sources['consoleTimeout']);
        self::assertSame('shop-production-console', $production->consoleFunctionName());

        $staging = ConfigResolver::resolve($raw, 'staging');
        self::assertSame([], $staging->deployCommands, 'an environment list replaces the app list, even when empty');

        $qa = ConfigResolver::resolve($raw, 'qa');
        self::assertSame(['migrate --force'], $qa->deployCommands);
        self::assertSame('app', $qa->sources['deployCommands']);

        $defaults = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->environment('qa')->toArray(), 'qa');
        self::assertSame(900, $defaults->consoleTimeout);
        self::assertSame([], $defaults->deployCommands);
        self::assertSame('default', $defaults->sources['deployCommands']);
    }

    public function test_production_is_protected_unless_switched_off(): void
    {
        $raw = App::make('shop')->region('us-east-1')
            ->environment('production')
            ->environment('staging')
            ->environment('critical', fn (Environment $e) => $e->protected())
            ->toArray();

        self::assertTrue(ConfigResolver::resolve($raw, 'production')->isProtected());
        self::assertFalse(ConfigResolver::resolve($raw, 'staging')->isProtected());
        self::assertTrue(ConfigResolver::resolve($raw, 'critical')->isProtected());
        self::assertSame('environment', ConfigResolver::resolve($raw, 'critical')->sources['protected']);

        $off = App::make('shop')->region('us-east-1')->environment('production', fn (Environment $e) => $e->protected(false))->toArray();
        self::assertFalse(ConfigResolver::resolve($off, 'production')->isProtected());
    }

    public function test_console_defaults_unset(): void
    {
        $config = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->environment('production')->toArray(), 'production');

        self::assertNull($config->console);
        self::assertNull($config->scheduler);
        self::assertNull($config->logRetention);
        self::assertFalse($config->consoleEnabled());
    }

    public function test_scheduler_implies_console(): void
    {
        $config = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->scheduler(1)->environment('production')->toArray(), 'production');

        self::assertTrue($config->consoleEnabled());
        self::assertNull($config->console);
        self::assertSame(1, $config->scheduler);
    }

    public function test_environment_turns_off_app_scheduler(): void
    {
        $raw = App::make('shop')->region('us-east-1')->scheduler(5)
            ->environment('production', fn (Environment $e) => $e->scheduler(false)->logRetention(false))
            ->toArray();
        $config = ConfigResolver::resolve($raw, 'production');

        self::assertNull($config->scheduler);
        self::assertSame('environment', $config->sources['scheduler']);
        self::assertFalse($config->logRetention);
        self::assertFalse($config->consoleEnabled());
    }

    public function test_environment_queue_merges_over_app_queue(): void
    {
        $raw = App::make('shop')->region('us-east-1')
            ->queue('jobs')->queue('emails', timeout: 120)
            ->environment('production', fn (Environment $e) => $e->queue('emails', memory: 2048)->queue('reports'))
            ->toArray();

        $config = ConfigResolver::resolve($raw, 'production');

        self::assertSame(['jobs', 'emails', 'reports'], array_map(fn ($q) => $q->name, $config->queues));
        self::assertSame(60, $config->queues[0]->timeout);
        self::assertSame(120, $config->queues[1]->timeout);
        self::assertSame(2048, $config->queues[1]->memory);
        self::assertSame(1024, $config->queues[0]->memory);
        self::assertSame('environment', $config->sources['queues']);
    }

    public function test_environment_false_drops_a_queue(): void
    {
        $raw = App::make('shop')->region('us-east-1')->queue('jobs')->queue('emails')
            ->environment('production', fn (Environment $e) => $e->queue('emails', false))
            ->environment('staging')
            ->toArray();

        self::assertSame(['jobs'], array_map(fn ($q) => $q->name, ConfigResolver::resolve($raw, 'production')->queues));
        $staging = ConfigResolver::resolve($raw, 'staging');
        self::assertSame(['jobs', 'emails'], array_map(fn ($q) => $q->name, $staging->queues));
        self::assertSame('app', $staging->sources['queues']);
    }

    public function test_no_queues_is_default_source(): void
    {
        $config = ConfigResolver::resolve(App::make('shop')->environment('production')->toArray(), 'production');

        self::assertSame([], $config->queues);
        self::assertSame('default', $config->sources['queues']);
        self::assertNull($config->defaultQueue());
    }

    public function test_default_queue_is_first_unless_marked(): void
    {
        $first = ConfigResolver::resolve(App::make('shop')->queue('jobs')->queue('emails')->environment('production')->toArray(), 'production');
        self::assertSame('jobs', $first->defaultQueue()?->name);

        $marked = ConfigResolver::resolve(App::make('shop')->queue('jobs')->queue('emails', default: true)->environment('production')->toArray(), 'production');
        self::assertSame('emails', $marked->defaultQueue()?->name);
        self::assertFalse($marked->queues[0]->default);
    }

    public function test_existing_queue_defaults_to_no_dlq(): void
    {
        $raw = App::make('shop')->queue('legacy', existing: 'old-queue')->queue('own')->queue('own2', dlq: false)->environment('production')->toArray();
        $queues = ConfigResolver::resolve($raw, 'production')->queues;

        self::assertFalse($queues[0]->dlq);
        self::assertTrue($queues[1]->dlq);
        self::assertFalse($queues[2]->dlq);
    }

    public function test_worker_function_name(): void
    {
        $config = ConfigResolver::resolve(App::make('shop')->environment('production')->toArray(), 'production');

        self::assertSame('shop-production-queue-emails', $config->workerFunctionName('emails'));
    }
}
