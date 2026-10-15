<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Commands;

use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Console\CommandResult;
use Multiapi\Arco\Console\Console;
use Multiapi\Arco\Deploy\ConsoleFunction;
use Multiapi\Arco\Tinker\TinkerSession;

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

    /** A console answer as arco:tinker prints it. @param array<string, mixed> $reply */
    public static function tinkerReply(array $reply, int $exitCode = 0, string $before = ''): CommandResult
    {
        $reply += ['output' => '', 'presented' => null, 'context' => null, 'dropped' => [], 'exception' => null];
        return new CommandResult($exitCode, $before . TinkerSession::MARKER . base64_encode((string) json_encode($reply)) . "\n");
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
