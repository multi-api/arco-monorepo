<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Config;

use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\Environment;
use Multiapi\Arco\Config\Runtime;
use PHPUnit\Framework\TestCase;

final class BuildersTest extends TestCase
{
    public function test_runtime_php_frankenphp_and_binary_serialize(): void
    {
        self::assertSame(['php' => '8.5', 'frankenphp' => '1.13'], (new Runtime())->php('8.5')->frankenphp('1.13')->toArray());
        self::assertSame(['build' => '1.13.0-r2'], (new Runtime())->build('1.13.0-r2')->toArray());
        self::assertSame(['binary' => 'bin/frankenphp', 'phpBinary' => 'bin/php'], (new Runtime())->binary(frankenphp: 'bin/frankenphp', php: 'bin/php')->toArray());
        self::assertSame(['binary' => 'bin/frankenphp', 'phpBinary' => null], (new Runtime())->binary('bin/frankenphp')->toArray());
    }

    public function test_serializes_only_explicit_values(): void
    {
        $app = App::make('shop')
            ->region('us-east-1')
            ->concurrency(48)
            ->runtime(fn (Runtime $r) => $r->workers(4)->maxThreads(32)->phpIni(['memory_limit' => '512M']))
            ->variables(['APP_ENV' => 'production'])
            ->exclude(['tests/**'])
            ->environment('staging')
            ->environment('production', fn (Environment $env) => $env
                ->account('222222222222')
                ->profile('acme-prod')
                ->timeout(60)
                ->variables(['APP_KEY' => 'arco-ssm:/shop/production/app-key'])
                ->runtime(fn (Runtime $r) => $r->threads(16)));

        self::assertSame([
            'name' => 'shop',
            'region' => 'us-east-1',
            'exclude' => ['tests/**'],
            'include' => [],
            'settings' => ['concurrency' => 48],
            'variables' => ['APP_ENV' => 'production'],
            'runtime' => ['workers' => 4, 'maxThreads' => 32, 'phpIni' => ['memory_limit' => '512M']],
            'permissions' => [],
            'domains' => null,
            'deployCommands' => null,
            'queues' => [],
            'storage' => [],
            'environments' => [
                'staging' => ['settings' => [], 'variables' => [], 'runtime' => [], 'permissions' => [], 'domains' => null, 'deployCommands' => null, 'queues' => [], 'storage' => []],
                'production' => [
                    'settings' => ['account' => '222222222222', 'profile' => 'acme-prod', 'timeout' => 60],
                    'variables' => ['APP_KEY' => 'arco-ssm:/shop/production/app-key'],
                    'runtime' => ['threads' => 16],
                    'permissions' => [],
                    'domains' => null,
                    'deployCommands' => null,
                    'queues' => [],
                    'storage' => [],
                ],
            ],
        ], $app->toArray());
    }

    public function test_variables_and_php_ini_merge_across_calls(): void
    {
        $app = App::make('a')
            ->variables(['A' => '1', 'B' => '2'])
            ->variables(['B' => '3'])
            ->runtime(fn (Runtime $r) => $r->phpIni(['x' => 1]))
            ->runtime(fn (Runtime $r) => $r->phpIni(['y' => true]));

        $array = $app->toArray();
        self::assertSame(['A' => '1', 'B' => '3'], $array['variables']);
        self::assertSame(['phpIni' => ['x' => 1, 'y' => true]], $array['runtime']);
    }

    public function test_environment_can_be_configured_twice(): void
    {
        $app = App::make('a')
            ->environment('prod', fn (Environment $e) => $e->timeout(10))
            ->environment('prod', fn (Environment $e) => $e->concurrency(5));

        self::assertSame(['timeout' => 10, 'concurrency' => 5], $app->toArray()['environments']['prod']['settings']);
    }

