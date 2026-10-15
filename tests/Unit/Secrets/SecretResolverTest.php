<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Secrets;

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\SecretsManager\SecretsManagerClient;
use Aws\Ssm\SsmClient;
use Multiapi\Arco\Secrets\SecretResolver;
use Psr\Http\Message\RequestInterface;
use PHPUnit\Framework\TestCase;

final class SecretResolverTest extends TestCase
{
    /** @var list<CommandInterface> */
    private array $ssmCalls = [];
    /** @var list<CommandInterface> */
    private array $secretCalls = [];

    private function resolver(MockHandler $ssm, MockHandler $secrets): SecretResolver
    {
        $common = ['region' => 'us-east-1', 'version' => 'latest', 'credentials' => false];

        $ssmHandler = function (CommandInterface $command, RequestInterface $request) use ($ssm): mixed {
            $this->ssmCalls[] = $command;
            return $ssm($command, $request);
        };
        $secretsHandler = function (CommandInterface $command, RequestInterface $request) use ($secrets): mixed {
            $this->secretCalls[] = $command;
            return $secrets($command, $request);
        };

        return new SecretResolver(
            new SsmClient($common + ['handler' => $ssmHandler]),
            new SecretsManagerClient($common + ['handler' => $secretsHandler]),
        );
    }

    public function test_resolves_ssm_and_secrets_manager_references(): void
    {
        $ssm = new MockHandler([new Result([
            'Parameters' => [['Name' => '/app/prod/app-key', 'Value' => "base64:abc\nline2"]],
            'InvalidParameters' => [],
        ])]);
        $secrets = new MockHandler([new Result([
            'SecretValues' => [[
                'ARN' => 'arn:aws:secretsmanager:us-east-1:111111111111:secret:app/prod/db-AbCdEf',
                'Name' => 'app/prod/db',
                'SecretString' => '{"password":"p#ss","port":5432,"ssl":true}',
            ]],
            'Errors' => [],
        ])]);

        $result = $this->resolver($ssm, $secrets)->resolve([
            'APP_NAME' => 'Shop',
            'APP_KEY' => 'arco-ssm:/app/prod/app-key',
            'DB_PASSWORD' => 'arco-secret:app/prod/db#password',
            'DB_PORT' => 'arco-secret:app/prod/db#port',
            'DB_SSL' => 'arco-secret:app/prod/db#ssl',
        ]);

        self::assertSame([], $result['missing']);
        self::assertSame([
            'APP_KEY' => "base64:abc\nline2",
            'DB_PASSWORD' => 'p#ss',
            'DB_PORT' => '5432',
            'DB_SSL' => 'true',
        ], $result['resolved']);
        self::assertTrue($this->ssmCalls[0]['WithDecryption']);
        self::assertSame(['app/prod/db'], $this->secretCalls[0]['SecretIdList']);
    }

    public function test_reports_missing_references(): void
    {
        $ssm = new MockHandler([new Result(['Parameters' => [], 'InvalidParameters' => ['/app/prod/nope']])]);
        $secrets = new MockHandler([new Result([
            'SecretValues' => [['ARN' => 'arn:x', 'Name' => 'app/db', 'SecretString' => '{"user":"u"}']],
            'Errors' => [['SecretId' => 'app/gone', 'ErrorCode' => 'ResourceNotFoundException']],
        ])]);

        $result = $this->resolver($ssm, $secrets)->resolve([
            'A' => 'arco-ssm:/app/prod/nope',
            'B' => 'arco-secret:app/db#password',
            'C' => 'arco-secret:app/gone',
        ]);

        self::assertSame([], $result['resolved']);
        self::assertSame(['arco-ssm:/app/prod/nope', 'arco-secret:app/db#password', 'arco-secret:app/gone'], $result['missing']);
    }

    public function test_batches_ssm_names_by_ten(): void
    {
        $vars = [];
        for ($i = 1; $i <= 11; $i++) {
            $vars["K$i"] = "arco-ssm:/p/$i";
        }
        $ssm = new MockHandler([
            new Result(['Parameters' => array_map(fn ($i) => ['Name' => "/p/$i", 'Value' => "v$i"], range(1, 10)), 'InvalidParameters' => []]),
            new Result(['Parameters' => [['Name' => '/p/11', 'Value' => 'v11']], 'InvalidParameters' => []]),
        ]);

        $result = $this->resolver($ssm, new MockHandler())->resolve($vars);

        self::assertCount(2, $this->ssmCalls);
        self::assertSame('v11', $result['resolved']['K11']);
    }

    public function test_makes_no_calls_without_references(): void
    {
        $result = $this->resolver(new MockHandler(), new MockHandler())->resolve(['APP_ENV' => 'production']);

        self::assertSame(['resolved' => [], 'missing' => []], $result);
        self::assertSame([], $this->ssmCalls);
        self::assertSame([], $this->secretCalls);
    }
}
