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
}
