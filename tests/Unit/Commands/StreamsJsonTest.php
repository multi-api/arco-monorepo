<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Commands;

use Multiapi\Arco\Cli\Application;
use Multiapi\Arco\Cli\ArcoCommand;
use Multiapi\Arco\Cli\JsonStream;
use Multiapi\Arco\Commands\Concerns\StreamsJson;
use Multiapi\Arco\Commands\Concerns\UsesCloudflare;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/** The shared --json wrapper of deploy and remove. */
final class StreamsJsonTest extends TestCase
{
    /** @param \Closure(JsonStream): int $body */
    private function tester(\Closure $body, string $succeeded = 'deployed'): CommandTester
    {
        $command = new class ($body, $succeeded) extends ArcoCommand {
            use StreamsJson;
            use UsesCloudflare;

            /** @param \Closure(JsonStream): int $body */
            public function __construct(private readonly \Closure $body, private readonly string $succeeded)
            {
                $this->clock = fn (): float => 1792591385.0;
                parent::__construct('json-probe');
            }

            protected function configure(): void
            {
                $this->addOption('env', null, InputOption::VALUE_REQUIRED)->addOption('json', null, InputOption::VALUE_NONE);
                $this->addCloudflareTokenOption();
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                return $this->runJson($input, $output, fn (OutputInterface $stderr, JsonStream $stream): int => ($this->body)($stream));
            }

            protected function jsonChanged(): bool
            {
                return false;
            }

            protected function jsonRolledBack(): bool
            {
                return false;
            }

            protected function jsonSucceeded(): string
            {
                return $this->succeeded === 'removed' ? 'removed' : 'deployed';
            }
        };
        $app = Application::create();
        $app->addCommands([$command]);
        return new CommandTester($app->find('json-probe'));
    }

    public function test_a_run_that_returns_without_a_result_still_ends_with_one(): void
    {
        foreach ([[0, 'removed', null], [1, 'refused', 'common.aborted'], [3, 'refused', 'common.aborted']] as [$code, $outcome, $key]) {
            $tester = $this->tester(function (JsonStream $stream) use ($code): int {
                $stream('remove.step_console', ['function' => 'f']);
                return $code;
            }, 'removed');

            $tester->execute(['--env' => 'staging', '--json' => true], ['capture_stderr_separately' => true]);

            self::assertSame($code, $tester->getStatusCode(), 'the exit code is kept');
            $lines = DeployJsonTest::lines($tester->getDisplay());
            $result = $lines[count($lines) - 1];
            self::assertSame([$outcome, 'staging', $key], [$result['outcome'], $result['environment'], $result['error']['key'] ?? null]);
        }
    }

    public function test_aws_secret_environment_values_and_the_cloudflare_token_are_redacted(): void
    {
        putenv('AWS_SECRET_ACCESS_KEY=aws-secret-key-value');
        putenv('AWS_SESSION_TOKEN=aws-session-token-value');
        try {
            $tester = $this->tester(function (JsonStream $stream): int {
                $stream('deploy.command', ['command' => 'aws-secret-key-value aws-session-token-value cf-token-value']);
                return 0;
            });
            $tester->execute(['--json' => true, '--cloudflare-token' => 'cf-token-value'], ['capture_stderr_separately' => true]);
        } finally {
            putenv('AWS_SECRET_ACCESS_KEY');
            putenv('AWS_SESSION_TOKEN');
        }

        $lines = DeployJsonTest::lines($tester->getDisplay());
        self::assertSame('[redacted] [redacted] [redacted]', $lines[0]['params']['command']);
    }
}
