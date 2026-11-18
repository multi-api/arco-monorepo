<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Commands;

use Aws\Lambda\Exception\LambdaException;
use Aws\Result;
use Multiapi\Arco\Commands\TenantsRestoreCommand;
use Multiapi\Arco\Deploy\TenantMediator;
use Multiapi\Arco\Tests\Unit\Deploy\AwsMocks;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class TenantsRestoreCommandTest extends TestCase
{
    private const TAGS = ['arco:app' => 'shop', 'arco:environment' => 'production'];

    private ?ProjectFixture $project = null;
    private AwsMocks $mocks;

    protected function setUp(): void
    {
        $this->mocks = new AwsMocks();
        $this->project = new ProjectFixture(str_replace('->timeout(60)', "->timeout(60)->domain('shop.example.com')->tenantDomains('routing.example.com')", ProjectFixture::standardConfig()));
    }

    protected function tearDown(): void
    {
        $this->project?->restore();
    }

    /** @param array<string, mixed> $input */
    private function run_(array $input = []): CommandTester
    {
        $tester = $this->project?->tester(new TenantsRestoreCommand(fn (?string $profile, string $region) => $this->mocks->clients($region))) ?? throw new \LogicException('No project.');
        $tester->execute(['--env' => 'production', '--profile' => 'p', ...$input], ['interactive' => false]);
        return $tester;
    }

    private static function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    /**
     * @param array<string, string> $tags
     * @return list<Result> GetCallerIdentity, then the ownership reads
     */
    private static function owned(array $tags = self::TAGS): array
    {
        return [
            new Result(['Account' => '111111111111']),
            new Result(['FunctionName' => 'shop-production-tenants', 'FunctionArn' => 'arn:aws:lambda:us-east-1:111111111111:function:shop-production-tenants', 'Description' => TenantMediator::MARKER . ' (Arco 1.0.0)']),
            new Result(['Tags' => $tags]),
        ];
    }

    private static function concurrency(?int $reserved): Result
    {
        return new Result($reserved === null ? [] : ['ReservedConcurrentExecutions' => $reserved]);
    }

    /** @return array<string, mixed> */
    private static function json(CommandTester $tester): array
    {
        $json = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($json);
        return $json;
    }

    public function test_it_deletes_the_reserved_concurrency_a_stopped_remove_left(): void
    {
        $this->mocks->handler->append(...self::owned(), ...[self::concurrency(0), new Result([]), self::concurrency(null)]);

        $tester = $this->run_();

        $tester->assertCommandIsSuccessful();
        self::assertContains('DeleteFunctionConcurrency', $this->mocks->calls);
        self::assertStringContainsString('shop-production-tenants of production is enabled again', self::display($tester));
    }

    public function test_it_says_when_the_mediator_is_already_enabled_and_writes_nothing(): void
    {
        $this->mocks->handler->append(...self::owned(), ...[self::concurrency(null)]);

        $tester = $this->run_();

        $tester->assertCommandIsSuccessful();
        self::assertNotContains('DeleteFunctionConcurrency', $this->mocks->calls);
        self::assertStringContainsString('already enabled', self::display($tester));
    }

    public function test_it_leaves_a_limit_set_on_purpose_and_reports_it(): void
    {
        $this->mocks->handler->append(...self::owned(), ...[self::concurrency(5)]);

        $tester = $this->run_();

        $tester->assertCommandIsSuccessful();
        self::assertNotContains('DeleteFunctionConcurrency', $this->mocks->calls);
        self::assertStringContainsString('reserved concurrency of 5, not 0', self::display($tester));
    }

    public function test_a_function_of_another_environment_fails_and_nothing_changes(): void
    {
        $this->mocks->handler->append(...self::owned(['arco:app' => 'shop', 'arco:environment' => 'staging']));

        $tester = $this->run_();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertNotContains('DeleteFunctionConcurrency', $this->mocks->calls);
        self::assertNotContains('GetFunctionConcurrency', $this->mocks->calls);
        self::assertStringContainsString('environment "staging"', self::display($tester));
    }

    public function test_a_missing_function_is_a_note_with_exit_0_and_no_write(): void
    {
        $this->mocks->handler->append(new Result(['Account' => '111111111111']), AwsMocks::error(LambdaException::class, 'GetFunctionConfiguration', 'ResourceNotFoundException', 404));

        $tester = $this->run_();

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('nothing to restore, and nothing was changed', self::display($tester));
        self::assertNotContains('DeleteFunctionConcurrency', $this->mocks->calls);
    }

    public function test_json_nothing_to_restore(): void
    {
        $this->mocks->handler->append(new Result(['Account' => '111111111111']), AwsMocks::error(LambdaException::class, 'GetFunctionConfiguration', 'ResourceNotFoundException', 404));

        $tester = $this->run_(['--json' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertSame(
            ['schema' => 1, 'outcome' => 'nothing_to_restore', 'app' => 'shop', 'environment' => 'production', 'function' => 'shop-production-tenants', 'reservedConcurrency' => null],
            self::json($tester),
        );
    }

    public function test_json_restored(): void
    {
        $this->mocks->handler->append(...self::owned(), ...[self::concurrency(0), new Result([]), self::concurrency(null)]);

        $tester = $this->run_(['--json' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertSame(
            ['schema' => 1, 'outcome' => 'restored', 'app' => 'shop', 'environment' => 'production', 'function' => 'shop-production-tenants', 'reservedConcurrency' => null],
            self::json($tester),
        );
    }

    public function test_json_already_enabled_and_limit_kept(): void
    {
        $this->mocks->handler->append(...self::owned(), ...[self::concurrency(null)]);
        $json = self::json($this->run_(['--json' => true]));
        self::assertSame(['already_enabled', null], [$json['outcome'], $json['reservedConcurrency']]);

        $this->mocks->handler->append(...self::owned(), ...[self::concurrency(7)]);
        $tester = $this->run_(['--json' => true]);
        $tester->assertCommandIsSuccessful();
        $json = self::json($tester);
        self::assertSame(['limit_kept', 7], [$json['outcome'], $json['reservedConcurrency']]);
    }

    public function test_json_refusal_carries_the_error_key_and_params_and_exits_1(): void
    {
        $this->mocks->handler->append(...self::owned(['arco:app' => 'shop', 'arco:environment' => 'staging']));

        $tester = $this->run_(['--json' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        $json = self::json($tester);
        self::assertSame(1, $json['schema']);
        self::assertSame(['refused', 'shop', 'production'], [$json['outcome'], $json['app'], $json['environment']]);
        self::assertSame('deploy.tenant_mediator_not_owned', $json['error']['key']);
        self::assertSame(['resource' => 'shop-production-tenants', 'app' => 'shop', 'environment' => 'staging'], $json['error']['params']);
        self::assertIsString($json['error']['message']);
    }

    public function test_json_aws_failure_is_failed_and_an_unreadable_config_is_refused(): void
    {
        $this->mocks->handler->append(...self::owned(), ...[AwsMocks::error(LambdaException::class, 'GetFunctionConcurrency', 'AccessDeniedException', 403, 'no')]);
        $json = self::json($this->run_(['--json' => true]));
        self::assertSame(['failed', 'aws.access_denied'], [$json['outcome'], $json['error']['key']]);

        $tester = $this->run_(['--json' => true, '--env' => 'nowhere']);
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        $json = self::json($tester);
        self::assertSame(['refused', 'config.unknown_environment'], [$json['outcome'], $json['error']['key']]);
    }

    public function test_json_without_a_profile_does_not_ask(): void
    {
        $tester = $this->project?->tester(new TenantsRestoreCommand(fn (?string $profile, string $region) => $this->mocks->clients($region))) ?? throw new \LogicException('No project.');
        $tester->execute(['--env' => 'production', '--json' => true], ['interactive' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame('aws.no_profile_non_interactive', self::json($tester)['error']['key']);
    }

    public function test_it_reads_spanish(): void
    {
        $this->mocks->handler->append(...self::owned(), ...[self::concurrency(null)]);
        $app = \Multiapi\Arco\Cli\Application::create();
        $app->addCommands([new TenantsRestoreCommand(fn (?string $profile, string $region) => $this->mocks->clients($region))]);
        $app->setAutoExit(false);
        $tester = new \Symfony\Component\Console\Tester\ApplicationTester($app);

        $tester->run(['command' => 'tenants:restore', '--env' => 'production', '--profile' => 'p', '--lang' => 'es'], ['interactive' => false]);

        self::assertStringContainsString('ya está activa', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }
}
