<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Logs;

use Multiapi\Arco\Logs\Interrupt;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\TestCase;

final class InterruptTest extends TestCase
{
    protected function tearDown(): void
    {
        if (function_exists('pcntl_signal')) {
            pcntl_signal(\SIGINT, \SIG_DFL);
            pcntl_signal(\SIGTERM, \SIG_DFL);
        }
    }

    #[RequiresFunction('pcntl_signal')]
    #[RequiresFunction('posix_kill')]
    public function test_ctrl_c_is_recorded_instead_of_ending_the_process(): void
    {
        $interrupt = new Interrupt();
        $interrupt->listen();
        self::assertFalse($interrupt->requested());

        posix_kill(getmypid(), \SIGINT);

        self::assertTrue($interrupt->requested());
    }
}
