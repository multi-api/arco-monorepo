<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\ConsoleRuntime;

use Multiapi\Arco\ConsoleRuntime\OutputBuffer;
use Multiapi\Arco\ConsoleRuntime\Response;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    public function test_encodes_exit_code_and_output(): void
    {
        self::assertSame('{"exitCode":3,"output":"done/ñ\n"}', Response::encode(3, "done/ñ\n"));
    }

    public function test_invalid_utf8_still_gives_valid_json(): void
    {
        $decoded = json_decode(Response::encode(0, "ok \xff\xfe end"), true, flags: JSON_THROW_ON_ERROR);

        self::assertStringStartsWith('ok ', $decoded['output']);
        self::assertStringEndsWith(' end', $decoded['output']);
    }

    public function test_escaped_output_is_cut_further_to_fit_the_lambda_response_limit(): void
    {
        // 5 MB of control characters: each becomes \u0001 (6 bytes) in JSON, 30 MB in total.
        $output = str_repeat("\x01", OutputBuffer::LIMIT - 4) . 'TAIL';

        $json = Response::encode(1, $output);

        self::assertLessThanOrEqual(Response::MAX_BYTES, strlen($json));
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $decoded['exitCode']);
        self::assertStringStartsWith(OutputBuffer::MARKER, $decoded['output']);
        self::assertStringEndsWith('TAIL', $decoded['output']);
        self::assertSame(1, substr_count($decoded['output'], '[truncated]'), 'one marker, even when already truncated');
    }
}
