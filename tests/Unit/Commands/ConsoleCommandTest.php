<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Commands;

use Aws\MockHandler;
use Aws\Result;
use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Commands\ConsoleCommand;
use Multiapi\Arco\Console\CommandResult;
use Multiapi\Arco\Deploy\DeployException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ConsoleCommandTest extends TestCase
{
    private ProjectFixture $project;

    protected function setUp(): void
    {
        $this->project = new ProjectFixture(ProjectFixture::standardConfig());
    }

    protected function tearDown(): void
    {
        $this->project->restore();
    }

    private function tester(?FakeConsole $console = null, ?\Closure $consoleFactory = null): CommandTester
    {
        $mock = new MockHandler([new Result(['Account' => '111111111111'])]);
        return $this->project->tester(new ConsoleCommand(
            awsFactory: fn (?string $profile, string $region) => new AwsClients($profile, $region, ['handler' => $mock, 'credentials' => false]),
            consoleFactory: $consoleFactory ?? fn () => $console,
        ));
    }

    public function test_prints_the_output_as_is_and_exits_with_the_commands_code(): void
    {
        $console = new FakeConsole([new CommandResult(3, "<info>kept as text</info>\nfailed\n")]);
        $tester = $this->tester($console);

        $tester->execute(['args' => 'migrate --force', '--env' => 'staging', '--profile' => 'p'], ['interactive' => false]);

        self::assertSame(3, $tester->getStatusCode());
        self::assertSame("<info>kept as text</info>\nfailed\n", $tester->getDisplay());
        self::assertSame([['run', 'migrate --force', null, 'live']], $console->calls);
    }

    public function test_without_an_account_lock_no_sts_round_trip_comes_before_the_invoke(): void
    {
        $mock = new MockHandler([new Result(['Account' => '111111111111'])]);
        $tester = $this->project->tester(new ConsoleCommand(
            awsFactory: fn (?string $profile, string $region) => new AwsClients($profile, $region, ['handler' => $mock, 'credentials' => false]),
            consoleFactory: fn () => new FakeConsole([new CommandResult(0, "ok\n")]),
        ));

        $tester->execute(['args' => 'about', '--env' => 'staging', '--profile' => 'p'], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        self::assertCount(1, $mock, 'sts:GetCallerIdentity was not called');
    }

    public function test_async_starts_the_command_and_points_to_the_logs(): void
    {
        $console = new FakeConsole();
        $tester = $this->tester($console);

        $tester->execute(['args' => 'reports:build', '--env' => 'staging', '--profile' => 'p', '--async' => true, '--timeout' => '300'], ['interactive' => false, 'capture_stderr_separately' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertSame([['start', 'reports:build', 300, 'live']], $console->calls);
        self::assertStringContainsString('arco logs --env=staging --source=console --follow', $tester->getErrorOutput());
        self::assertSame('', $tester->getDisplay(), 'stdout carries only command output');
    }

    public function test_a_crash_shows_the_error_type_and_the_last_log_lines(): void
    {
        $tester = $this->tester(new FakeConsole([new CommandResult(1, '', 'Sandbox.Timedout', 'Task timed out after 900.00 seconds', ['migrating users...'])]));

        $tester->execute(['args' => 'migrate --force', '--env' => 'staging', '--profile' => 'p'], ['interactive' => false]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Sandbox.Timedout', $tester->getDisplay());
        self::assertStringContainsString('migrating users...', $tester->getDisplay());
    }

    public function test_credentials_that_expire_mid_command_are_named_with_the_profile(): void
    {
        $expired = new \Aws\Exception\AwsException('expired', new \Aws\Command('Invoke'), ['code' => 'ExpiredTokenException']);
        $tester = $this->tester(new FakeConsole([$expired]));

        $tester->execute(['args' => 'migrate --force', '--env' => 'staging', '--profile' => 'p'], ['interactive' => false]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('The AWS credentials for profile "p" are missing or expired', preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }

    public function test_a_denied_assume_role_shows_the_aws_message_not_expired_credentials(): void
    {
        $denied = new \Aws\Exception\AwsException('denied', new \Aws\Command('AssumeRole'), ['code' => 'AccessDenied', 'message' => 'not authorized to perform sts:AssumeRole']);
        $tester = $this->tester(new FakeConsole([new \Aws\Exception\CredentialsException('Error in retrieving assume role credentials.', 0, $denied)]));

        $tester->execute(['args' => 'about', '--env' => 'staging', '--profile' => 'p'], ['interactive' => false]);

        self::assertSame(1, $tester->getStatusCode());
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('not authorized to perform sts:AssumeRole', $display);
        self::assertStringNotContainsString('missing or expired', $display);
    }

    public function test_generic_expired_credentials_include_the_aws_message(): void
    {
        $tester = $this->tester(new FakeConsole([new \Aws\Exception\CredentialsException('credential_process returned expired credentials')]));

        $tester->execute(['args' => 'about', '--env' => 'staging', '--profile' => 'p'], ['interactive' => false]);

        $display = preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('missing or expired', $display);
        self::assertStringContainsString('credential_process returned expired credentials', $display);
    }

    public function test_an_environment_without_console_function_suggests_a_deploy(): void
    {
        $tester = $this->tester(consoleFactory: fn () => throw DeployException::make('console.not_deployed', ['function' => 'shop-staging-console', 'environment' => 'staging']));

        $tester->execute(['args' => 'about', '--env' => 'staging', '--profile' => 'p'], ['interactive' => false]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('arco deploy --env=staging', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()), 'the error block wraps at the terminal width');
    }

    public function test_rejects_a_bad_timeout_or_quoting_before_any_aws_call(): void
    {
        $console = new FakeConsole();
        $tester = $this->tester($console);

        $tester->execute(['args' => 'about', '--env' => 'staging', '--profile' => 'p', '--timeout' => '901'], ['interactive' => false]);
        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('901', $tester->getDisplay());

        $tester->execute(['args' => "about --x='y", '--env' => 'staging', '--profile' => 'p'], ['interactive' => false]);
        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('unclosed', $tester->getDisplay());
        self::assertSame([], $console->calls);
    }
}
