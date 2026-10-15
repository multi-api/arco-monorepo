<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\ConsoleRuntime;

use Multiapi\Arco\ConsoleRuntime\OutputBuffer;
use PHPUnit\Framework\TestCase;

final class OutputBufferTest extends TestCase
{
    public function test_keeps_everything_under_the_limit(): void
    {
        $buffer = new OutputBuffer(10);
        $buffer->append('abc');
        $buffer->append('def');

        self::assertFalse($buffer->truncated());
        self::assertSame('abcdef', $buffer->contents());
    }

    public function test_keeps_the_end_with_a_marker_when_over_the_limit(): void
    {
        $buffer = new OutputBuffer(10);
        $buffer->append('abcdefghij');
        $buffer->append('klm');

        self::assertTrue($buffer->truncated());
        self::assertSame("[truncated]\ndefghijklm", $buffer->contents());
    }

    public function test_many_small_chunks_keep_the_last_bytes(): void
    {
        $buffer = new OutputBuffer(10);
        for ($i = 0; $i < 100; $i++) {
            $buffer->append(sprintf('%03d', $i));
        }

        // 300 bytes "000001…099" in 3-byte chunks; the last 10 bytes are "6097098099".
        self::assertSame(OutputBuffer::MARKER . '6097098099', $buffer->contents());
    }

    public function test_the_default_limit_is_five_megabytes(): void
    {
        self::assertSame(5 * 1024 * 1024, OutputBuffer::LIMIT);
    }
}
