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
        ArcoServiceProvider::$log = null;
        unset(
            $_SERVER['ARCO'], $_SERVER['AWS_ACCESS_KEY_ID'], $_SERVER['AWS_SECRET_ACCESS_KEY'], $_SERVER['AWS_SESSION_TOKEN'],
            $_SERVER['AWS_REGION'], $_SERVER['AWS_DEFAULT_REGION'], $_SERVER['ARCO_STORAGE'], $_SERVER['ARCO_CACHE_TABLE'],
            $_SERVER['ARCO_FAILED_JOBS_TABLE'], $_SERVER['AWS_USE_PATH_STYLE_ENDPOINT'],
        );
    }

    private static function lambdaCredentials(): void
    {
        $_SERVER['ARCO'] = '1';
        $_SERVER['AWS_ACCESS_KEY_ID'] = 'ASIA1';
        $_SERVER['AWS_SECRET_ACCESS_KEY'] = 'secret';
        $_SERVER['AWS_SESSION_TOKEN'] = 'session';
        $_SERVER['AWS_REGION'] = 'sa-east-1';
    }

    /** @param array<string, mixed> $config */
    private static function registered(array $config): Repository
    {
        $app = new Application(sys_get_temp_dir());
        $app->instance('config', new Repository($config));
        (new ArcoServiceProvider($app))->register();
        $repository = $app->make('config');
        self::assertInstanceOf(Repository::class, $repository);
        return $repository;
    }

    public function test_every_storage_becomes_a_named_s3_disk_carrying_the_session_token(): void
    {
        self::lambdaCredentials();
        $_SERVER['ARCO_STORAGE'] = '{"uploads":{"bucket":"shop-prod-uploads","url":"https://shop-prod-uploads.s3.sa-east-1.amazonaws.com"},'
            . '"media":{"bucket":"shop-prod-media","url":"https://shop-prod-media.s3.sa-east-1.amazonaws.com"}}';

        $config = self::registered(['filesystems' => ['disks' => ['local' => ['driver' => 'local']]]]);

        self::assertSame([
            'driver' => 's3',
            'bucket' => 'shop-prod-uploads',
            'region' => 'sa-east-1',
            'url' => 'https://shop-prod-uploads.s3.sa-east-1.amazonaws.com',
            'key' => 'ASIA1',
            'secret' => 'secret',
            'token' => 'session',
        ], $config->get('filesystems.disks.uploads'));
        self::assertSame('shop-prod-media', $config->get('filesystems.disks.media.bucket'));
        self::assertSame('session', $config->get('filesystems.disks.media.token'));
        self::assertSame(['driver' => 'local'], $config->get('filesystems.disks.local'));
    }

    public function test_named_disks_use_path_style_when_arco_says_so(): void
    {
        self::lambdaCredentials();
        $_SERVER['ARCO_STORAGE'] = '{"uploads":{"bucket":"b","url":"http://localhost:4566/b"}}';
        self::assertNull(self::registered([])->get('filesystems.disks.uploads.use_path_style_endpoint'));

        $_SERVER['AWS_USE_PATH_STYLE_ENDPOINT'] = 'true';
        self::assertTrue(self::registered([])->get('filesystems.disks.uploads.use_path_style_endpoint'));
    }

    public function test_the_region_falls_back_to_aws_default_region(): void
    {
        self::lambdaCredentials();
        unset($_SERVER['AWS_REGION']);
        $_SERVER['AWS_DEFAULT_REGION'] = 'us-east-2';
        $_SERVER['ARCO_STORAGE'] = '{"uploads":{"bucket":"b","url":"https://b"}}';

        self::assertSame('us-east-2', self::registered([])->get('filesystems.disks.uploads.region'));
    }

    public function test_an_app_disk_with_the_same_name_is_left_untouched_and_logged(): void
    {
        self::lambdaCredentials();
        $lines = [];
        ArcoServiceProvider::$log = static function (array $line) use (&$lines): void { $lines[] = $line; };
        $_SERVER['ARCO_STORAGE'] = '{"uploads":{"bucket":"shop-prod-uploads","url":"https://u"},"media":{"bucket":"shop-prod-media","url":"https://m"}}';
        $mine = ['driver' => 'local', 'root' => '/tmp/uploads'];

        $config = self::registered(['filesystems' => ['disks' => ['uploads' => $mine]]]);

        self::assertSame($mine, $config->get('filesystems.disks.uploads'));
        self::assertSame('shop-prod-media', $config->get('filesystems.disks.media.bucket'));
        self::assertSame([['arco' => 'disk_skipped', 'disk' => 'uploads', 'bucket' => 'shop-prod-uploads', 'reason' => 'the app defines this disk']], $lines);
    }

    public function test_an_arco_cache_table_gets_its_key_value_and_expires_at_attributes(): void
    {
        self::lambdaCredentials();
        $_SERVER['ARCO_CACHE_TABLE'] = 'shop-prod-cache';

        $config = self::registered(['cache' => ['stores' => ['dynamodb' => [
            'driver' => 'dynamodb', 'key' => 'ASIA1', 'secret' => 'secret', 'region' => 'sa-east-1',
            'table' => 'cache', 'attributes' => ['key' => 'id', 'value' => 'value', 'expiration' => 'ttl'],
        ]]]]);

        self::assertSame('shop-prod-cache', $config->get('cache.stores.dynamodb.table'));
        self::assertSame(['key' => 'key', 'value' => 'value', 'expiration' => 'expires_at'], $config->get('cache.stores.dynamodb.attributes'));
        self::assertSame('session', $config->get('cache.stores.dynamodb.token'));
        self::assertSame('dynamodb', $config->get('cache.stores.dynamodb.driver'));
    }

    public function test_an_arco_cache_table_gets_a_store_when_the_app_has_none(): void
    {
        self::lambdaCredentials();
        $_SERVER['ARCO_CACHE_TABLE'] = 'shop-prod-cache';

        self::assertSame([
            'driver' => 'dynamodb', 'region' => 'sa-east-1', 'key' => 'ASIA1', 'secret' => 'secret', 'token' => 'session',
            'table' => 'shop-prod-cache', 'attributes' => ['key' => 'key', 'value' => 'value', 'expiration' => 'expires_at'],
        ], self::registered(['cache' => ['default' => 'file']])->get('cache.stores.dynamodb'));
    }

    public function test_a_cache_table_arco_did_not_create_keeps_the_apps_attributes(): void
    {
        self::lambdaCredentials();
        $store = ['driver' => 'dynamodb', 'table' => 'their-cache', 'attributes' => ['key' => 'id', 'value' => 'value', 'expiration' => 'ttl']];

        self::assertSame($store, self::registered(['cache' => ['stores' => ['dynamodb' => $store]]])->get('cache.stores.dynamodb'));
    }

    public function test_the_failed_jobs_table_wires_the_dynamodb_failed_job_provider(): void
    {
        self::lambdaCredentials();
        $_SERVER['ARCO_FAILED_JOBS_TABLE'] = 'shop-prod-failed-jobs';

        $config = self::registered(['queue' => ['failed' => ['driver' => 'database-uuids', 'database' => 'sqlite', 'table' => 'failed_jobs']]]);

        self::assertSame([
            'driver' => 'dynamodb',
            'database' => 'sqlite',
            'table' => 'shop-prod-failed-jobs',
            'region' => 'sa-east-1',
            'key' => 'ASIA1',
            'secret' => 'secret',
            'token' => 'session',
        ], $config->get('queue.failed'));
    }

    public function test_nothing_changes_without_the_variables_or_outside_arco(): void
    {
        $app = [
            'filesystems' => ['disks' => ['local' => ['driver' => 'local']]],
            'cache' => ['stores' => ['dynamodb' => ['driver' => 'dynamodb', 'table' => 'cache', 'attributes' => ['key' => 'id']]]],
            'queue' => ['failed' => ['driver' => 'database-uuids', 'table' => 'failed_jobs']],
        ];
        self::lambdaCredentials();
        self::assertSame($app, self::registered($app)->all());

        unset($_SERVER['ARCO']);
        $_SERVER['ARCO_STORAGE'] = '{"uploads":{"bucket":"b","url":"https://b"}}';
        $_SERVER['ARCO_CACHE_TABLE'] = 'shop-prod-cache';
        $_SERVER['ARCO_FAILED_JOBS_TABLE'] = 'shop-prod-failed-jobs';
        self::assertSame($app, self::registered($app)->all());
    }

    public function test_malformed_storage_json_is_ignored(): void
    {
        self::lambdaCredentials();
        $_SERVER['ARCO_STORAGE'] = '{not json';

        self::assertNull(self::registered([])->get('filesystems.disks'));
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