    public function test_domain_dns_and_certificate(): void
    {
        $app = App::make('a')
            ->domain('a.example.com')
            ->environment('prod', fn (Environment $e) => $e
                ->domain(['b.example.com', 'c.example.com'])
                ->domain('d.example.com')
                ->dns('common')
                ->certificate('arn:aws:acm:us-east-1:111111111111:certificate/abc'))
            ->environment('qa', fn (Environment $e) => $e->dns(false));

        $array = $app->toArray();
        self::assertSame(['a.example.com'], $array['domains']);
        self::assertSame(['b.example.com', 'c.example.com', 'd.example.com'], $array['environments']['prod']['domains']);
        self::assertSame(['dns' => 'common', 'certificate' => 'arn:aws:acm:us-east-1:111111111111:certificate/abc'], $array['environments']['prod']['settings']);
        self::assertSame(['dns' => false], $array['environments']['qa']['settings']);
    }

    public function test_console_scheduler_and_retention_setters(): void
    {
        self::assertSame(
            ['console' => true, 'scheduler' => 5, 'logRetention' => 30],
            App::make('a')->console()->scheduler(5)->logRetention(30)->toArray()['settings'],
        );
        self::assertSame(
            ['console' => false, 'scheduler' => false, 'logRetention' => false],
            App::make('a')->console(false)->scheduler(false)->logRetention(false)->toArray()['settings'],
        );
        self::assertSame(['scheduler' => 1], App::make('a')->scheduler()->toArray()['settings']);
    }

    public function test_queues_store_only_explicit_options(): void
    {
        $app = App::make('a')->queue('jobs')->queue('emails', timeout: 120)->queue('x', false);

        self::assertSame(['jobs' => [], 'emails' => ['timeout' => 120], 'x' => false], $app->toArray()['queues']);
    }

    public function test_queue_options_accumulate_per_name(): void
    {
        $app = App::make('a')->queue('emails', timeout: 120, fifo: true, default: true, existing: 'q')->queue('emails', memory: 2048);

        self::assertSame(
            ['emails' => ['timeout' => 120, 'fifo' => true, 'existing' => 'q', 'default' => true, 'memory' => 2048]],
            $app->toArray()['queues'],
        );
    }

    public function test_environment_queues_are_serialized(): void
    {
        $app = App::make('a')->environment('prod', fn (Environment $e) => $e->queue('emails', memory: 2048));

        self::assertSame(['emails' => ['memory' => 2048]], $app->toArray()['environments']['prod']['queues']);
    }

    public function test_storage_with_every_option(): void
    {
        $app = App::make('a')
            ->storage('uploads', public: ['public/'], expire: ['tmp/' => 1], cors: ['https://example.com'], existing: 'b', default: true)
            ->storage('files')
            ->storage('old', false);

        self::assertSame([
            'uploads' => ['public' => ['public/'], 'expire' => ['tmp/' => 1], 'cors' => ['https://example.com'], 'existing' => 'b', 'default' => true],
            'files' => [],
            'old' => false,
        ], $app->toArray()['storage']);
    }

    public function test_storage_options_accumulate_per_name_and_serialize_per_environment(): void
    {
        $app = App::make('a')->storage('files', public: ['p/'])->storage('files', cors: ['*'])
            ->environment('prod', fn (Environment $e) => $e->storage('files', false));

        self::assertSame(['files' => ['public' => ['p/'], 'cors' => ['*']]], $app->toArray()['storage']);
        self::assertSame(['files' => false], $app->toArray()['environments']['prod']['storage']);
    }

    public function test_cache_and_failed_jobs_table_setters(): void
    {
        self::assertSame(['cache' => true, 'failedJobsTable' => true], App::make('a')->cache()->failedJobsTable()->toArray()['settings']);
        self::assertSame(
            ['cache' => ['existing' => 'x'], 'failedJobsTable' => ['existing' => 'y']],
            App::make('a')->cache(existing: 'x')->failedJobsTable(existing: 'y')->toArray()['settings'],
        );
        self::assertSame(['cache' => false, 'failedJobsTable' => false], App::make('a')->cache(false)->failedJobsTable(false)->toArray()['settings']);
    }
}
