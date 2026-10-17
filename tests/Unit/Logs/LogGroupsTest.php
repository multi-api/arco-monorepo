<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Logs;

use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Logs\LogGroups;
use PHPUnit\Framework\TestCase;

final class LogGroupsTest extends TestCase
{
    public function test_the_arn_partition_follows_the_region(): void
    {
        self::assertSame('arn:aws:logs:eu-west-1:111111111111:log-group:/aws/lambda/x', LogGroups::arn('eu-west-1', '111111111111', '/aws/lambda/x'));
        self::assertSame('arn:aws-cn:logs:cn-north-1:111111111111:log-group:/aws/lambda/x', LogGroups::arn('cn-north-1', '111111111111', '/aws/lambda/x'));
        self::assertSame('arn:aws-us-gov:logs:us-gov-west-1:111111111111:log-group:/aws/lambda/x', LogGroups::arn('us-gov-west-1', '111111111111', '/aws/lambda/x'));
    }

    private function config(bool $queues): \Multiapi\Arco\Config\AppConfig
    {
        $app = App::make('shop')->region('us-east-1');
        return ConfigResolver::resolve(($queues ? $app->queue('jobs')->queue('emails') : $app)->environment('production')->toArray(), 'production');
    }

    public function test_worker_groups_join_queue_queues_and_all(): void
    {
        $config = $this->config(true);

        self::assertSame(['/aws/lambda/shop-production-queue-emails'], LogGroups::for($config, 'queue', 'emails'));
        self::assertSame(['/aws/lambda/shop-production-queue-jobs', '/aws/lambda/shop-production-queue-emails'], LogGroups::for($config, 'queues'));
        self::assertSame([
            '/aws/lambda/web/shop-production',
            '/aws/lambda/web/shop-production.local',
            '/aws/lambda/shop-production-console',
            '/aws/lambda/shop-production-queue-jobs',
            '/aws/lambda/shop-production-queue-emails',
        ], LogGroups::for($config, 'all'));
        $this->expectException(\InvalidArgumentException::class);
        LogGroups::for($config, 'queue', 'nope');
    }

    public function test_apps_without_queues_keep_their_groups(): void
    {
        $config = $this->config(false);

        self::assertSame(['/aws/lambda/web/shop-production', '/aws/lambda/web/shop-production.local', '/aws/lambda/shop-production-console'], LogGroups::for($config, 'all'));
        self::assertSame([], LogGroups::for($config, 'queues'));
    }
}
