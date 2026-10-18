<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy;

use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Config\Environment;
use Multiapi\Arco\Deploy\PackageRequirements;
use PHPUnit\Framework\TestCase;

final class PackageRequirementsTest extends TestCase
{
    /** @return array<string, list<string>> feature => requirements */
    private static function requirements(App $app): array
    {
        $config = ConfigResolver::resolve($app->region('us-east-1')->environment('production', fn (Environment $e) => $e)->toArray(), 'production');
        return array_map(static fn (array $list): array => array_column($list, 0), PackageRequirements::forConfig($config));
    }

    public function test_features(): void
    {
        self::assertSame(['queue()' => ['multiapi/arco-laravel:^0.5', 'aws/aws-sdk-php']], self::requirements(App::make('shop')->queue('jobs')));
        self::assertSame(
            ['storage()' => ['league/flysystem-aws-s3-v3:^3.0', 'aws/aws-sdk-php', 'multiapi/arco-laravel:^0.6']],
            self::requirements(App::make('shop')->storage('files')),
        );
        self::assertSame(['cache()' => ['aws/aws-sdk-php']], self::requirements(App::make('shop')->cache()));
        self::assertSame(
            ['failedJobsTable()' => ['aws/aws-sdk-php', 'multiapi/arco-laravel:^0.6']],
            self::requirements(App::make('shop')->failedJobsTable()),
        );
        self::assertSame([], self::requirements(App::make('shop')));
    }

    public function test_paths_are_relative_to_the_app_root(): void
    {
        $config = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->queue('jobs')->environment('production', fn (Environment $e) => $e)->toArray(), 'production');
        foreach (PackageRequirements::forConfig($config) as $requirements) {
            foreach ($requirements as [, $path]) {
                self::assertStringStartsWith('vendor/', $path);
            }
        }
    }
}
