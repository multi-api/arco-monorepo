<?php declare(strict_types=1);

namespace Multiapi\Arco\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Illuminate\Queue\QueueManager;
use Multiapi\Arco\Laravel\Console\ScheduleIntervalsCommand;
use Multiapi\Arco\Laravel\Console\TinkerCommand;
use Multiapi\Arco\Laravel\Console\WorkCommand;
use Multiapi\Arco\Laravel\Http\ShareRequestId;
use Multiapi\Arco\Laravel\Queue\ArcoSqsConnector;
use Multiapi\Arco\Laravel\Queue\ArcoSqsQueue;
use Multiapi\Arco\Laravel\Queue\LambdaWorker;

final class ArcoServiceProvider extends ServiceProvider
{
    private const JSON_FORMATTER = 'Monolog\\Formatter\\JsonFormatter';
    /**
     * The attributes of a cache table Arco creates. Its TTL names EXPIRES_AT (ResourcesStack::TTL_ATTRIBUTE),
     * which is also what Laravel's DynamoDB failed-job provider writes.
     */
    public const CACHE_ATTRIBUTES = ['key' => 'key', 'value' => 'value', 'expiration' => self::EXPIRES_AT];
    public const EXPIRES_AT = 'expires_at';

    /**
     * @internal Where log lines go (one JSON object per line on stderr by default); tests replace it.
     * @var (\Closure(array<string, mixed>): void)|null
     */
    public static ?\Closure $log = null;

    public function register(): void
    {
        // Lambda Web Functions sits behind AWS's HTTPS front end, which sets X-Forwarded-* headers.
        if (($_SERVER['ARCO'] ?? getenv('ARCO')) !== '1') {
            return;
        }
        TrustProxies::at('*');
        TrustProxies::withHeaders(
            Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO,
        );

        // Before addSessionToken(), which would also add the token to these entries.
        $this->registerStorageDisks();
        $this->configureCacheTable();
        $this->configureFailedJobsTable();
        $this->addSessionToken();
        $this->fixDefaultConfiguration();
        $this->registerQueueConnector();
    }

    public function boot(): void
    {
        if (($_SERVER['ARCO'] ?? getenv('ARCO')) !== '1') {
            return;
        }
        if ($this->app->runningInConsole()) {
            // Hidden: arco:tinker for `arco tinker` through the console function; arco:schedule-intervals
            // for `arco scheduler:set` and `arco deploy`, which run it locally with ARCO=1; arco:work for
            // the queue worker functions.
            $this->commands([TinkerCommand::class, ScheduleIntervalsCommand::class, WorkCommand::class]);
        }
        $kernel = $this->app->bound(Kernel::class) ? $this->app->make(Kernel::class) : null;
        if (is_object($kernel) && method_exists($kernel, 'prependMiddleware')) {
            $kernel->prependMiddleware(ShareRequestId::class);
        }
    }

    /**
     * Arco deploys each queue under a physical name (<app>-<env>-emails) and lists them in ARCO_QUEUES:
     * the `sqs` connector maps the logical names, so `->onQueue('emails')` reaches the right queue.
     */
    private function registerQueueConnector(): void
    {
        $queues = ArcoSqsQueue::queuesFromEnv(Env::get('ARCO_QUEUES'));
        if ($queues === []) {
            return;
        }
        $add = static function (mixed $manager) use ($queues): void {
            if ($manager instanceof QueueManager) {
                $manager->addConnector('sqs', static fn () => new ArcoSqsConnector($queues));
            }
        };
        if ($this->app->resolved('queue')) {
            $add($this->app->make('queue'));
        }
        $this->app->afterResolving('queue', $add);
    }

    /**
     * Every storage of arco.php becomes a named s3 disk (`Storage::disk('uploads')`), from ARCO_STORAGE
     * (name => bucket and url), with the function's credentials and session token. A disk the app
     * defines under the same name is the app's choice: it stays, and a log line says so.
     */
    private function registerStorageDisks(): void
    {
        $storage = json_decode(Env::get('ARCO_STORAGE') ?? '', true);
        $config = $this->config();
        if (! is_array($storage) || $config === null) {
            return;
        }
        foreach ($storage as $name => $entry) {
            if (! is_string($name) || $name === '' || str_contains($name, '.') || ! is_array($entry) || ! is_string($entry['bucket'] ?? null)) {
                continue;
            }
            if ($config->has("filesystems.disks.$name")) {
                self::log(['arco' => 'disk_skipped', 'disk' => $name, 'bucket' => $entry['bucket'], 'reason' => 'the app defines this disk']);
                continue;
            }
            $config->set("filesystems.disks.$name", [
                'driver' => 's3',
                'bucket' => $entry['bucket'],
                'region' => self::region(),
                'url' => is_string($entry['url'] ?? null) ? $entry['url'] : null,
                ...self::credentials(),
                // A local emulator (AWS_ENDPOINT_URL) cannot serve virtual-hosted bucket names.
                ...(Env::get('AWS_USE_PATH_STYLE_ENDPOINT') === 'true' ? ['use_path_style_endpoint' => true] : []),
            ]);
        }
    }

