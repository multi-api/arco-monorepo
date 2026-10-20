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
            ->runtime(fn (Runtime $r) => $r->workers(4)->maxThreads(32)->phpIni(['memory_limit' => '256M']))
            ->exclude(['tests/**'])
            ->environment('staging', fn (Environment $e) => $e->variables(['APP_ENV' => 'staging']))
            ->environment('production', fn (Environment $e) => $e
                ->concurrency(64)
                ->account('222222222222')
                ->runtime(fn (Runtime $r) => $r->workers(8)->maxThreads(64)->phpIni(['opcache.jit' => 'tracing'])))
            ->toArray();
    }

    public function test_queue_max_instances_and_its_deprecated_name_resolve_alike(): void
    {
        $resolve = fn (App $app) => ConfigResolver::resolve($app->region('us-east-1')->environment('production')->toArray(), 'production')->queues[0];

        $new = $resolve(App::make('shop')->queue('a', maxInstances: 5));
        $old = $resolve(App::make('shop')->queue('a', concurrency: 5));

        self::assertSame([5, false], [$new->maxInstances, $new->deprecatedConcurrency]);
        self::assertSame([5, true], [$old->maxInstances, $old->deprecatedConcurrency]);
    }

    public function test_workers_and_max_threads_resolve_like_the_deprecated_min_max(): void
    {
        $resolve = fn (callable $runtime) => ConfigResolver::resolve(App::make('shop')->region('us-east-1')->runtime($runtime)->environment('production')->toArray(), 'production')->runtime;

        $new = $resolve(fn (Runtime $r) => $r->workers(6)->maxThreads(48));
        $old = $resolve(fn (Runtime $r) => $r->workers(min: 6, max: 48));

        self::assertSame([6, 48, false], [$new->workers, $new->maxThreads, $new->deprecatedWorkers]);
        self::assertSame([6, 48, true], [$old->workers, $old->maxThreads, $old->deprecatedWorkers]);
        $defaults = $resolve(fn (Runtime $r) => $r->threads(8));
        self::assertSame([4, 64], [$defaults->workers, $defaults->maxThreads]);
    }

    public function test_an_environment_overriding_workers_ends_the_deprecation(): void
    {
        $raw = App::make('shop')->region('us-east-1')
            ->runtime(fn (Runtime $r) => $r->workers(min: 4, max: 64))
            ->environment('staging')
            ->environment('production', fn (Environment $e) => $e->runtime(fn (Runtime $r) => $r->workers(8)->maxThreads(32)))
            ->toArray();

        self::assertTrue(ConfigResolver::resolve($raw, 'staging')->runtime->deprecatedWorkers);
        self::assertFalse(ConfigResolver::resolve($raw, 'production')->runtime->deprecatedWorkers);
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
        self::assertSame(8, $config->runtime->workers);
        self::assertSame(64, $config->runtime->maxThreads);
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

        // The environment's frankenphp() replaces the app's build(): selectors resolve per level.
        $production = ConfigResolver::resolve($raw, 'production');
        self::assertNull($production->runtime->build);
        self::assertSame('default', $production->sources['runtime.build']);
        self::assertSame('1.13.0', $production->runtime->frankenphp);
        self::assertSame('environment', $production->sources['runtime.frankenphp']);
    }

    public function test_runtime_selectors_resolve_per_level(): void
    {
        $resolve = fn (callable $app, callable $env) => ConfigResolver::resolve(App::make('shop')->region('us-east-1')
            ->runtime($app)
            ->environment('production', fn (Environment $e) => $e->runtime($env))
            ->toArray(), 'production')->runtime;

        // An environment build() drops the app's php()/frankenphp().
        $pinned = $resolve(fn (Runtime $r) => $r->php('8.5')->frankenphp('1.13'), fn (Runtime $r) => $r->build('php8.5.11-fp1.13.0-r2'));
        self::assertSame(['php8.5.11-fp1.13.0-r2', null, null], [$pinned->build, $pinned->php, $pinned->frankenphp]);
        // An environment php() or frankenphp() drops the app's build(); the app's other selector of that group stays.
        $chosen = $resolve(fn (Runtime $r) => $r->build('1.13.0-r2'), fn (Runtime $r) => $r->php('8.5'));
        self::assertSame([null, '8.5', null], [$chosen->build, $chosen->php, $chosen->frankenphp]);
        $combined = $resolve(fn (Runtime $r) => $r->php('8.5'), fn (Runtime $r) => $r->frankenphp('1.13'));
        self::assertSame([null, '8.5', '1.13'], [$combined->build, $combined->php, $combined->frankenphp]);
        // Same level: both kept, so validation rejects them.
        $same = $resolve(fn (Runtime $r) => $r->threads(4), fn (Runtime $r) => $r->php('8.5')->build('1.13.0-r2'));
        self::assertSame(['1.13.0-r2', '8.5'], [$same->build, $same->php]);
        // An environment binary() drops the app's selectors (they would be ignored), not the other way round.
        $custom = $resolve(fn (Runtime $r) => $r->php('8.5'), fn (Runtime $r) => $r->binary('bin/frankenphp'));
        self::assertSame(['bin/frankenphp', null], [$custom->binary, $custom->php]);
        $kept = $resolve(fn (Runtime $r) => $r->binary('bin/frankenphp'), fn (Runtime $r) => $r->php('8.5'));
        self::assertSame(['bin/frankenphp', '8.5'], [$kept->binary, $kept->php]);
    }

    public function test_php_and_binary_with_a_php_cli_resolve_with_inheritance(): void
    {
        $raw = App::make('shop')->region('us-east-1')
            ->runtime(fn (Runtime $r) => $r->php('8.5')->binary('bin/frankenphp', 'bin/php'))
            ->environment('staging')
            ->environment('production', fn (Environment $e) => $e->runtime(fn (Runtime $r) => $r->binary(frankenphp: 'bin/other')))
            ->toArray();

        $staging = ConfigResolver::resolve($raw, 'staging');
        self::assertSame('8.5', $staging->runtime->php);
        self::assertSame('app', $staging->sources['runtime.php']);
        self::assertSame(['bin/frankenphp', 'bin/php'], [$staging->runtime->binary, $staging->runtime->phpBinary]);

        // binary() sets both files: an environment's binary() without php: has no PHP CLI.
        $production = ConfigResolver::resolve($raw, 'production');
        self::assertSame(['bin/other', null], [$production->runtime->binary, $production->runtime->phpBinary]);
        self::assertSame('environment', $production->sources['runtime.phpBinary']);

        $default = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->environment('production')->toArray(), 'production');
        self::assertNull($default->runtime->php);
        self::assertSame('default', $default->sources['runtime.php']);
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

    public function test_max_instances_resolves_per_level(): void
    {
        $raw = App::make('shop')->region('us-east-1')
            ->maxInstances(10)
            ->environment('staging', fn (Environment $e) => $e->maxInstances(2))
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
            ->console(timeout: 600, memory: 4096)
            ->environment('staging', fn (Environment $e) => $e->deployCommands([]))
            ->environment('production', fn (Environment $e) => $e->deployCommands(['migrate --force', 'config:cache']))
            ->environment('qa')
            ->toArray();

        $production = ConfigResolver::resolve($raw, 'production');
        self::assertSame(['migrate --force', 'config:cache'], $production->deployCommands);
        self::assertSame('environment', $production->sources['deployCommands']);
        self::assertSame(600, $production->consoleTimeout);
        self::assertSame(4096, $production->consoleMemory);
        self::assertTrue($production->consoleEnabled());
        self::assertSame('app', $production->sources['consoleTimeout']);
        self::assertSame('shop-production-console', $production->consoleFunctionName());

        $staging = ConfigResolver::resolve($raw, 'staging');
        self::assertSame([], $staging->deployCommands, 'an environment list replaces the app list, even when empty');

        $qa = ConfigResolver::resolve($raw, 'qa');
        self::assertSame(['migrate --force'], $qa->deployCommands);
        self::assertSame('app', $qa->sources['deployCommands']);

        $defaults = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->environment('qa')->toArray(), 'qa');
        self::assertSame([900, 2048], [$defaults->consoleTimeout, $defaults->consoleMemory]);
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

    public function test_environment_storage_merges_over_app_storage(): void
    {
        $raw = App::make('shop')->region('us-east-1')
            ->storage('files', expire: ['tmp/' => 1])->storage('backups')
            ->environment('production', fn (Environment $e) => $e->storage('files', public: ['public/'])->storage('backups', false)->storage('media'))
            ->environment('staging')
            ->toArray();
        $config = ConfigResolver::resolve($raw, 'production');

        self::assertSame(['files', 'media'], array_map(fn ($s) => $s->name, $config->storage));
        self::assertSame(['public/'], $config->storage[0]->public);
        self::assertSame(['tmp/' => 1], $config->storage[0]->expire);
        self::assertSame([], $config->storage[1]->public);
        self::assertSame('environment', $config->sources['storage']);
        $staging = ConfigResolver::resolve($raw, 'staging');
        self::assertSame(['files', 'backups'], array_map(fn ($s) => $s->name, $staging->storage));
        self::assertSame('app', $staging->sources['storage']);
        self::assertSame('default', ConfigResolver::resolve(App::make('shop')->environment('production')->toArray(), 'production')->sources['storage']);
    }

    public function test_default_storage_is_first_unless_marked(): void
    {
        $first = ConfigResolver::resolve(App::make('shop')->storage('files')->storage('media')->environment('production')->toArray(), 'production');
        self::assertSame('files', $first->defaultStorage()?->name);
        self::assertTrue($first->storage[0]->default);
        self::assertFalse($first->storage[1]->default);

        $marked = ConfigResolver::resolve(App::make('shop')->storage('files')->storage('media', default: true)->environment('production')->toArray(), 'production');
        self::assertSame('media', $marked->defaultStorage()?->name);
        self::assertFalse($marked->storage[0]->default);

        self::assertNull(ConfigResolver::resolve(App::make('shop')->environment('production')->toArray(), 'production')->defaultStorage());
    }

    public function test_no_default_storage_when_every_entry_opts_out(): void
    {
        $resolve = fn (App $app) => ConfigResolver::resolve($app->environment('production')->toArray(), 'production');

        $none = $resolve(App::make('shop')->storage('files', default: false)->storage('media', default: false));
        self::assertNull($none->defaultStorage());
        self::assertCount(2, $none->storage);

        // One entry that says nothing keeps the usual rule: the first is the default.
        $mixed = $resolve(App::make('shop')->storage('files', default: false)->storage('media'));
        self::assertSame('files', $mixed->defaultStorage()?->name);
        $single = $resolve(App::make('shop')->storage('files'));
        self::assertSame('files', $single->defaultStorage()?->name);
    }

    public function test_storage_existing_and_cors(): void
    {
        $config = ConfigResolver::resolve(App::make('shop')->storage('files', existing: 'legacy-bucket', cors: ['*'])->environment('production')->toArray(), 'production');

        self::assertSame('legacy-bucket', $config->storage[0]->existing);
        self::assertSame(['*'], $config->storage[0]->cors);
    }

    public function test_cache_and_failed_jobs_tables_resolve_environment_over_app(): void
    {
        $raw = App::make('shop')->region('us-east-1')->cache()->failedJobsTable(existing: 'jobs')
            ->environment('production', fn (Environment $e) => $e->cache(false))
            ->environment('staging', fn (Environment $e) => $e->cache(existing: 'legacy-cache'))
            ->environment('qa')
            ->toArray();

        $production = ConfigResolver::resolve($raw, 'production');
        self::assertNull($production->cacheTable);
        self::assertSame('environment', $production->sources['cache']);
        self::assertSame('failed-jobs', $production->failedJobsTable?->kind);
        self::assertSame('jobs', $production->failedJobsTable->existing);
        self::assertSame('app', $production->sources['failedJobsTable']);

        $staging = ConfigResolver::resolve($raw, 'staging');
        self::assertSame('cache', $staging->cacheTable?->kind);
        self::assertSame('legacy-cache', $staging->cacheTable->existing);

        $qa = ConfigResolver::resolve($raw, 'qa');
        self::assertSame('cache', $qa->cacheTable?->kind);
        self::assertNull($qa->cacheTable->existing);
        self::assertSame('app', $qa->sources['cache']);

        $none = ConfigResolver::resolve(App::make('shop')->environment('production')->toArray(), 'production');
        self::assertNull($none->cacheTable);
        self::assertNull($none->failedJobsTable);
        self::assertSame('default', $none->sources['cache']);
        self::assertSame('default', $none->sources['failedJobsTable']);
    }
}
