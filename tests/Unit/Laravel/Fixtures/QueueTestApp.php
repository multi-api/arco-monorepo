<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Laravel\Fixtures;

use Aws\CommandInterface;
use Aws\Result;
use Aws\Sqs\Exception\SqsException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Bus\BusServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Application;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\QueueServiceProvider;
use Illuminate\Support\Facades\Facade;
use Psr\Http\Message\RequestInterface;

/**
 * A hand-built Laravel application with the queue, the bus and arco-laravel's provider, whose SQS
 * client never reaches the network: every call is recorded in $calls and answered locally.
 */
final class QueueTestApp
{
    public const PREFIX = 'https://sqs.us-east-1.amazonaws.com/123456789012';
    public const DEFAULT_URL = self::PREFIX . '/shop-production-default';
    public const EMAILS_URL = self::PREFIX . '/shop-production-emails';

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $calls = [];
    public bool $sqsFails = false;
    public readonly Application $app;
    public readonly RecordingExceptionHandler $exceptions;
    public readonly RecordingFailer $failer;

    /** The environment arco deploys on a worker function. */
    public static function env(): array
    {
        return [
            'ARCO' => '1',
            'ARCO_QUEUES' => (string) json_encode(['default' => self::DEFAULT_URL, 'emails' => self::EMAILS_URL], JSON_UNESCAPED_SLASHES),
            'ARCO_WORKER_QUEUE' => 'emails',
            'ARCO_QUEUE_TRIES' => '3',
        ];
    }

    public function __construct(string $basePath)
    {
        $this->app = new Application($basePath);
        Container::setInstance($this->app);
        Facade::setFacadeApplication($this->app);
        $this->app->instance('config', new Repository([
            'queue' => [
                'default' => 'sqs',
                'connections' => ['sqs' => [
                    'driver' => 'sqs',
                    'key' => 'AKIATEST',
                    'secret' => 'secret',
                    'prefix' => self::PREFIX,
                    'queue' => self::DEFAULT_URL,
                    'suffix' => '',
                    'region' => 'us-east-1',
                    'handler' => $this->handler(...),
                ]],
                'failed' => ['driver' => 'null'],
            ],
        ]));
        $this->app->register(QueueServiceProvider::class);
        $this->app->register(BusServiceProvider::class);
        $this->app->instance(ExceptionHandler::class, $this->exceptions = new RecordingExceptionHandler());
        $this->app->instance('queue.failer', $this->failer = new RecordingFailer());
        $this->app->scoped('arco.test.scoped', fn () => new \stdClass());
    }

    private function handler(CommandInterface $command, RequestInterface $request): PromiseInterface
    {
        $this->calls[] = [$command->getName(), $command->toArray()];
        if ($this->sqsFails) {
            return Create::rejectionFor(new SqsException('Access denied', $command));
        }
        return Create::promiseFor(new Result([]));
    }

    /** @return list<array<string, mixed>> The arguments of every call to $operation. */
    public function callsTo(string $operation): array
    {
        return array_values(array_map(fn (array $call) => $call[1], array_filter($this->calls, fn (array $call) => $call[0] === $operation)));
    }
}

final class RecordingExceptionHandler implements ExceptionHandler
{
    /** @var list<\Throwable> */
    public array $reported = [];

    public function report(\Throwable $e): void
    {
        $this->reported[] = $e;
    }

    public function shouldReport(\Throwable $e): bool
    {
        return true;
    }

    public function render($request, \Throwable $e): never
    {
        throw $e;
    }

    public function renderForConsole($output, \Throwable $e): void
    {
        $output->writeln($e->getMessage());
    }
}

final class RecordingFailer implements FailedJobProviderInterface
{
    /** @var list<array{connection: string, queue: string, payload: string, exception: string}> */
    public array $logged = [];

    public ?\Throwable $throws = null;

    public function log($connection, $queue, $payload, $exception)
    {
        if ($this->throws !== null) {
            throw $this->throws;
        }
        $this->logged[] = ['connection' => (string) $connection, 'queue' => (string) $queue, 'payload' => (string) $payload, 'exception' => $exception->getMessage()];
        return count($this->logged);
    }

    public function ids($queue = null) { return []; }
    public function all() { return []; }
    public function find($id)
    {
        foreach ($this->logged as $entry) {
            $payload = json_decode($entry['payload'], true);
            if (is_array($payload) && ($payload['uuid'] ?? null) === $id) {
                return (object) $entry;
            }
        }
        return null;
    }
    public function forget($id) { return false; }
    public function flush($hours = null) {}
}
