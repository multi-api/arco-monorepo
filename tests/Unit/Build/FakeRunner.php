<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Build;

use Multiapi\Arco\Build\BuildException;
use Multiapi\Arco\Build\CommandRunner;

final class FakeRunner implements CommandRunner
{
    /** @var list<array{command: list<string>, cwd: string, env: array<string, string>}> */
    public array $calls = [];

    /**
     * @param array<string, string> $failOn command[1] => output
     * @param (\Closure(list<string>, string): void)|null $onRun called before each command
     */
    public function __construct(private readonly array $failOn = [], private readonly ?\Closure $onRun = null)
    {
    }

    public function run(array $command, string $cwd, array $env = []): string
    {
        $this->calls[] = ['command' => $command, 'cwd' => $cwd, 'env' => $env];
        if ($this->onRun !== null) {
            ($this->onRun)($command, $cwd);
        }
        $key = $command[1] ?? '';
        if (isset($this->failOn[$key])) {
            throw BuildException::make('build.command_failed', ['command' => implode(' ', $command), 'output' => $this->failOn[$key]]);
        }
        return '';
    }
}
