<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\E2e;

use Multiapi\Arco\Tests\E2e\E2eReport;
use PHPUnit\Framework\TestCase;

final class E2eReportTest extends TestCase
{
    public function test_a_check_that_ran_prints_its_duration(): void
    {
        self::assertSame("[e2e] test_up                                  ok            12.3 s\n", E2eReport::line('test_up', 12.34, 'ok'));
    }

    public function test_a_skipped_check_prints_no_duration_never_an_epoch_one(): void
    {
        $line = E2eReport::line('test_logs', null, 'skipped');

        self::assertStringEndsWith("skipped          -\n", $line);
        self::assertDoesNotMatchRegularExpression('/\d{6,}/', $line);
        self::assertStringEndsWith("-\n", E2eReport::line('x', -5.0, 'skipped'));
    }
}
