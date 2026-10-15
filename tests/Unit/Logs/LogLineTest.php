<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Logs;

use Multiapi\Arco\Logs\LogEvent;
use Multiapi\Arco\Logs\LogLine;
use PHPUnit\Framework\TestCase;

final class LogLineTest extends TestCase
{
    public function test_monolog_json_from_arco_laravel(): void
    {
        $line = LogLine::parse('{"message":"Payment failed","context":{"requestId":"r-1","order":7},"level":400,"level_name":"ERROR","channel":"production","datetime":"2026-10-15T12:00:01+00:00","extra":{}}' . "\n");

        self::assertSame(LogLine::APP, $line->kind);
        self::assertSame('error', $line->level);
        self::assertSame('Payment failed', $line->text);
        self::assertSame('r-1', $line->requestId);
        self::assertSame(['order' => 7], $line->context);
    }

    public function test_lambda_json_lines_and_monolog_numbers(): void
    {
        self::assertSame('warning', LogLine::parse('{"timestamp":"x","level":"WARN","message":"slow","requestId":"abc"}')->level);
        self::assertSame('abc', LogLine::parse('{"timestamp":"x","level":"WARN","message":"slow","requestId":"abc"}')->requestId);
        self::assertSame('notice', LogLine::parse('{"message":"m","level":250}')->level);
    }

    public function test_plain_text_is_kept_as_is(): void
    {
        $line = LogLine::parse("PHP Fatal error:  Allowed memory size exhausted\n");

        self::assertSame(LogLine::TEXT, $line->kind);
        self::assertNull($line->level);
        self::assertSame('PHP Fatal error:  Allowed memory size exhausted', $line->text);
    }

    public function test_web_function_init_reports(): void
    {
        $ok = LogLine::parse('{"time":"t","type":"platform.initReport","record":{"status":"success","metrics":{"totalDurationMs":812.4}}}');
        self::assertSame(LogLine::PLATFORM, $ok->kind);
        self::assertSame('cold_start', $ok->summary);
        self::assertSame(812, $ok->durationMs);

        $failed = LogLine::parse('{"time":"t","type":"platform.initReport","record":{"status":"error","errorType":"Runtime.ExitError"}}');
        self::assertSame('init_failed', $failed->summary);
        self::assertSame('Runtime.ExitError', $failed->errorType);
        self::assertSame('error', $failed->level);

        self::assertNull(LogLine::parse('{"time":"t","type":"platform.start","record":{}}')->summary);
    }

    public function test_console_function_platform_lines(): void
    {
        self::assertSame(LogLine::PLATFORM, LogLine::parse("START RequestId: abc Version: 7\n")->kind);
        self::assertNull(LogLine::parse("END RequestId: abc\n")->summary);
        $report = LogLine::parse("REPORT RequestId: abc\tDuration: 12.31 ms\tBilled Duration: 13 ms\tMemory Size: 2048 MB\tMax Memory Used: 80 MB\tInit Duration: 640.52 ms\n");
        self::assertSame('cold_start', $report->summary);
        self::assertSame(641, $report->durationMs);
        self::assertNull(LogLine::parse("REPORT RequestId: abc\tDuration: 12.31 ms\n")->summary);
        $init = LogLine::parse("INIT_REPORT Init Duration: 120.00 ms\tPhase: init\tStatus: error\tError Type: Runtime.ExitError\n");
        self::assertSame('init_failed', $init->summary);
        self::assertSame('Runtime.ExitError', $init->errorType);
    }

    public function test_arco_runtime_lines(): void
    {
        self::assertSame('error', LogLine::parse('{"arco":"command_failed","arguments":["migrate"],"exitCode":1,"ms":5}')->level);
        self::assertSame('debug', LogLine::parse('{"arco":"boot","ms":1}')->level);
        self::assertSame(LogLine::ARCO, LogLine::parse('{"arco":"boot","ms":1}')->kind);
        self::assertSame('error', LogLine::parse('{"arco":"frankenphp_exit","ms":9,"code":137,"signal":null}')->level, 'the web server crashed');
        self::assertSame('debug', LogLine::parse('{"arco":"binary_ready","seconds":2,"bytes":1}')->level);
    }

    public function test_events_from_filter_log_events_and_live_tail(): void
    {
        $filtered = LogEvent::fromAws(['eventId' => 'e1', 'timestamp' => 1792065601000, 'message' => 'x', 'logStreamName' => 's'], '/aws/lambda/shop-production-console');
        self::assertSame('e1', $filtered->id);
        self::assertSame('/aws/lambda/shop-production-console', $filtered->group);

        $live = LogEvent::fromAws(['timestamp' => 1792065601000, 'message' => 'x', 'logStreamName' => 's', 'logGroupIdentifier' => 'arn:aws:logs:us-east-1:111111111111:log-group:/aws/lambda/web/shop-production'], '');
        self::assertSame('/aws/lambda/web/shop-production', $live->group);
        self::assertSame(64, strlen($live->id));
    }
}
