<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy;

use Aws\Lambda\Exception\LambdaException;
use Aws\Result;
use Multiapi\Arco\Deploy\DeployException;
use Multiapi\Arco\Deploy\FunctionDeploy;
use Multiapi\Arco\Deploy\LambdaFunction;
use Multiapi\Arco\Deploy\Sleeper;
use PHPUnit\Framework\TestCase;

final class LambdaFunctionTest extends TestCase
{
    private const CONSOLE = 'shop-production-console';
    private const WORKER = 'shop-production-queue-emails';

    private static function ready(): Result
    {
        return new Result(['State' => 'Active', 'LastUpdateStatus' => 'Successful']);
    }

    private static function busy(): Result
    {
        return new Result(['State' => 'Active', 'LastUpdateStatus' => 'InProgress']);
    }

    /** @return list<FunctionDeploy> the console and one worker, as AwsDeployer::prepare() hands them over */
    private static function requests(AwsMocks $mocks, Sleeper $sleeper): array
    {
        $lambda = $mocks->clients()->lambda();
        $console = new LambdaFunction($lambda, $sleeper);
        $worker = new LambdaFunction($lambda, $sleeper, 'deploy.worker_missing', 'deploy.worker_update_failed');
        $code = ['S3Bucket' => 'bucket', 'S3Key' => 'console.zip'];
        return [
            $console->request(self::CONSOLE, 'arco.console', $code, ['Timeout' => 900], []),
            $worker->request(self::WORKER, 'arco.worker', $code, ['Timeout' => 60], []),
        ];
    }

    /** @return list<string> "Operation FunctionName" per call */
    private static function trace(AwsMocks $mocks): array
    {
        return array_map(fn (string $operation, array $params) => $operation . ' ' . ($params['FunctionName'] ?? ''), $mocks->calls, $mocks->params);
    }

    public function test_functions_are_updated_together_phase_by_phase(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::ready(), self::ready(),                   // both exist
            self::ready(), self::busy(),                    // the worker is still busy before its code update
            self::ready(),                                  // ... then idle
            new Result([]), new Result([]),                 // UpdateFunctionCode console, worker
            self::busy(), self::busy(),                     // both apply their code together
            self::busy(), self::ready(),
            self::ready(),
            new Result([]), new Result([]),                 // UpdateFunctionConfiguration console, worker
            self::ready(), self::ready(),
            new Result(['Version' => '7']), new Result(['Version' => '3']),
        );
        $sleeper = AwsMocks::noSleep();

        $versions = LambdaFunction::deployAll($sleeper, self::requests($mocks, $sleeper));

