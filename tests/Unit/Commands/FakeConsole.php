<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Commands;

use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Console\CommandResult;
use Multiapi\Arco\Console\Console;
use Multiapi\Arco\Deploy\ConsoleFunction;

final class FakeConsole implements Console
{
    /** @var list<array{0: string, 1: string, 2: int|string|null, 3: string|null}> */
    public array $calls = [];

    /**
     * @param list<CommandResult|\Throwable> $results returned in order by run() and tinker(); a Throwable is thrown by run()
     * @param (\Closure(string): void)|null $onCall receives "console:<command>@<qualifier>" for run()
     */
    public function __construct(private array $results = [], private readonly ?\Closure $onCall = null)
    {
    }

    public function run(AppConfig $config, string $command, ?int $timeout = null, string $qualifier = ConsoleFunction::ALIAS): CommandResult
    {
        $this->calls[] = ['run', $command, $timeout, $qualifier];
        if ($this->onCall !== null) {
            ($this->onCall)("console:$command@$qualifier");
        }
        $result = array_shift($this->results) ?? throw new \LogicException("No result queued for \"$command\".");
        return $result instanceof \Throwable ? throw $result : $result;
    }

    public function start(AppConfig $config, string $command, ?int $timeout = null): void
    {
        $this->calls[] = ['start', $command, $timeout, ConsoleFunction::ALIAS];
    }

    public function tinker(AppConfig $config, string $code, ?string $context): CommandResult
    {
        $this->calls[] = ['tinker', $code, $context, null];
        $result = array_shift($this->results) ?? throw new \LogicException('No tinker reply queued.');
        return $result instanceof \Throwable ? throw $result : $result;
    }
}
