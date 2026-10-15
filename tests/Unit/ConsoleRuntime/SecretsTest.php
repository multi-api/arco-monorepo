<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\ConsoleRuntime;

use Multiapi\Arco\ConsoleRuntime\Secrets;
use PHPUnit\Framework\TestCase;

final class SecretsTest extends TestCase
{
    private const CREDENTIALS = ['AWS_ACCESS_KEY_ID' => 'AK', 'AWS_SECRET_ACCESS_KEY' => 'SK', 'AWS_SESSION_TOKEN' => 'T'];

    /** @var list<array{host: string, target: string, body: array<string, mixed>, headers: array<string, string>}> */
    private array $calls = [];

    /** @param array<string, array<string, mixed>> $responses by X-Amz-Target */
    private function secrets(array $responses): Secrets
    {
        return new Secrets('us-east-1', function (string $host, array $headers, string $body) use ($responses): array {
            $target = $headers['x-amz-target'];
            $this->calls[] = ['host' => $host, 'target' => $target, 'body' => (array) json_decode($body, true), 'headers' => $headers];
            return ['status' => 200, 'body' => (string) json_encode($responses[$target] ?? [])];
        });
    }

    public function test_resolves_references_and_keeps_exact_string_values(): void
    {
        $secrets = $this->secrets([
            'AmazonSSM.GetParameters' => ['Parameters' => [['Name' => '/shop/prod/app-key', 'Value' => 'base64:k=']]],
            'secretsmanager.BatchGetSecretValue' => ['SecretValues' => [
                ['ARN' => 'arn:aws:secretsmanager:us-east-1:1:secret:shop/prod/db-Ab12', 'Name' => 'shop/prod/db', 'SecretString' => '{"password":"p#ss","port":5432,"ssl":true}'],
            ]],
        ]);

        $result = $secrets->resolve([
            ...self::CREDENTIALS,
            'APP_KEY' => 'arco-ssm:/shop/prod/app-key',
            'DB_PASSWORD' => 'arco-secret:shop/prod/db#password',
            'DB_PORT' => 'arco-secret:shop/prod/db#port',
            'DB_SSL' => 'arco-secret:shop/prod/db#ssl',
            'PLAIN' => 'value',
        ]);

        self::assertSame(['APP_KEY' => 'base64:k=', 'DB_PASSWORD' => 'p#ss', 'DB_PORT' => '5432', 'DB_SSL' => 'true'], $result['resolved']);
        self::assertSame([], $result['missing']);
        self::assertSame('ssm.us-east-1.amazonaws.com', $this->calls[0]['host']);
        self::assertSame(['Names' => ['/shop/prod/app-key'], 'WithDecryption' => true], $this->calls[0]['body']);
        self::assertSame(['SecretIdList' => ['shop/prod/db']], $this->calls[1]['body']);
        self::assertSame('T', $this->calls[0]['headers']['x-amz-security-token']);
    }

    public function test_reports_missing_references_and_makes_no_calls_without_references(): void
    {
        $secrets = $this->secrets(['AmazonSSM.GetParameters' => ['Parameters' => []]]);

        self::assertSame(['resolved' => [], 'missing' => []], $secrets->resolve(['PLAIN' => 'x']));
        self::assertSame([], $this->calls);

        $result = $secrets->resolve([...self::CREDENTIALS, 'A' => 'arco-ssm:/missing']);
        self::assertSame(['arco-ssm:/missing'], $result['missing']);
    }

    public function test_batches_ssm_by_10_and_secrets_manager_by_20(): void
    {
        $env = self::CREDENTIALS;
        for ($i = 0; $i < 11; $i++) {
            $env["P$i"] = "arco-ssm:/p$i";
        }
        for ($i = 0; $i < 21; $i++) {
            $env["S$i"] = "arco-secret:s$i";
        }

        $this->secrets([])->resolve($env);

        $sizes = array_map(fn (array $c) => count((array) ($c['body']['Names'] ?? $c['body']['SecretIdList'])), $this->calls);
        self::assertSame([10, 1, 20, 1], $sizes);
    }

    public function test_needs_credentials_only_when_there_are_references(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->secrets([])->resolve(['A' => 'arco-ssm:/x']);
    }

    public function test_an_aws_error_is_reported(): void
    {
        $secrets = new Secrets('us-east-1', fn () => ['status' => 400, 'body' => '{"__type":"AccessDeniedException"}']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AmazonSSM.GetParameters failed with HTTP 400');
        $secrets->resolve([...self::CREDENTIALS, 'A' => 'arco-ssm:/x']);
    }

    public function test_all_digit_names_are_sent_as_strings(): void
    {
        $secrets = $this->secrets([
            'AmazonSSM.GetParameters' => ['Parameters' => [['Name' => '12345', 'Value' => 'from-ssm']]],
            'secretsmanager.BatchGetSecretValue' => ['SecretValues' => [['Name' => '678', 'SecretString' => 'from-sm']]],
        ]);

        $result = $secrets->resolve([...self::CREDENTIALS, 'A' => 'arco-ssm:12345', 'B' => 'arco-secret:678']);

        self::assertSame(['Names' => ['12345'], 'WithDecryption' => true], $this->calls[0]['body']);
        self::assertSame(['SecretIdList' => ['678']], $this->calls[1]['body']);
        self::assertSame(['A' => 'from-ssm', 'B' => 'from-sm'], $result['resolved']);
    }
}
