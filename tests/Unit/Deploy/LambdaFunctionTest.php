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
        // One pause per round of polls, whatever the number of functions busy in it; each wait starts at 0.5 s.
        self::assertSame([500, 500, 500], $sleeper->naps);
        self::assertSame(0, $sleeper->slept);
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
        // 0.5 + 0.5 + 1 + 1 s, then 299 pauses of 2 s pass the 600 s deadline; the console is ready in the first round.
        $mocks->handler->append(self::ready(), self::busy());
        for ($round = 1; $round <= 303; $round++) {
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
        self::assertSame(601_000, $sleeper->sleptMs);
        self::assertSame([500, 500, 1000, 1000, 2000], array_slice($sleeper->naps, 0, 5), 'fast polls first, then every 2 s');
        self::assertCount(0, $mocks->handler);
    }

    /** @return array<string, mixed> what GetFunctionConfiguration returns for a function already set up with settings() */
    private static function configured(array $overrides = []): array
    {
        return [
            'State' => 'Active',
            'LastUpdateStatus' => 'Successful',
            'Role' => 'arn:aws:iam::123:role/shop-production',
            'Description' => 'Arco console function (Arco 1.2.3)',
            'MemorySize' => 1024,
            'EphemeralStorage' => ['Size' => 2048],
            'Timeout' => 900,
            'Environment' => ['Variables' => ['ARCO' => '1', 'ARCO_APP' => 'shop']],
            'VpcConfig' => ['SubnetIds' => [], 'SecurityGroupIds' => [], 'VpcId' => ''],
            'Layers' => [],
            ...$overrides,
        ];
    }

    /** @return array<string, mixed> the settings ConsoleFunction::settings() sends for that function */
    private static function settings(array $overrides = []): array
    {
        return [
            'Role' => 'arn:aws:iam::123:role/shop-production',
            'Description' => 'Arco console function (Arco 1.2.3)',
            'MemorySize' => 1024,
            'EphemeralStorage' => ['Size' => 2048],
            'Timeout' => 900,
            'Environment' => ['Variables' => ['ARCO_APP' => 'shop', 'ARCO' => '1']],
            ...$overrides,
        ];
    }

    public function test_a_configuration_already_in_place_is_not_sent_again(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(self::configured()),                 // the console already has these settings
            new Result(self::configured(['Timeout' => 30])), // the worker's timeout changed
            self::ready(), self::ready(),
            new Result([]), new Result([]),                 // UpdateFunctionCode console, worker
            self::ready(), self::ready(),
            new Result([]),                                 // UpdateFunctionConfiguration worker only
            self::ready(),                                  // only the worker is waited for
            new Result(['Version' => '8']), new Result(['Version' => '5']),
        );
        $sleeper = AwsMocks::noSleep();
        $lambda = $mocks->clients()->lambda();
        $code = ['S3Bucket' => 'bucket', 'S3Key' => 'console.zip'];
        $requests = [
            (new LambdaFunction($lambda, $sleeper))->request(self::CONSOLE, 'arco.console', $code, self::settings(), []),
            (new LambdaFunction($lambda, $sleeper))->request(self::WORKER, 'arco.worker', $code, self::settings(), []),
        ];

        $versions = LambdaFunction::deployAll($sleeper, $requests);

        self::assertSame([self::CONSOLE => '8', self::WORKER => '5'], $versions);
        self::assertSame([
            'GetFunctionConfiguration ' . self::CONSOLE, 'GetFunctionConfiguration ' . self::WORKER,
            'GetFunctionConfiguration ' . self::CONSOLE, 'GetFunctionConfiguration ' . self::WORKER,
            'UpdateFunctionCode ' . self::CONSOLE, 'UpdateFunctionCode ' . self::WORKER,
            'GetFunctionConfiguration ' . self::CONSOLE, 'GetFunctionConfiguration ' . self::WORKER,
            'UpdateFunctionConfiguration ' . self::WORKER,
            'GetFunctionConfiguration ' . self::WORKER,
            'PublishVersion ' . self::CONSOLE, 'PublishVersion ' . self::WORKER,
        ], self::trace($mocks));
    }

    public function test_a_role_just_created_always_gets_the_configuration(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(self::configured()), self::ready(),
            new Result([]), self::ready(),
            new Result([]), self::ready(),
            new Result(['Version' => '2']),
        );
        $sleeper = AwsMocks::noSleep();
        $function = new LambdaFunction($mocks->clients()->lambda(), $sleeper);

        $version = LambdaFunction::deployAll($sleeper, [$function->request(self::CONSOLE, 'arco.console', ['S3Bucket' => 'b', 'S3Key' => 'k'], self::settings(), [], roleJustCreated: true)]);

        self::assertSame([self::CONSOLE => '2'], $version);
        self::assertContains('UpdateFunctionConfiguration', $mocks->calls, 'a recreated role of the same name gets sent again');
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>, bool}> */
    public static function configurations(): iterable
    {
        yield 'same settings, variables in another order' => [self::configured(), self::settings(), true];
        yield 'a variable changed' => [self::configured(), self::settings(['Environment' => ['Variables' => ['ARCO' => '1', 'ARCO_APP' => 'other']]]), false];
        yield 'a variable added' => [self::configured(), self::settings(['Environment' => ['Variables' => ['ARCO' => '1', 'ARCO_APP' => 'shop', 'NEW' => 'x']]]), false];
        yield 'a variable removed' => [self::configured(), self::settings(['Environment' => ['Variables' => ['ARCO' => '1']]]), false];
        yield 'no variables on either side' => [self::configured(['Environment' => null]), self::settings(['Environment' => ['Variables' => []]]), true];
        yield 'variables Lambda could not decrypt' => [self::configured(['Environment' => ['Error' => ['ErrorCode' => 'KMSAccessDeniedException']]]), self::settings(['Environment' => ['Variables' => []]]), false];
        yield 'memory' => [self::configured(), self::settings(['MemorySize' => 2048]), false];
        yield 'timeout' => [self::configured(), self::settings(['Timeout' => 60]), false];
        yield 'role' => [self::configured(), self::settings(['Role' => 'arn:aws:iam::123:role/other']), false];
        yield 'description (a new Arco version)' => [self::configured(), self::settings(['Description' => 'Arco console function (Arco 1.2.4)']), false];
        yield 'ephemeral storage' => [self::configured(), self::settings(['EphemeralStorage' => ['Size' => 4096]]), false];
        yield 'a KMS key removed from arco.php' => [self::configured(['KMSKeyArn' => 'arn:aws:kms:us-east-1:123:key/a']), self::settings(), false];
        yield 'the same KMS key' => [self::configured(['KMSKeyArn' => 'arn:aws:kms:us-east-1:123:key/a']), self::settings(['KMSKeyArn' => 'arn:aws:kms:us-east-1:123:key/a']), true];
        yield 'a KMS key added' => [self::configured(), self::settings(['KMSKeyArn' => 'arn:aws:kms:us-east-1:123:key/a']), false];
        yield 'the same VPC, listed in another order' => [
            self::configured(['VpcConfig' => ['SubnetIds' => ['subnet-b', 'subnet-a'], 'SecurityGroupIds' => ['sg-1'], 'VpcId' => 'vpc-1', 'Ipv6AllowedForDualStack' => false]]),
            self::settings(['VpcConfig' => ['SubnetIds' => ['subnet-a', 'subnet-b'], 'SecurityGroupIds' => ['sg-1']]]),
            true,
        ];
        yield 'another subnet' => [
            self::configured(['VpcConfig' => ['SubnetIds' => ['subnet-a'], 'SecurityGroupIds' => ['sg-1'], 'VpcId' => 'vpc-1']]),
            self::settings(['VpcConfig' => ['SubnetIds' => ['subnet-a', 'subnet-b'], 'SecurityGroupIds' => ['sg-1']]]),
            false,
        ];
        yield 'leaving the VPC' => [
            self::configured(['VpcConfig' => ['SubnetIds' => ['subnet-a'], 'SecurityGroupIds' => ['sg-1'], 'VpcId' => 'vpc-1']]),
            self::settings(['VpcConfig' => ['SubnetIds' => [], 'SecurityGroupIds' => []]]),
            false,
        ];
        yield 'already out of the VPC' => [self::configured(['VpcConfig' => null]), self::settings(['VpcConfig' => ['SubnetIds' => [], 'SecurityGroupIds' => []]]), true];
        yield 'a setting the comparison does not know' => [self::configured(), self::settings(['Layers' => []]), false];
        yield 'a missing field' => [self::configured(['MemorySize' => null]), self::settings(), false];
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $settings
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('configurations')]
    public function test_which_configurations_are_already_in_place(array $current, array $settings, bool $matches): void
    {
        self::assertSame($matches, LambdaFunction::configured(array_filter($current, fn ($value) => $value !== null), $settings));
    }
}
