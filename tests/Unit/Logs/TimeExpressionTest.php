<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Logs;

use Multiapi\Arco\Logs\TimeExpression;
use PHPUnit\Framework\TestCase;

final class TimeExpressionTest extends TestCase
{
    private const NOW = 1792065600000; // 2026-10-15 12:00:00 UTC

    public function test_relative_times(): void
    {
        self::assertSame(self::NOW - 30_000, TimeExpression::parse('30s', self::NOW));
        self::assertSame(self::NOW - 600_000, TimeExpression::parse('10m', self::NOW));
        self::assertSame(self::NOW - 7_200_000, TimeExpression::parse('2h', self::NOW));
        self::assertSame(self::NOW - 86_400_000, TimeExpression::parse('1d', self::NOW));
    }

    public function test_dates(): void
    {
        self::assertSame(self::NOW, TimeExpression::parse('2026-10-15T12:00:00Z', self::NOW));
        self::assertSame(self::NOW + 1500, TimeExpression::parse('2026-10-15 12:00:01.5 UTC', self::NOW));
    }

    public function test_rejects_anything_else(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TimeExpression::parse('soon', self::NOW);
    }

    public function test_rejects_empty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TimeExpression::parse(' ', self::NOW);
    }
}
