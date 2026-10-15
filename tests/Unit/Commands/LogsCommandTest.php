<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Commands;

use Aws\CloudWatchLogs\Exception\CloudWatchLogsException;
use Aws\Result;
use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Commands\LogsCommand;
use Multiapi\Arco\Logs\PollingFollower;
use Multiapi\Arco\Tests\Unit\Deploy\AwsMocks;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class LogsCommandTest extends TestCase
{
    private const T = 1792065600000; // 2026-10-15 12:00:00 UTC
    private ProjectFixture $project;
    private AwsMocks $mocks;
    private string $timezone;

    protected function setUp(): void
    {
        $this->project = new ProjectFixture(ProjectFixture::standardConfig());
        $this->mocks = new AwsMocks();
        $this->mocks->handler->append(new Result(['Account' => '111111111111']));
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
    }

    protected function tearDown(): void
    {
        $this->project->restore();
        date_default_timezone_set($this->timezone);
    }

    private function tester(?\Closure $stop = null): CommandTester
    {
        $sleeper = AwsMocks::noSleep();
        return $this->project->tester(new LogsCommand(
            awsFactory: fn () => $this->mocks->clients(),
            followerFactory: fn (AwsClients $aws) => new PollingFollower($aws->logs(), $sleeper),
            sleeper: $sleeper,
            stop: $stop ?? fn (): bool => $sleeper->slept >= 2,
        ));
    }

    private static function missing(): CloudWatchLogsException
    {
        return AwsMocks::error(CloudWatchLogsException::class, 'FilterLogEvents', 'ResourceNotFoundException', 400);
    }

    public function test_prints_web_and_console_events_merged_by_time(): void
    {
        $this->mocks->handler->append(
            new Result(['events' => [['eventId' => 'w1', 'timestamp' => self::T + 2000, 'message' => '{"message":"Order placed","level_name":"INFO","context":{"requestId":"r-1"}}']]]),
            self::missing(),                                                     // web .local
            new Result(['events' => [
                ['eventId' => 'c1', 'timestamp' => self::T + 1000, 'message' => "START RequestId: abc Version: 7\n"],
                ['eventId' => 'c2', 'timestamp' => self::T + 1500, 'message' => "Migrating: 2026_10_15_create_orders\n"],
            ]]),
        );

        $tester = $this->tester();
        $tester->execute(['--env' => 'staging', '--profile' => 'p'], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        self::assertStringNotContainsString('START RequestId', $display);
        $migrating = strpos($display, 'Migrating: 2026_10_15_create_orders');
        $order = strpos($display, '12:00:02 INFO    Order placed [requestId=r-1]');
        self::assertIsInt($migrating);
        self::assertIsInt($order);
        self::assertLessThan($order, $migrating);
        self::assertSame(['/aws/lambda/web/shop-staging', '/aws/lambda/web/shop-staging.local', '/aws/lambda/shop-staging-console'], array_column(array_slice($this->mocks->params, 1), 'logGroupName'));
        self::assertSame(1_000_000_000 - 600_000, $this->mocks->params[1]['startTime'], '--since=10m by default');
        self::assertArrayNotHasKey('filterPattern', $this->mocks->params[1]);
    }

    public function test_level_request_and_text_filters(): void
    {
        $this->mocks->handler->append(
            new Result(['events' => [
                ['eventId' => '1', 'timestamp' => self::T, 'message' => '{"message":"seen","level_name":"INFO","context":{"requestId":"r-1"}}'],
                ['eventId' => '2', 'timestamp' => self::T, 'message' => '{"message":"Order failed","level_name":"ERROR","context":{"requestId":"r-1"}}'],
                ['eventId' => '3', 'timestamp' => self::T, 'message' => '{"message":"Order failed","level_name":"ERROR","context":{"requestId":"r-2"}}'],
            ]]),
            self::missing(),
        );

        $tester = $this->tester();
        $tester->execute(['--env' => 'staging', '--profile' => 'p', '--source' => 'web', '--level' => 'warning', '--request' => 'r-1', '--filter' => 'Order'], ['interactive' => false]);

        $display = $tester->getDisplay();
        self::assertSame('"Order" "r-1"', $this->mocks->params[1]['filterPattern']);
        self::assertSame(1, substr_count($display, 'Order failed'));
        self::assertStringNotContainsString('seen', $display);
    }

    public function test_json_prints_raw_events_one_per_line(): void
    {
        $this->mocks->handler->append(new Result(['events' => [['eventId' => 'c1', 'timestamp' => self::T, 'message' => "plain\n", 'logStreamName' => 's1']]]));

        $tester = $this->tester();
        $tester->execute(['--env' => 'staging', '--profile' => 'p', '--source' => 'console', '--json' => true], ['interactive' => false]);

        self::assertSame(
            ['timestamp' => self::T, 'logGroup' => '/aws/lambda/shop-staging-console', 'logStream' => 's1', 'message' => "plain\n"],
            json_decode(trim($tester->getDisplay()), true),
        );
    }

    public function test_follow_prints_new_events_once(): void
    {
        $this->mocks->handler->append(
            new Result(['events' => [['eventId' => 'c1', 'timestamp' => self::T, 'message' => 'history line']]]),
            new Result(['events' => [['eventId' => 'c1', 'timestamp' => self::T, 'message' => 'history line'], ['eventId' => 'c2', 'timestamp' => self::T + 1, 'message' => 'new line']]]),
        );

        $tester = $this->tester();
        $tester->execute(['--env' => 'staging', '--profile' => 'p', '--source' => 'console', '--follow' => true], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        self::assertSame(1, substr_count($tester->getDisplay(), 'history line'));
        self::assertSame(1, substr_count($tester->getDisplay(), 'new line'));
    }

    public function test_an_environment_without_logs_yet_is_not_an_error(): void
    {
        $this->mocks->handler->append(self::missing(), self::missing(), self::missing());

        $tester = $this->tester();
        $tester->execute(['--env' => 'staging', '--profile' => 'p'], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('/aws/lambda/shop-staging-console', $tester->getDisplay());
    }

    public function test_invalid_options_fail_before_any_aws_call(): void
    {
        $tester = $this->tester();
        foreach ([['--follow' => true, '--until' => '5m'], ['--source' => 'db'], ['--level' => 'loud'], ['--since' => 'soon']] as $options) {
            $tester->execute(['--env' => 'staging', '--profile' => 'p', ...$options], ['interactive' => false]);
            self::assertSame(1, $tester->getStatusCode(), json_encode($options) ?: '');
        }
        self::assertSame([], $this->mocks->calls);
    }

    public function test_invalid_level_and_source_show_a_translated_error(): void
    {
        $tester = $this->tester();

        $tester->execute(['--env' => 'staging', '--profile' => 'p', '--level' => 'loud'], ['interactive' => false]);
        self::assertStringContainsString('--level must be one of debug, info, warning, error, got "loud"', $tester->getDisplay());

        $tester->execute(['--env' => 'staging', '--profile' => 'p', '--source' => 'db'], ['interactive' => false]);
        self::assertStringContainsString('--source must be all, web or console, got "db"', $tester->getDisplay());

        $tester->execute(['--env' => 'staging', '--profile' => 'p', '--since' => '1500'], ['interactive' => false]);
        self::assertStringContainsString('"1500" is not a time', $tester->getDisplay());

        self::assertSame([], $this->mocks->calls);
    }

    public function test_level_is_case_insensitive(): void
    {
        $this->mocks->handler->append(new Result(['events' => [
            ['eventId' => '1', 'timestamp' => self::T, 'message' => '{"message":"quiet","level_name":"INFO"}'],
            ['eventId' => '2', 'timestamp' => self::T, 'message' => '{"message":"loud","level_name":"ERROR"}'],
        ]]));

        $tester = $this->tester();
        $tester->execute(['--env' => 'staging', '--profile' => 'p', '--source' => 'console', '--level' => 'ERROR'], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('loud', $tester->getDisplay());
        self::assertStringNotContainsString('quiet', $tester->getDisplay());
    }
}
