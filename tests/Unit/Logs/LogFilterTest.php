<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Logs;

use Multiapi\Arco\Logs\LogFilter;
use Multiapi\Arco\Logs\LogLine;
use PHPUnit\Framework\TestCase;

final class LogFilterTest extends TestCase
{
    public function test_level_keeps_that_level_and_above_and_lines_without_a_level(): void
    {
        $filter = new LogFilter('warning');

        self::assertFalse($filter->accepts(LogLine::parse('{"message":"m","level_name":"INFO"}')));
        self::assertTrue($filter->accepts(LogLine::parse('{"message":"m","level_name":"WARNING"}')));
        self::assertTrue($filter->accepts(LogLine::parse('{"message":"m","level_name":"CRITICAL"}')));
        self::assertTrue($filter->accepts(LogLine::parse('PHP Fatal error: x')), 'plain text may be a fatal error');
    }

    public function test_request_keeps_only_lines_of_that_request(): void
    {
        $filter = new LogFilter(null, 'r-1');

        self::assertTrue($filter->accepts(LogLine::parse('{"message":"m","level_name":"INFO","context":{"requestId":"r-1"}}')));
        self::assertFalse($filter->accepts(LogLine::parse('{"message":"m","level_name":"INFO","context":{"requestId":"r-2"}}')));
        self::assertFalse($filter->accepts(LogLine::parse('plain line')));
    }

    public function test_text_and_request_become_one_cloudwatch_pattern(): void
    {
        self::assertNull(LogFilter::pattern(null, null));
        self::assertSame('"Order failed"', LogFilter::pattern('Order failed', null));
        self::assertSame('"say \\"hi\\"" "r-1"', LogFilter::pattern('say "hi"', 'r-1'));
    }
}