    /**
     * A cache table Arco creates (ARCO_CACHE_TABLE) has the key `key` and TTL on `expires_at`: the
     * dynamodb store is pointed at it with those attributes, whatever the app's config/cache.php says
     * (Bref's, for one, uses id/ttl). A table the app brings (`existing:`) keeps the app's attributes.
     */
    private function configureCacheTable(): void
    {
        $table = Env::get('ARCO_CACHE_TABLE');
        $config = $this->config();
        if ($table === null || $config === null) {
            return;
        }
        if (! is_array($config->get('cache.stores.dynamodb'))) {
            // An app that removed the stock store: CACHE_STORE=dynamodb still needs one.
            $config->set('cache.stores.dynamodb', ['driver' => 'dynamodb', 'region' => self::region(), ...self::credentials()]);
        }
        $config->set('cache.stores.dynamodb.table', $table);
        $config->set('cache.stores.dynamodb.attributes', self::CACHE_ATTRIBUTES);
    }

    /**
     * Failed jobs go to the DynamoDB table Arco created (ARCO_FAILED_JOBS_TABLE): stock config/queue.php
     * does not read that table's name from the environment. Other entries of queue.failed stay.
     */
    private function configureFailedJobsTable(): void
    {
        $table = Env::get('ARCO_FAILED_JOBS_TABLE');
        $config = $this->config();
        if ($table === null || $config === null) {
            return;
        }
        $failed = $config->get('queue.failed');
        $config->set('queue.failed', [
            ...(is_array($failed) ? $failed : []),
            'driver' => 'dynamodb',
            'table' => $table,
            'region' => self::region(),
            ...self::credentials(),
        ]);
    }

    private static function region(): ?string
    {
        return Env::get('AWS_REGION') ?? Env::get('AWS_DEFAULT_REGION');
    }

    /** @return array{key: ?string, secret: ?string, token: ?string} the function's temporary credentials */
    private static function credentials(): array
    {
        return [
            'key' => Env::get('AWS_ACCESS_KEY_ID'),
            'secret' => Env::get('AWS_SECRET_ACCESS_KEY'),
            'token' => Env::get('AWS_SESSION_TOKEN'),
        ];
    }

    private function config(): ?Repository
    {
        if (! $this->app->bound('config')) {
            return null;
        }
        $config = $this->app->make('config');
        return $config instanceof Repository ? $config : null;
    }

    /** @param array<string, mixed> $line */
    private static function log(array $line): void
    {
        (self::$log ?? LambdaWorker::stderr(...))($line);
    }

    /**
     * Lambda keeps only stdout/stderr, and its filesystem is read-only except /tmp: send logs to
     * stderr as one JSON object per line (searchable in CloudWatch Logs Insights) and keep
     * sessions out of files. Only stock values are changed; anything the app chose stays.
     */
    private function fixDefaultConfiguration(): void
    {
        if (! $this->app->bound('config')) {
            return;
        }
        $config = $this->app->make('config');
        if (! $config instanceof Repository) {
            return;
        }
        if ($config->get('session.driver') === 'file') {
            $config->set('session.driver', 'cookie');
        }
        if ($config->get('logging.default') === 'stack') {
            $config->set('logging.default', 'stderr');
        }
        if (is_array($config->get('logging.channels.stderr')) && $config->get('logging.channels.stderr.formatter') === null) {
            $config->set('logging.channels.stderr.formatter', self::JSON_FORMATTER);
            $config->set('logging.channels.stderr.formatter_with', ['includeStacktraces' => true]);
        }
        $emergency = $config->get('logging.channels.emergency');
        if (is_array($emergency) && isset($emergency['path']) && is_array($config->get('logging.channels.stderr'))) {
            // The default emergency log is a file under storage/, which nobody reads on Lambda.
            $config->set('logging.channels.emergency', $config->get('logging.channels.stderr'));
        }
    }

    /**
     * Lambda hands the function temporary credentials (key, secret and session token). Laravel's
     * stock config passes only key and secret to AWS clients (DynamoDB, SQS, S3, SES, failed jobs,
     * or any service the app configures the same way), which AWS rejects with "The security token
     * included in the request is invalid". Anywhere in the config where an entry uses the
     * function's own key without a token, add the token.
     */
    private function addSessionToken(): void
    {
        $key = Env::get('AWS_ACCESS_KEY_ID');
        $token = Env::get('AWS_SESSION_TOKEN');
        if ($key === null || $token === null || ! $this->app->bound('config')) {
            return;
        }
        $config = $this->app->make('config');
        if (! $config instanceof Repository) {
            return;
        }
        foreach ($config->all() as $name => $value) {
            if (is_array($value)) {
                $this->patch($config, (string) $name, $value, $key, $token, 0);
            }
        }
    }

    /** @param array<mixed> $entry */
    private function patch(Repository $config, string $path, array $entry, string $key, string $token, int $depth): void
    {
        if (($entry['key'] ?? null) === $key && empty($entry['token'])) {
            $config->set("$path.token", $token);
        }
        if ($depth >= 6) {
            return;
        }
        foreach ($entry as $name => $value) {
            if (is_array($value) && ! str_contains((string) $name, '.')) {
                $this->patch($config, "$path.$name", $value, $key, $token, $depth + 1);
            }
        }
    }
}
