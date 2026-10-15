<?php declare(strict_types=1);

namespace Multiapi\Arco\Tinker;

use Psy\Configuration;
use Psy\Shell;

/** PsySH on the user's machine (history, multi-line input, completion) whose statements run remotely. */
final class RemoteShell extends Shell
{
    public function __construct(Configuration $config, private readonly RemoteExecution $remote)
    {
        // The promoted property is set before Shell's constructor reads the loop listeners.
        parent::__construct($config);
    }

    /** @return array<mixed> PsySH's own listeners (untyped upstream), then the remote execution */
    protected function getDefaultLoopListeners(): array
    {
        return [...parent::getDefaultLoopListeners(), $this->remote];
    }

    /** @param \Closure(TinkerReply): void $render */
    public static function start(TinkerSession $session, \Closure $render): int
    {
        return (new self(new Configuration(['updateCheck' => 'never']), new RemoteExecution($session, $render)))->run();
    }
}
