<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy;

use Aws\Lambda\Exception\LambdaException;
use Aws\Result;
use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Deploy\ConsoleFunction;
use Multiapi\Arco\Deploy\ConsoleRetriesNotDisabled;
use Multiapi\Arco\Deploy\DeployException;
use PHPUnit\Framework\TestCase;

final class ConsoleFunctionTest extends TestCase
{
    private const TAGS = ['arco:app' => 'shop', 'arco:environment' => 'production'];

    private function config(int $consoleTimeout = 900): AppConfig
    {
        return ConfigResolver::resolve(App::make('shop')->region('us-east-1')->consoleTimeout($consoleTimeout)->kmsKey('arn:aws:kms:us-east-1:1:key/k')->environment('production')->toArray(), 'production');
    }

    private static function missing(string $operation): LambdaException
    {
        return AwsMocks::error(LambdaException::class, $operation, 'ResourceNotFoundException', 404);
    }

    private function console(AwsMocks $mocks): ConsoleFunction
    {
        return new ConsoleFunction($mocks->clients()->lambda(), AwsMocks::noSleep());
    }

    public function test_creates_the_function_and_publishes_a_version(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::missing('GetFunctionConfiguration'),
            // The role was just created, so a propagation error is retried.
            AwsMocks::error(LambdaException::class, 'CreateFunction', 'InvalidParameterValueException', 400, 'The role defined for the function cannot be assumed by Lambda.'),
            new Result(['FunctionName' => 'shop-production-console', 'State' => 'Pending']),
            new Result(['State' => 'Pending', 'LastUpdateStatus' => 'InProgress']),
            new Result(['State' => 'Active', 'LastUpdateStatus' => 'Successful']),
            new Result(['Version' => '1']),
        );

        $version = $this->console($mocks)->deploy($this->config(), 'bucket', 'shop/production/h.zip', 'v1', 'arn:role', ['APP_ENV' => 'production', 'EMPTY' => ''], self::TAGS, true);

