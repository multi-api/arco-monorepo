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
}