        self::assertSame([self::CONSOLE => '7', self::WORKER => '3'], $versions);
        self::assertSame([
            'GetFunctionConfiguration ' . self::CONSOLE, 'GetFunctionConfiguration ' . self::WORKER,
            'GetFunctionConfiguration ' . self::CONSOLE, 'GetFunctionConfiguration ' . self::WORKER,
            'GetFunctionConfiguration ' . self::WORKER,
            'UpdateFunctionCode ' . self::CONSOLE, 'UpdateFunctionCode ' . self::WORKER,
            'GetFunctionConfiguration ' . self::CONSOLE, 'GetFunctionConfiguration ' . self::WORKER,
            'GetFunctionConfiguration ' . self::CONSOLE, 'GetFunctionConfiguration ' . self::WORKER,
            'GetFunctionConfiguration ' . self::CONSOLE,
            'UpdateFunctionConfiguration ' . self::CONSOLE, 'UpdateFunctionConfiguration ' . self::WORKER,
            'GetFunctionConfiguration ' . self::CONSOLE, 'GetFunctionConfiguration ' . self::WORKER,
            'PublishVersion ' . self::CONSOLE, 'PublishVersion ' . self::WORKER,
        ], self::trace($mocks), 'the second code update starts before the first one is ready');
        // One 2 s pause per round of polls, whatever the number of functions busy in it.
        self::assertSame(6, $sleeper->slept);
        self::assertSame('', $mocks->params[12]['KMSKeyArn'], 'the configuration update still resets a removed KMS key');
        self::assertSame(60, $mocks->params[13]['Timeout']);
    }

    public function test_a_new_function_is_created_alongside_an_updated_one(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            AwsMocks::error(LambdaException::class, 'GetFunctionConfiguration', 'ResourceNotFoundException', 404), // console missing
            self::ready(),                                  // the worker exists
            self::ready(),                                  // worker idle
            new Result(['State' => 'Pending']),             // CreateFunction console
            new Result([]),                                 // UpdateFunctionCode worker
            self::ready(), self::ready(),
            new Result([]),                                 // UpdateFunctionConfiguration worker only: the console was created with it
            self::ready(),
            new Result(['Version' => '1']), new Result(['Version' => '4']),
        );
        $sleeper = AwsMocks::noSleep();

        $versions = LambdaFunction::deployAll($sleeper, self::requests($mocks, $sleeper));

        self::assertSame([self::CONSOLE => '1', self::WORKER => '4'], $versions);
        self::assertSame([
            'GetFunctionConfiguration ' . self::CONSOLE, 'GetFunctionConfiguration ' . self::WORKER,
            'GetFunctionConfiguration ' . self::WORKER,
            'CreateFunction ' . self::CONSOLE, 'UpdateFunctionCode ' . self::WORKER,
            'GetFunctionConfiguration ' . self::CONSOLE, 'GetFunctionConfiguration ' . self::WORKER,
            'UpdateFunctionConfiguration ' . self::WORKER,
            'GetFunctionConfiguration ' . self::WORKER,
            'PublishVersion ' . self::CONSOLE, 'PublishVersion ' . self::WORKER,
        ], self::trace($mocks));
        self::assertSame(0, $sleeper->slept);
    }

    public function test_a_failed_worker_is_named_and_nothing_is_published(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::ready(), self::ready(),
            self::ready(), self::ready(),
            new Result([]), new Result([]),                 // UpdateFunctionCode console, worker
            self::ready(),                                  // the console is fine
            new Result(['State' => 'Active', 'LastUpdateStatus' => 'Failed', 'LastUpdateStatusReason' => 'Unzipped size must be smaller than 262144000 bytes']),
        );
        $sleeper = AwsMocks::noSleep();

        try {
            LambdaFunction::deployAll($sleeper, self::requests($mocks, $sleeper));
            self::fail('A failed worker update must stop the deploy.');
        } catch (DeployException $e) {
            self::assertSame('deploy.worker_update_failed', $e->key);
            self::assertSame(self::WORKER, $e->params['function']);
            self::assertSame('Unzipped size must be smaller than 262144000 bytes', $e->params['reason']);
        }
        self::assertNotContains('UpdateFunctionConfiguration', $mocks->calls);
        self::assertNotContains('PublishVersion', $mocks->calls);
    }

    public function test_the_timeout_names_the_functions_still_busy(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(self::ready(), self::ready(), self::ready(), self::ready(), new Result([]), new Result([]));
        // 301 rounds of 2 s reach the 600 s deadline; the console is ready in the first one.
        $mocks->handler->append(self::ready(), self::busy());
        for ($round = 1; $round <= 300; $round++) {
            $mocks->handler->append(self::busy());
        }
        $sleeper = AwsMocks::noSleep();

        try {
            LambdaFunction::deployAll($sleeper, self::requests($mocks, $sleeper));
            self::fail('A function busy past the deadline must stop the deploy.');
        } catch (DeployException $e) {
            self::assertSame('deploy.timeout', $e->key);
            self::assertSame(['what' => self::WORKER], $e->params);
        }
        self::assertSame(600, $sleeper->slept);
        self::assertCount(0, $mocks->handler);
    }
}