        self::assertSame('1', $version);
        self::assertSame(['GetFunctionConfiguration', 'CreateFunction', 'CreateFunction', 'GetFunctionConfiguration', 'GetFunctionConfiguration', 'PublishVersion'], $mocks->calls);
        $create = $mocks->params[2];
        self::assertSame('shop-production-console', $create['FunctionName']);
        self::assertSame('provided.al2023', $create['Runtime']);
        self::assertSame(['arm64'], $create['Architectures']);
        self::assertSame(2048, $create['MemorySize']);
        self::assertSame(900, $create['Timeout']);
        self::assertSame('arn:role', $create['Role']);
        self::assertSame(['S3Bucket' => 'bucket', 'S3Key' => 'shop/production/h.zip', 'S3ObjectVersion' => 'v1'], $create['Code']);
        self::assertSame(['APP_ENV' => 'production'], $create['Environment']['Variables'], 'Lambda rejects empty values');
        self::assertSame(self::TAGS, $create['Tags']);
        self::assertSame('arn:aws:kms:us-east-1:1:key/k', $create['KMSKeyArn']);
        self::assertSame(['Size' => 1024], $create['EphemeralStorage'], 'room in /tmp for the downloaded binary');
        self::assertSame('shop-production-console', $mocks->params[5]['FunctionName']);
    }

    public function test_updates_code_then_configuration_and_publishes(): void
    {
        $ready = fn () => new Result(['State' => 'Active', 'LastUpdateStatus' => 'Successful']);
        $mocks = new AwsMocks();
        $mocks->handler->append(
            $ready(),                                                        // exists
            $ready(),                                                        // idle before the code update
            new Result([]),                                                  // UpdateFunctionCode
            new Result(['State' => 'Active', 'LastUpdateStatus' => 'InProgress']),
            $ready(),
            new Result([]),                                                  // UpdateFunctionConfiguration
            $ready(),
            new Result(['Version' => '7']),
        );

        $version = $this->console($mocks)->deploy($this->config(600), 'bucket', 'k.zip', null, 'arn:role', ['A' => 'b'], self::TAGS);

        self::assertSame('7', $version);
        self::assertSame(['GetFunctionConfiguration', 'GetFunctionConfiguration', 'UpdateFunctionCode', 'GetFunctionConfiguration', 'GetFunctionConfiguration', 'UpdateFunctionConfiguration', 'GetFunctionConfiguration', 'PublishVersion'], $mocks->calls);
        self::assertSame('bucket', $mocks->params[2]['S3Bucket']);
        self::assertArrayNotHasKey('S3ObjectVersion', $mocks->params[2]);
        self::assertSame(600, $mocks->params[5]['Timeout']);
        self::assertArrayNotHasKey('Tags', $mocks->params[5]);
        self::assertSame('arn:aws:kms:us-east-1:1:key/k', $mocks->params[5]['KMSKeyArn']);
    }

    public function test_a_failed_update_stops_the_deploy_with_the_reason(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['State' => 'Active', 'LastUpdateStatus' => 'Successful']),
            new Result(['State' => 'Active', 'LastUpdateStatus' => 'Successful']),
            new Result([]),
            new Result(['State' => 'Active', 'LastUpdateStatus' => 'Failed', 'LastUpdateStatusReason' => 'Unzipped size must be smaller than 262144000 bytes']),
        );

        try {
            $this->console($mocks)->deploy($this->config(), 'bucket', 'k.zip', null, 'arn:role', [], self::TAGS);
            self::fail('A failed update must stop the deploy.');
        } catch (DeployException $e) {
            self::assertSame('deploy.console_failed', $e->key);
            self::assertSame('Unzipped size must be smaller than 262144000 bytes', $e->params['reason']);
        }
    }

    public function test_pointing_the_alias_turns_off_async_retries(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(self::missing('UpdateAlias'), new Result([]), new Result([]));

        $this->console($mocks)->point('shop-production-console', '7');

        self::assertSame(['UpdateAlias', 'CreateAlias', 'PutFunctionEventInvokeConfig'], $mocks->calls);
        self::assertSame(['FunctionName' => 'shop-production-console', 'Name' => 'live', 'FunctionVersion' => '7'], array_intersect_key($mocks->params[1], array_flip(['FunctionName', 'Name', 'FunctionVersion'])));
        self::assertSame('live', $mocks->params[2]['Qualifier']);
        self::assertSame(0, $mocks->params[2]['MaximumRetryAttempts'], 'a failed asynchronous command must never run again');
    }

    public function test_a_failed_retry_setting_after_the_alias_moved_is_told_apart(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(new Result([]), AwsMocks::error(LambdaException::class, 'PutFunctionEventInvokeConfig', 'AccessDeniedException', 403));

        try {
            $this->console($mocks)->point('shop-production-console', '7');
            self::fail('A refused retry setting must surface.');
        } catch (ConsoleRetriesNotDisabled $e) {
            self::assertSame(['UpdateAlias', 'PutFunctionEventInvokeConfig'], $mocks->calls);
        }
    }

    public function test_a_failed_alias_move_is_not_reported_as_retries(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(AwsMocks::error(LambdaException::class, 'UpdateAlias', 'AccessDeniedException', 403));

        $this->expectException(LambdaException::class);
        $this->console($mocks)->point('shop-production-console', '7');
    }

    public function test_live_version_and_delete_tolerate_a_missing_function(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(self::missing('GetAlias'), new Result(['FunctionVersion' => '7']), self::missing('DeleteFunction'));
        $console = $this->console($mocks);

        self::assertNull($console->liveVersion('shop-production-console'));
        self::assertSame('7', $console->liveVersion('shop-production-console'));
        $console->delete('shop-production-console');

        self::assertSame(['GetAlias', 'GetAlias', 'DeleteFunction'], $mocks->calls);
    }
}
