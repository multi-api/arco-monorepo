<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Laravel;

use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Http\Middleware\TrustProxies;
use Multiapi\Arco\Laravel\ArcoServiceProvider;
use Multiapi\Arco\Laravel\Http\ShareRequestId;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ArcoServiceProviderTest extends TestCase
{
    protected function tearDown(): void
    {
        TrustProxies::flushState();
        unset($_SERVER['ARCO'], $_SERVER['AWS_ACCESS_KEY_ID'], $_SERVER['AWS_SESSION_TOKEN']);
    }

    private static function trusted(): mixed
    {
        return (new ReflectionClass(TrustProxies::class))->getStaticPropertyValue('alwaysTrustProxies');
    }

    public function test_trusts_proxies_only_on_arco(): void
    {
        (new ArcoServiceProvider(new Application(sys_get_temp_dir())))->register();
        self::assertNull(self::trusted());

        $_SERVER['ARCO'] = '1';
        (new ArcoServiceProvider(new Application(sys_get_temp_dir())))->register();
        self::assertSame('*', self::trusted());
    }

    public function test_adds_the_session_token_to_aws_clients_configured_with_the_function_key(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->instance('config', new Repository([
            'cache' => ['stores' => ['dynamodb' => ['driver' => 'dynamodb', 'key' => 'AKIA1', 'secret' => 's'], 'file' => ['driver' => 'file']]],
            'queue' => ['connections' => ['sqs' => ['driver' => 'sqs', 'key' => 'AKIA1', 'secret' => 's']]],
            'filesystems' => ['disks' => [
                's3' => ['driver' => 's3', 'key' => 'AKIA1', 'secret' => 's'],
                'other' => ['driver' => 's3', 'key' => 'AKIA-OTHER', 'secret' => 'x'],
                'pinned' => ['driver' => 's3', 'key' => 'AKIA1', 'secret' => 's', 'token' => 'mine'],
            ]],
            'services' => ['ses' => ['key' => 'AKIA1', 'secret' => 's'], 'textract' => ['key' => 'AKIA1', 'secret' => 's', 'region' => 'us-east-1']],
            'queue_failed_like' => ['failed' => ['driver' => 'dynamodb', 'key' => 'AKIA1', 'secret' => 's']],
        ]));
        $_SERVER['ARCO'] = '1';
        $_SERVER['AWS_ACCESS_KEY_ID'] = 'AKIA1';
        $_SERVER['AWS_SESSION_TOKEN'] = 'session';

        (new ArcoServiceProvider($app))->register();

        $config = $app->make('config');
        self::assertSame('session', $config->get('cache.stores.dynamodb.token'));
        self::assertSame('session', $config->get('queue.connections.sqs.token'));
        self::assertSame('session', $config->get('filesystems.disks.s3.token'));
        self::assertSame('session', $config->get('services.ses.token'));
        self::assertSame('session', $config->get('services.textract.token'));
        self::assertSame('session', $config->get('queue_failed_like.failed.token'));
        self::assertNull($config->get('filesystems.disks.other.token'));
        self::assertSame('mine', $config->get('filesystems.disks.pinned.token'));
        self::assertNull($config->get('cache.stores.file.token'));
    }

    public function test_leaves_config_alone_outside_arco(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->instance('config', new Repository(['cache' => ['stores' => ['dynamodb' => ['driver' => 'dynamodb', 'key' => 'AKIA1']]]]));
        $_SERVER['AWS_ACCESS_KEY_ID'] = 'AKIA1';
        $_SERVER['AWS_SESSION_TOKEN'] = 'session';

        (new ArcoServiceProvider($app))->register();

        self::assertNull($app->make('config')->get('cache.stores.dynamodb.token'));
    }

    public function test_on_arco_logs_go_to_stderr_as_json_and_sessions_leave_files(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->instance('config', new Repository([
            'session' => ['driver' => 'file'],
            'logging' => ['default' => 'stack', 'channels' => [
                'stderr' => ['driver' => 'monolog', 'formatter' => null],
                'emergency' => ['path' => '/var/task/storage/logs/laravel.log'],
            ]],
        ]));
        $_SERVER['ARCO'] = '1';

        (new ArcoServiceProvider($app))->register();

        $config = $app->make('config');
        self::assertSame('cookie', $config->get('session.driver'));
        self::assertSame('stderr', $config->get('logging.default'));
        self::assertSame('Monolog\\Formatter\\JsonFormatter', $config->get('logging.channels.stderr.formatter'));
        self::assertSame(['includeStacktraces' => true], $config->get('logging.channels.stderr.formatter_with'));
        self::assertSame('monolog', $config->get('logging.channels.emergency.driver'));
    }

    public function test_keeps_logging_and_session_choices_the_app_made(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->instance('config', new Repository([
            'session' => ['driver' => 'database'],
            'logging' => ['default' => 'lambda', 'channels' => ['stderr' => ['driver' => 'monolog', 'formatter' => 'App\\MyFormatter']]],
        ]));
        $_SERVER['ARCO'] = '1';

        (new ArcoServiceProvider($app))->register();

        $config = $app->make('config');
        self::assertSame('database', $config->get('session.driver'));
        self::assertSame('lambda', $config->get('logging.default'));
        self::assertSame('App\\MyFormatter', $config->get('logging.channels.stderr.formatter'));
    }

    public function test_request_id_prefers_the_callers_header_then_aws_ids(): void
    {
        $request = Request::create('/');
        self::assertNull(ShareRequestId::requestId($request));

        $request->headers->set('X-Amzn-Trace-Id', 'Root=1-abc');
        self::assertSame('Root=1-abc', ShareRequestId::requestId($request));

        $request->headers->set('X-Request-ID', 'mine');
        self::assertSame('mine', ShareRequestId::requestId($request));
    }

    public function test_request_id_is_shared_with_the_log_context(): void
    {
        $logger = new class extends \Psr\Log\AbstractLogger {
            /** @var array<string, mixed> */
            public array $context = [];
            public function shareContext(array $context): void { $this->context = $context; }
            public function log($level, \Stringable|string $message, array $context = []): void {}
        };
        $request = Request::create('/');
        $request->headers->set('X-Amzn-RequestId', 'req-1');

        (new ShareRequestId($logger))->handle($request, fn () => 'ok');

        self::assertSame(['requestId' => 'req-1'], $logger->context);
    }
}
