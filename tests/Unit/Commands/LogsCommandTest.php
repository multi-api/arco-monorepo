<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Commands;

use Aws\CloudWatchLogs\Exception\CloudWatchLogsException;
use ArrayIterator;
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

    /** The real follower choice (Live Tail with a polling fallback), on mocked AWS. */
    private function defaultTester(bool $liveTailAvailable): CommandTester
    {
        $sleeper = AwsMocks::noSleep();
        return $this->project->tester(new LogsCommand(
            awsFactory: fn () => $this->mocks->clients(),
            // Drops Live Tail's http_handler, which would bypass the mock handler and reach the network.
            logsFactory: fn (AwsClients $aws, array $overrides) => $aws->logs(),
            sleeper: $sleeper,
            stop: fn (): bool => $sleeper->slept >= 2,
            liveTailAvailable: fn (): bool => $liveTailAvailable,
        ));
    }

    public function test_follow_announces_live_tail_when_it_is_used(): void
    {
        $this->mocks->handler->append(
            new Result(['events' => []]),                                   // history
            new Result(['responseStream' => new ArrayIterator([])]),        // StartLiveTail, ends at once
            new Result(['events' => [['eventId' => 'c1', 'timestamp' => self::T, 'message' => 'polled line']]]),
        );

        $tester = $this->defaultTester(true);
        $tester->execute(['--env' => 'staging', '--profile' => 'p', '--source' => 'console', '--follow' => true], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        self::assertStringContainsString('with CloudWatch Live Tail', $display);
        self::assertStringContainsString('The Live Tail stream stopped sending data', $display);
        self::assertStringContainsString('polled line', $display);
        self::assertSame(['GetCallerIdentity', 'FilterLogEvents', 'StartLiveTail', 'FilterLogEvents'], $this->mocks->calls);
    }

    /** Live test: the last history event came again from Live Tail, which has no eventId. */
    public function test_follow_does_not_print_a_history_event_again_when_live_tail_replays_it(): void
    {
        $stream = '2026/10/15/[$LATEST]abc';
        $group = '/aws/lambda/shop-staging-console';
        $this->mocks->handler->append(
            new Result(['events' => [['eventId' => 'c1', 'logStreamName' => $stream, 'timestamp' => self::T, 'message' => 'history line']]]),
            new Result(['responseStream' => new ArrayIterator([['sessionUpdate' => ['sessionResults' => [
                ['logGroupIdentifier' => "arn:aws:logs:us-east-1:111111111111:log-group:$group", 'logStreamName' => $stream, 'timestamp' => self::T, 'message' => 'history line'],
                ['logGroupIdentifier' => "arn:aws:logs:us-east-1:111111111111:log-group:$group", 'logStreamName' => $stream, 'timestamp' => self::T + 5, 'message' => 'live line'],
            ]]]])]),
            new Result(['events' => [
                ['eventId' => 'c1', 'logStreamName' => $stream, 'timestamp' => self::T, 'message' => 'history line'],
                ['eventId' => 'c2', 'logStreamName' => $stream, 'timestamp' => self::T + 5, 'message' => 'live line'],
            ]]),
        );

        $tester = $this->defaultTester(true);
        $tester->execute(['--env' => 'staging', '--profile' => 'p', '--source' => 'console', '--follow' => true], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        self::assertSame(1, substr_count($tester->getDisplay(), 'history line'));
        self::assertSame(1, substr_count($tester->getDisplay(), 'live line'));
    }

    public function test_follow_polls_directly_without_url_fopen(): void
    {
        $this->mocks->handler->append(
            new Result(['events' => []]),
            new Result(['events' => [['eventId' => 'c1', 'timestamp' => self::T, 'message' => 'polled line']]]),
        );

        $tester = $this->defaultTester(false);
        $tester->execute(['--env' => 'staging', '--profile' => 'p', '--source' => 'console', '--follow' => true], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        self::assertStringContainsString('by polling every 2 s', $display);
        self::assertStringNotContainsString('Live Tail', $display);
        self::assertStringContainsString('polled line', $display);
        self::assertNotContains('StartLiveTail', $this->mocks->calls);
    }

    public function test_follow_without_any_log_group_yet_says_so_and_polls(): void
    {
        $this->mocks->handler->append(
            self::missing(),                                                // history
            new Result(['events' => [['eventId' => 'c1', 'timestamp' => self::T, 'message' => 'first ever']]]),
        );

        $tester = $this->defaultTester(true);
        $tester->execute(['--env' => 'staging', '--profile' => 'p', '--source' => 'console', '--follow' => true], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        self::assertStringContainsString('No logs yet in /aws/lambda/shop-staging-console', $display);
        self::assertStringContainsString('by polling every 2 s', $display);
        self::assertStringContainsString('first ever', $display);
        self::assertNotContains('StartLiveTail', $this->mocks->calls);
    }
}
