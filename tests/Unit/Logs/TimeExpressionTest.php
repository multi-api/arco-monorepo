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

    public function test_dotted_dates(): void
    {
        $expected = (int) (new \DateTimeImmutable('2026-10-15 00:00:00'))->format('Uv');

        self::assertSame($expected, TimeExpression::parse('15.10.2026', self::NOW));
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

    /** @return iterable<string, array{string}> */
    public static function notTimes(): iterable
    {
        yield 'bare number' => ['1500'];
        yield 'bare year' => ['2026'];
        yield 'decimal duration' => ['1.5h'];
        yield 'decimal number' => ['1.5'];
        yield 'unknown unit' => ['10w'];
        yield 'upper-case unit' => ['10M'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('notTimes')]
    public function test_rejects_numbers_without_a_valid_unit(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TimeExpression::parse($value, self::NOW);
    }

    public function test_rejects_an_overflowing_duration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TimeExpression::parse('99999999999999999999d', self::NOW);
    }

    public function test_rejects_a_duration_before_1970(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TimeExpression::parse('30000d', self::NOW);
    }
}
