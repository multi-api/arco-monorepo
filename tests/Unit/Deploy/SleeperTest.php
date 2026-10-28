<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy;

use Multiapi\Arco\Deploy\Sleeper;
use PHPUnit\Framework\TestCase;

final class SleeperTest extends TestCase
{
    public function test_on_sleep_is_called_after_each_sleep(): void
    {
        $calls = 0;
        $sleeper = new Sleeper(function () use (&$calls): void { $calls++; });

        $sleeper->sleep(0);
        $sleeper->sleep(0);

        self::assertSame(2, $calls);
    }

    public function test_a_sleeper_without_a_callback_just_sleeps(): void
    {
        (new Sleeper())->sleep(0);
        self::assertGreaterThan(0.0, (new Sleeper())->now());
    }
}
