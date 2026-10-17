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

final class ArcoServiceProvider extends ServiceProvider
{
    private const JSON_FORMATTER = 'Monolog\\Formatter\\JsonFormatter';

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
