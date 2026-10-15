<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Config;

use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\Environment;
use Multiapi\Arco\Config\Runtime;
use PHPUnit\Framework\TestCase;

final class BuildersTest extends TestCase
{
    public function test_serializes_only_explicit_values(): void
    {
        $app = App::make('shop')
            ->region('us-east-1')
            ->concurrency(48)
            ->runtime(fn (Runtime $r) => $r->workers(min: 4, max: 32)->phpIni(['memory_limit' => '512M']))
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
            'runtime' => ['workers' => ['min' => 4, 'max' => 32], 'phpIni' => ['memory_limit' => '512M']],
            'permissions' => [],
            'domains' => null,
            'environments' => [
                'staging' => ['settings' => [], 'variables' => [], 'runtime' => [], 'permissions' => [], 'domains' => null],
                'production' => [
                    'settings' => ['account' => '222222222222', 'profile' => 'acme-prod', 'timeout' => 60],
                    'variables' => ['APP_KEY' => 'arco-ssm:/shop/production/app-key'],
                    'runtime' => ['threads' => 16],
                    'permissions' => [],
                    'domains' => null,
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
}
