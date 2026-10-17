<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy;

use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Config\QueueConfig;
use Multiapi\Arco\Deploy\DeployException;
use Multiapi\Arco\Deploy\LambdaFunction;
use Multiapi\Arco\Deploy\WorkerFunctions;
use PHPUnit\Framework\TestCase;

final class WorkerFunctionsTest extends TestCase
{
    public function test_the_environment_carries_the_queue_and_the_guards_that_depend_on_it(): void
    {
        $arco = new QueueConfig('emails', tries: 4);
        $existing = new QueueConfig('legacy', tries: 2, dlq: false, existing: 'legacy');
        $noDlq = new QueueConfig('plain', dlq: false);

        self::assertSame(['APP_ENV' => 'x', 'ARCO_WORKER_QUEUE' => 'emails', 'ARCO_QUEUE_TRIES' => '4', 'ARCO_QUEUE_MAX_RECEIVES' => '6'], WorkerFunctions::environment($arco, ['APP_ENV' => 'x']));
        self::assertSame(['ARCO_WORKER_QUEUE' => 'legacy', 'ARCO_QUEUE_TRIES' => '2'], WorkerFunctions::environment($existing, []));
        self::assertArrayNotHasKey('ARCO_QUEUE_MAX_RECEIVES', WorkerFunctions::environment($noDlq, []));
    }

    public function test_settings_come_from_the_queue_and_empty_variables_are_dropped(): void
    {
        $config = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->kmsKey('arn:kms')->environment('production')->toArray(), 'production');
        $settings = WorkerFunctions::settings($config, new QueueConfig('emails', timeout: 90, memory: 768), 'arn:role', ['A' => '1', 'EMPTY' => '']);

