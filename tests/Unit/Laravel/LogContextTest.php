<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Laravel;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Log\LogManager;
use Multiapi\Arco\Laravel\Log\LogContext;
use PHPUnit\Framework\TestCase;

final class LogContextTest extends TestCase
{
    public function test_the_trace_id_is_the_root_of_an_x_ray_header(): void
    {
        self::assertSame('1-6543210f-0123456789abcdef01234567', LogContext::traceId('Root=1-6543210f-0123456789abcdef01234567;Parent=0123456789abcdef;Sampled=1'));
        self::assertSame('1-6543210f-0123456789abcdef01234567', LogContext::traceId('Self=1-aaaaaaaa-bbbbbbbbbbbbbbbbbbbbbbbb; root=1-6543210f-0123456789abcdef01234567'));
        self::assertSame('1-6543210f-0123456789abcdef01234567', LogContext::traceId('Root=1-6543210f-0123456789abcdef01234567'));
        self::assertNull(LogContext::traceId(null));
        self::assertNull(LogContext::traceId(''));
        self::assertNull(LogContext::traceId('Parent=0123456789abcdef;Sampled=0'));
        self::assertNull(LogContext::traceId('Root=1-abc'));
        self::assertNull(LogContext::traceId('Root=1-6543210F-0123456789ABCDEF01234567'));
    }

    public function test_a_traceparent_becomes_the_same_x_ray_trace_id(): void
    {
        self::assertSame('1-6543210f-0123456789abcdef01234567', LogContext::traceIdFromTraceparent('00-6543210f0123456789abcdef01234567-0123456789abcdef-01'));
        self::assertNull(LogContext::traceIdFromTraceparent(null));
        self::assertNull(LogContext::traceIdFromTraceparent('00-6543210f-01'));
    }

    public function test_share_adds_and_replace_starts_over(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->instance('config', new Repository(['logging' => ['default' => 'null', 'channels' => ['null' => ['driver' => 'monolog', 'handler' => \Monolog\Handler\NullHandler::class]]]]));
        $log = new LogManager($app);
        $app->instance('log', $log);

        LogContext::share($app, ['requestId' => 'a', 'traceId' => null]);
        LogContext::share($app, ['job' => 'App\\Jobs\\Send']);
        self::assertSame(['requestId' => 'a', 'job' => 'App\\Jobs\\Send'], $log->sharedContext());

        LogContext::replace($app, ['requestId' => 'b']);
        self::assertSame(['requestId' => 'b'], $log->sharedContext());
    }

    public function test_without_a_logger_nothing_happens(): void
    {
        $app = new Container();
        LogContext::replace($app, ['requestId' => 'a']);
        self::assertFalse($app->bound('log'));
    }
}
