<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Logs;

use Multiapi\Arco\Logs\Arrays;
use PHPUnit\Framework\TestCase;

final class ArraysTest extends TestCase
{
    public function test_keyed_turns_every_key_into_a_string(): void
    {
        self::assertSame(['0' => 'a', 'b' => 2], Arrays::keyed(['a', 'b' => 2]));
        self::assertSame([], Arrays::keyed([]));
    }
}