        self::assertSame(90, $settings['Timeout']);
        self::assertSame(768, $settings['MemorySize']);
        self::assertSame(['Size' => 1024], $settings['EphemeralStorage']);
        self::assertSame(['Variables' => ['A' => '1']], $settings['Environment']);
        self::assertSame('arn:kms', $settings['KMSKeyArn']);
        self::assertSame('arn:role', $settings['Role']);
    }

    public function test_a_worker_that_disappears_is_reported_as_a_worker_not_as_the_console(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(AwsMocks::error(\Aws\Lambda\Exception\LambdaException::class, 'GetFunctionConfiguration', 'ResourceNotFoundException', 404));
        $function = (new WorkerFunctions($mocks->clients()->lambda(), AwsMocks::noSleep(), new \Multiapi\Arco\Deploy\Queues($mocks->clients()->sqs(), $mocks->clients()->lambda()), $mocks->clients()->taggingApi()))->function();

        try {
            $function->wait('shop-production-queue-emails');
            self::fail('expected deploy.worker_missing');
        } catch (DeployException $e) {
            self::assertSame('deploy.worker_missing', $e->key);
        }
        self::assertInstanceOf(LambdaFunction::class, $function);
    }

    private static function config(string ...$queues): \Multiapi\Arco\Config\AppConfig
    {
        $app = App::make('shop')->region('us-east-1');
        foreach ($queues as $definition) {
            $app = match ($definition) {
                'big' => $app->queue('big', batchSize: 11),
                'ten' => $app->queue('ten', batchSize: 10),
                'orders' => $app->queue('orders', batchSize: 10, fifo: true),
                'a' => $app->queue('legacy', existing: 'arn:aws:sqs:us-east-1:222222222222:queue-a', dlq: false),
                'b' => $app->queue('legacy', existing: 'arn:aws:sqs:us-east-1:222222222222:queue-b', dlq: false),
                default => throw new \LogicException($definition),
            };
        }
        return ConfigResolver::resolve($app->environment('production', fn (\Multiapi\Arco\Config\Environment $e) => $e->account('111111111111'))->toArray(), 'production');
    }

    private const ALIAS = 'arn:aws:lambda:us-east-1:111111111111:function:shop-production-queue-%s:live';

    /**
     * @param list<array<string, mixed>> $mappings the alias's mappings
     * @return array{AwsMocks, list<array{string, array<string, mixed>}>}
     */
    private static function activate(\Multiapi\Arco\Config\AppConfig $config, string $queue, array $mappings, \Aws\Result|\Throwable ...$more): array
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new \Aws\Result([]),                                                    // UpdateAlias
            new \Aws\Result(['AliasArn' => sprintf(self::ALIAS, $queue)]),          // GetAlias
            new \Aws\Result(['EventSourceMappings' => $mappings]),                  // ListEventSourceMappings (the alias)
            ...$more,
        );
        $clients = $mocks->clients();
        $workers = new WorkerFunctions($clients->lambda(), AwsMocks::noSleep(), new \Multiapi\Arco\Deploy\Queues($clients->sqs(), $clients->lambda()), $clients->taggingApi());
        $steps = [];
        $queueConfig = array_values(array_filter($config->queues, fn (QueueConfig $q) => $q->name === $queue))[0];
        $workers->activate($config, $queueConfig, '7', function (string $key, array $params) use (&$steps): void { $steps[] = [$key, $params]; });
        self::assertCount(0, $mocks->handler, 'every mocked call was used');
        return [$mocks, $steps];
    }

    /** @return array<string, mixed> */
    private static function call(AwsMocks $mocks, string $operation): array
    {
        $index = array_search($operation, $mocks->calls, true);
        self::assertIsInt($index, "$operation was called");
        return $mocks->params[$index];
    }

    public function test_a_standard_queue_above_ten_messages_a_batch_gets_a_one_second_batching_window(): void
    {
        [$mocks] = self::activate(self::config('big'), 'big', [], new \Aws\Result(['UUID' => 'u1']));
        self::assertSame(11, self::call($mocks, 'CreateEventSourceMapping')['BatchSize']);
        self::assertSame(1, self::call($mocks, 'CreateEventSourceMapping')['MaximumBatchingWindowInSeconds']);

        $arn = 'arn:aws:sqs:us-east-1:111111111111:shop-production-big';
        [$mocks] = self::activate(self::config('big'), 'big', [['UUID' => 'u1', 'EventSourceArn' => $arn]], new \Aws\Result([]));
        self::assertSame(1, self::call($mocks, 'UpdateEventSourceMapping')['MaximumBatchingWindowInSeconds']);
    }

    public function test_a_standard_queue_of_up_to_ten_clears_the_window_and_a_fifo_queue_never_sends_it(): void
    {
        [$mocks] = self::activate(self::config('ten'), 'ten', [], new \Aws\Result(['UUID' => 'u1']));
        self::assertSame(0, self::call($mocks, 'CreateEventSourceMapping')['MaximumBatchingWindowInSeconds']);

        $arn = 'arn:aws:sqs:us-east-1:111111111111:shop-production-ten';
        [$mocks] = self::activate(self::config('ten'), 'ten', [['UUID' => 'u1', 'EventSourceArn' => $arn]], new \Aws\Result([]));
        self::assertSame(0, self::call($mocks, 'UpdateEventSourceMapping')['MaximumBatchingWindowInSeconds'], 'a batch size lowered to 10 clears a window set before');

        [$mocks] = self::activate(self::config('orders'), 'orders', [], new \Aws\Result(['UUID' => 'u1']));
        self::assertArrayNotHasKey('MaximumBatchingWindowInSeconds', self::call($mocks, 'CreateEventSourceMapping'));
        $arn = 'arn:aws:sqs:us-east-1:111111111111:shop-production-orders.fifo';
        [$mocks] = self::activate(self::config('orders'), 'orders', [['UUID' => 'u1', 'EventSourceArn' => $arn]], new \Aws\Result([]));
        self::assertArrayNotHasKey('MaximumBatchingWindowInSeconds', self::call($mocks, 'UpdateEventSourceMapping'));
    }

    public function test_the_mappings_are_looked_up_by_alias_so_a_switched_queue_loses_its_old_mapping(): void
    {
        // legacy was `existing: queue-a`, now `existing: queue-b`
        [$mocks, $steps] = self::activate(
            self::config('b'),
            'legacy',
            [['UUID' => 'u-a', 'EventSourceArn' => 'arn:aws:sqs:us-east-1:222222222222:queue-a']],
            new \Aws\Result(['EventSourceMappings' => []]),                       // other consumers of queue-b
            new \Aws\Result(['UUID' => 'u-b']),                                   // CreateEventSourceMapping
            new \Aws\Result([]),                                                  // DeleteEventSourceMapping
        );

        self::assertSame(['UpdateAlias', 'GetAlias', 'ListEventSourceMappings', 'ListEventSourceMappings', 'CreateEventSourceMapping', 'DeleteEventSourceMapping'], $mocks->calls);
        self::assertSame(sprintf(self::ALIAS, 'legacy'), $mocks->params[2]['FunctionName']);
        self::assertArrayNotHasKey('EventSourceArn', $mocks->params[2], 'every mapping of the alias, whatever its queue');
        self::assertSame('arn:aws:sqs:us-east-1:222222222222:queue-b', self::call($mocks, 'CreateEventSourceMapping')['EventSourceArn']);
        self::assertSame('u-a', self::call($mocks, 'DeleteEventSourceMapping')['UUID']);
        self::assertContains(['deploy.worker_mapping_removed', ['queue' => 'legacy', 'arn' => 'arn:aws:sqs:us-east-1:222222222222:queue-a']], $steps);
    }

    public function test_the_current_mapping_is_updated_and_stale_ones_of_the_alias_are_deleted(): void
    {
        [$mocks, $steps] = self::activate(
            self::config('b'),
            'legacy',
            [
                ['UUID' => 'u-a', 'EventSourceArn' => 'arn:aws:sqs:us-east-1:222222222222:queue-a'],
                ['UUID' => 'u-b', 'EventSourceArn' => 'arn:aws:sqs:us-east-1:222222222222:queue-b'],
            ],
            new \Aws\Result([]),                                                  // UpdateEventSourceMapping
            AwsMocks::error(\Aws\Lambda\Exception\LambdaException::class, 'DeleteEventSourceMapping', 'ResourceNotFoundException', 404),
        );

        self::assertSame(['UpdateAlias', 'GetAlias', 'ListEventSourceMappings', 'UpdateEventSourceMapping', 'DeleteEventSourceMapping'], $mocks->calls);
        self::assertSame('u-b', self::call($mocks, 'UpdateEventSourceMapping')['UUID']);
        self::assertSame('u-a', self::call($mocks, 'DeleteEventSourceMapping')['UUID']);
        self::assertContains('deploy.worker_mapping_removed', array_column($steps, 0), 'a mapping already gone is still reported as removed');
        self::assertNotContains('deploy.worker_mapping_removed', \Multiapi\Arco\Cli\StepPrinter::WARNINGS, 'a step, not a warning');
    }
}
