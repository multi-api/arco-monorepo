<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Multiapi\Arco\Console\CommandResult;

/** A deploy command failed or crashed; the deploy stopped before moving traffic. */
final class DeployCommandFailed extends \RuntimeException
{
    /** @param list<string> $ran commands that ran, the failing one last */
    public function __construct(public readonly string $command, public readonly CommandResult $result, public readonly array $ran)
    {
        parent::__construct("Deploy command \"$command\" failed.");
    }
}
