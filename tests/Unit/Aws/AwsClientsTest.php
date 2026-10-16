<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Aws;

use Aws\CommandInterface;
use Aws\Credentials\Credentials;
use Aws\Credentials\CredentialSources;
use Aws\MockHandler;
use Aws\Result;
use Multiapi\Arco\Tests\Support\TempPaths;
use Psr\Http\Message\RequestInterface;
use Multiapi\Arco\Aws\AwsClients;
use PHPUnit\Framework\TestCase;

final class AwsClientsTest extends TestCase
{
    private string $dir;
    /** @var array<string, string|false> */
    private array $env = [];

    protected function setUp(): void
    {
        $this->dir = TempPaths::path('arco-aws-');
        mkdir($this->dir);
        foreach (['AWS_CONFIG_FILE', 'AWS_SHARED_CREDENTIALS_FILE', 'AWS_PROFILE', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY'] as $name) {
            $this->env[$name] = getenv($name);
        }
        putenv('AWS_PROFILE');
        putenv('AWS_ACCESS_KEY_ID=env-key');
        putenv('AWS_SECRET_ACCESS_KEY=env-secret');
        putenv("AWS_SHARED_CREDENTIALS_FILE={$this->dir}/credentials");
        file_put_contents("{$this->dir}/credentials", '');
    }

    protected function tearDown(): void
    {
        foreach ($this->env as $name => $value) {
            putenv($value === false ? $name : "$name=$value");
        }
        array_map('unlink', glob("{$this->dir}/*") ?: []);
        rmdir($this->dir);
    }

    public function test_reads_a_profile_from_the_config_file_and_ignores_environment_keys(): void
    {
        // Static keys in ~/.aws/config: the SDK's own 'profile' option only reads ~/.aws/credentials.
        file_put_contents("{$this->dir}/config", "[profile common]\naws_access_key_id = config-key\naws_secret_access_key = config-secret\n");
        putenv("AWS_CONFIG_FILE={$this->dir}/config");

        $credentials = (AwsClients::credentialsFor('common', 'us-east-1'))()->wait();

        self::assertInstanceOf(Credentials::class, $credentials);
        self::assertSame('config-key', $credentials->getAccessKeyId());
    }

    /**
     * A tinker session sends many statements through one LambdaClient: the profile's credentials
     * (for SSO: a token read and sso:GetRoleCredentials) are resolved once, not per statement.
     */
    public function test_credentials_are_resolved_once_across_clients_and_calls(): void
    {
        $script = "{$this->dir}/creds.sh";
        file_put_contents($script, "#!/bin/sh\necho x >> {$this->dir}/count\necho '{\"Version\":1,\"AccessKeyId\":\"A\",\"SecretAccessKey\":\"S\",\"SessionToken\":\"T\",\"Expiration\":\"2099-01-01T00:00:00Z\"}'\n");
        chmod($script, 0755);
        file_put_contents("{$this->dir}/config", "[profile proc]\ncredential_process = $script\n");
        putenv("AWS_CONFIG_FILE={$this->dir}/config");
        $mock = new MockHandler([new Result(['Account' => '1']), new Result([]), new Result([]), new Result([])]);

        $aws = new AwsClients('proc', 'us-east-1', ['handler' => $mock]);
        $aws->accountId();
        $lambda = $aws->lambda(['retries' => 0]);
        for ($i = 0; $i < 3; $i++) {
            $lambda->invoke(['FunctionName' => 'f', 'Payload' => '{}']);
        }

        self::assertSame(1, substr_count((string) file_get_contents("{$this->dir}/count"), 'x'));
    }

    /** @return array{0: AwsClients, 1: \ArrayObject<int, string>} clients whose credentials come from $credentials, and the STS calls */
    private static function clientsWith(Credentials $credentials): array
    {
        $calls = new \ArrayObject();
        $handler = function (CommandInterface $command) use ($calls) {
            $calls[] = $command->getName();
            return \GuzzleHttp\Promise\Create::promiseFor(new Result(['Account' => '222222222222']));
        };
        return [new AwsClients(null, 'us-east-1', ['handler' => $handler, 'credentials' => $credentials]), $calls];
    }

    /**
     * IAM Identity Center and AssumeRole credentials are issued by AWS for one account, which the SDK
     * keeps with them: reading it saves sts:GetCallerIdentity (a round trip and a TLS handshake to
     * another host) before the first statement of a tinker session or command on a locked environment.
     */
    public function test_the_account_of_sso_and_assumed_role_credentials_needs_no_sts_call(): void
    {
        foreach ([CredentialSources::PROFILE_SSO, CredentialSources::PROFILE_SSO_LEGACY, CredentialSources::STS_ASSUME_ROLE] as $source) {
            [$aws, $calls] = self::clientsWith(new Credentials('A', 'S', 'T', time() + 3600, '123456789012', $source));

            self::assertSame('123456789012', $aws->accountId(), $source);
            self::assertSame([], $calls->getArrayCopy(), $source);
        }
    }

    public function test_other_credentials_ask_sts_for_the_account(): void
    {
        // credential_process output and environment variables state an account, but nothing checked it.
        foreach ([[CredentialSources::PROFILE_PROCESS, '123456789012'], [CredentialSources::ENVIRONMENT, '123456789012'], [CredentialSources::PROFILE, null], [CredentialSources::PROFILE_SSO, null], [CredentialSources::PROFILE_SSO, 'not-an-id'], [CredentialSources::PROFILE_SSO, "123456789012\n"]] as [$source, $account]) {
            [$aws, $calls] = self::clientsWith(new Credentials('A', 'S', null, null, $account, $source));

            self::assertSame('222222222222', $aws->accountId(), $source);
            self::assertSame(['GetCallerIdentity'], $calls->getArrayCopy(), $source);
        }
    }

    public function test_reads_a_profile_from_the_credentials_file(): void
    {
        file_put_contents("{$this->dir}/credentials", "[legacy]\naws_access_key_id = file-key\naws_secret_access_key = file-secret\n");
        file_put_contents("{$this->dir}/config", '');
        putenv("AWS_CONFIG_FILE={$this->dir}/config");

        self::assertSame('file-key', (AwsClients::credentialsFor('legacy', 'us-east-1'))()->wait()->getAccessKeyId());
    }

    /** @var list<array{params: array<string, mixed>, authorization: string}> */
    private array $sts = [];

    /** @return array<string, mixed> STS client overrides answering AssumeRole */
    private function stsMock(int $calls = 1): array
    {
        $results = [];
        for ($i = 1; $i <= $calls; $i++) {
            $results[] = new Result(['Credentials' => ['AccessKeyId' => "ASSUMED$i", 'SecretAccessKey' => 's', 'SessionToken' => 't', 'Expiration' => gmdate('c', time() + 3600)]]);
        }
        $mock = new MockHandler($results);
        return ['handler' => function (CommandInterface $command, RequestInterface $request) use ($mock) {
            $this->sts[] = ['params' => $command->toArray(), 'authorization' => $request->getHeaderLine('Authorization')];
            return $mock($command, $request);
        }];
    }

    private function config(string $ini): void
    {
        file_put_contents("{$this->dir}/config", $ini);
        putenv("AWS_CONFIG_FILE={$this->dir}/config");
    }

    public function test_assumes_a_role_profile_from_the_config_file_with_its_source_profile(): void
    {
        $this->config(<<<'INI'
            [profile base]
            aws_access_key_id = BASEKEY
            aws_secret_access_key = base-secret

            [profile dns]
            role_arn = arn:aws:iam::123456789012:role/dns
            source_profile = base
            external_id = ext-1
            duration_seconds = 1800
            INI);

        $credentials = (AwsClients::credentialsFor('dns', 'us-east-1', $this->stsMock()))()->wait();

        self::assertSame('ASSUMED1', $credentials->getAccessKeyId());
        $params = $this->sts[0]['params'];
        self::assertSame('arn:aws:iam::123456789012:role/dns', $params['RoleArn']);
        self::assertMatchesRegularExpression('/^arco-\d+$/', $params['RoleSessionName']);
        self::assertSame('ext-1', $params['ExternalId']);
        self::assertSame(1800, $params['DurationSeconds']);
        self::assertStringContainsString('Credential=BASEKEY/', $this->sts[0]['authorization']);
    }

    public function test_role_chains_and_role_profiles_in_the_credentials_file(): void
    {
        $this->config(<<<'INI'
            [profile base]
            aws_access_key_id = BASEKEY
            aws_secret_access_key = base-secret

            [profile middle]
            role_arn = arn:aws:iam::111111111111:role/middle
            source_profile = base
            role_session_name = me
            INI);
        file_put_contents("{$this->dir}/credentials", "[top]\nrole_arn = arn:aws:iam::222222222222:role/top\nsource_profile = middle\n");

        $credentials = (AwsClients::credentialsFor('top', 'us-east-1', $this->stsMock(2)))()->wait();

        self::assertSame('ASSUMED2', $credentials->getAccessKeyId());
        self::assertSame('arn:aws:iam::111111111111:role/middle', $this->sts[0]['params']['RoleArn']);
        self::assertSame('me', $this->sts[0]['params']['RoleSessionName']);
        self::assertSame('arn:aws:iam::222222222222:role/top', $this->sts[1]['params']['RoleArn']);
        self::assertStringContainsString('Credential=ASSUMED1/', $this->sts[1]['authorization']);
    }

    public function test_a_role_profile_can_use_its_own_keys_as_source(): void
    {
        $this->config("[profile self]\naws_access_key_id = SELFKEY\naws_secret_access_key = x\nrole_arn = arn:aws:iam::123456789012:role/r\nsource_profile = self\n");

        self::assertSame('ASSUMED1', (AwsClients::credentialsFor('self', 'us-east-1', $this->stsMock()))()->wait()->getAccessKeyId());
        self::assertStringContainsString('Credential=SELFKEY/', $this->sts[0]['authorization']);
    }

    public function test_unsupported_role_profiles_fail_with_a_clear_message(): void
    {
        $this->config(<<<'INI'
            [profile a]
            role_arn = arn:aws:iam::123456789012:role/a
            source_profile = b

            [profile b]
            role_arn = arn:aws:iam::123456789012:role/b
            source_profile = a

            [profile ec2]
            role_arn = arn:aws:iam::123456789012:role/x
            credential_source = Ec2InstanceMetadata

            [profile mfa]
            role_arn = arn:aws:iam::123456789012:role/x
            source_profile = a
            mfa_serial = arn:aws:iam::123456789012:mfa/me

            [profile orphan]
            role_arn = arn:aws:iam::123456789012:role/x
            INI);

        foreach (['a' => 'cycle', 'ec2' => 'credential_source', 'mfa' => 'mfa_serial', 'orphan' => 'source_profile'] as $profile => $expected) {
            try {
                (AwsClients::credentialsFor($profile, 'us-east-1', $this->stsMock()))()->wait();
                self::fail("Expected $profile to fail.");
            } catch (\Aws\Exception\CredentialsException $e) {
                self::assertStringContainsString($expected, $e->getMessage(), $profile);
            }
        }
        self::assertSame([], $this->sts);
    }
}
