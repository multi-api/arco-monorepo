<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Console;

use Aws\Lambda\Exception\LambdaException;
use Aws\Result;
use GuzzleHttp\Psr7\Utils;
use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Console\LambdaConsole;
use Multiapi\Arco\Deploy\DeployException;
use Multiapi\Arco\Tests\Unit\Deploy\AwsMocks;
use PHPUnit\Framework\TestCase;

final class LambdaConsoleTest extends TestCase
{
    private function config(): AppConfig
    {
        return ConfigResolver::resolve(App::make('shop')->region('us-east-1')->console(timeout: 600)->environment('production')->toArray(), 'production');
    }

    private function console(AwsMocks $mocks): LambdaConsole
    {
        return new LambdaConsole($mocks->clients()->lambda(LambdaConsole::clientOptions($this->config())));
    }

    /** @param array<string, mixed> $payload */
    private static function invoked(array $payload, ?string $functionError = null, string $log = ''): Result
    {
        $data = ['StatusCode' => 200, 'Payload' => Utils::streamFor((string) json_encode($payload)), 'LogResult' => base64_encode($log)];
        if ($functionError !== null) {
            $data['FunctionError'] = $functionError;
        }
        return new Result($data);
    }

    public function test_runs_a_command_on_the_live_alias_and_returns_its_exit_code_and_output(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(self::invoked(['exitCode' => 3, 'output' => "Nothing to migrate.\n"]));

        $result = $this->console($mocks)->run($this->config(), 'migrate --force');

        self::assertSame(3, $result->exitCode);
        self::assertSame("Nothing to migrate.\n", $result->output);
        self::assertFalse($result->crashed());
        self::assertFalse($result->succeeded());
        self::assertSame(['Invoke'], $mocks->calls);
        self::assertSame('shop-production-console', $mocks->params[0]['FunctionName']);
        self::assertSame('live', $mocks->params[0]['Qualifier']);
        self::assertSame('RequestResponse', $mocks->params[0]['InvocationType']);
        self::assertSame('Tail', $mocks->params[0]['LogType']);
        self::assertSame(['command' => 'migrate --force'], json_decode($mocks->params[0]['Payload'], true));
    }

    public function test_a_version_and_a_timeout_can_be_given(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(self::invoked(['exitCode' => 0, 'output' => '']));

        self::assertTrue($this->console($mocks)->run($this->config(), 'config:cache', 120, '7')->succeeded());

        self::assertSame('7', $mocks->params[0]['Qualifier']);
        self::assertSame(['command' => 'config:cache', 'timeout' => 120], json_decode($mocks->params[0]['Payload'], true));
    }

    public function test_an_unhandled_error_reports_its_type_and_the_log_tail(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(self::invoked(['errorType' => 'Sandbox.Timedout', 'errorMessage' => 'Task timed out after 600.00 seconds'], 'Unhandled', "START\nmigrating...\n\nEND\n"));

        $result = $this->console($mocks)->run($this->config(), 'migrate --force');

        self::assertTrue($result->crashed());
        self::assertSame(1, $result->exitCode);
        self::assertSame('Sandbox.Timedout', $result->errorType);
        self::assertSame('Task timed out after 600.00 seconds', $result->errorMessage);
        self::assertSame(['START', 'migrating...', 'END'], $result->logTail);
    }

    public function test_an_unexpected_payload_is_reported(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(self::invoked(['hello' => 'world']));

        $result = $this->console($mocks)->run($this->config(), 'about');

        self::assertSame('Arco.InvalidResponse', $result->errorType);
    }

    public function test_a_missing_function_means_the_environment_needs_a_deploy(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(AwsMocks::error(LambdaException::class, 'Invoke', 'ResourceNotFoundException', 404));

        try {
            $this->console($mocks)->run($this->config(), 'about');
            self::fail('A missing function must be reported.');
        } catch (DeployException $e) {
            self::assertSame('console.not_deployed', $e->key);
            self::assertSame(['function' => 'shop-production-console', 'environment' => 'production'], $e->params);
        }
    }

    public function test_a_failed_invoke_is_not_retried(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            AwsMocks::error(LambdaException::class, 'Invoke', 'ServiceException', 500),
            self::invoked(['exitCode' => 0, 'output' => 'ran a second time']),
        );

        try {
            $this->console($mocks)->run($this->config(), 'migrate --force');
            self::fail('The error must surface instead of a retry.');
        } catch (LambdaException $e) {
            self::assertSame('ServiceException', $e->getAwsErrorCode());
        }
        self::assertSame(['Invoke'], $mocks->calls, 'a retried invoke would run the command twice');
        $options = LambdaConsole::clientOptions($this->config());
        self::assertSame(0, $options['retries']);
        self::assertSame(630, $options['http']['timeout'], 'above the function timeout');
    }

    public function test_without_ext_curl_the_client_options_leave_out_the_curl_keepalive(): void
    {
        // Guzzle falls back to its stream handler; the CURLOPT_* constants would not even exist.
        $options = LambdaConsole::clientOptions($this->config(), curl: false);

        self::assertArrayNotHasKey('curl', $options['http']);
        self::assertSame(630, $options['http']['timeout']);
        self::assertSame(1, LambdaConsole::clientOptions($this->config(), curl: true)['http']['curl'][CURLOPT_TCP_KEEPALIVE]);
    }

    public function test_start_uses_an_event_invocation_on_the_live_alias(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(new Result(['StatusCode' => 202]));

        $this->console($mocks)->start($this->config(), 'reports:build', 300);

        self::assertSame('Event', $mocks->params[0]['InvocationType']);
        self::assertSame('live', $mocks->params[0]['Qualifier']);
        self::assertSame(['command' => 'reports:build', 'timeout' => 300], json_decode($mocks->params[0]['Payload'], true));
    }

    public function test_tinker_sends_base64_code_and_the_context(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(self::invoked(['exitCode' => 0, 'output' => 'ARCO-TINKER:e30=']));

        $this->console($mocks)->tinker($this->config(), '$a = 1', 'Y3R4');

        self::assertSame(['tinker' => ['code' => base64_encode('$a = 1'), 'context' => 'Y3R4']], json_decode($mocks->params[0]['Payload'], true));
    }
}
