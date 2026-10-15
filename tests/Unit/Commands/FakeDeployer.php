<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Commands;

use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Deploy\AppStatus;
use Multiapi\Arco\Deploy\Deployer;
use Multiapi\Arco\Deploy\DeployResult;
use Multiapi\Arco\Deploy\PreparedDeploy;
use Multiapi\Arco\Package\ConsoleArtifacts;
use Multiapi\Arco\Package\Package;

final class FakeDeployer implements Deployer
{
    /** @var list<string> */
    public array $calls = [];
    /** @var array<string, string> */
    public array $variables = [];
    public ?Package $package = null;
    public ?ConsoleArtifacts $console = null;
    /** step reported by prune(), as AwsDeployer does when AWS refuses the cleanup */
    public ?string $pruneWarning = null;
    /** thrown by release(), e.g. AWS refusing the traffic shift */
    public ?\Throwable $releaseError = null;
    /** thrown by activateConsole(), e.g. AWS refusing lambda:UpdateAlias */
    public ?\Throwable $activateError = null;

    public function __construct(
        public ?string $previousRevision = null,
        public ?AppStatus $status = null,
        public bool $domainResources = false,
        public ?string $consoleVersion = null,
        public ?string $previousConsoleVersion = null,
    ) {
    }

    public function prepare(AppConfig $config, Package $package, ?ConsoleArtifacts $console, array $variables, ?callable $onStep = null): PreparedDeploy
    {
        $this->calls[] = 'prepare';
        $this->variables = $variables;
        $this->package = $package;
        $this->console = $console;
        if (! is_file($package->path)) {
            throw new \LogicException("Package $package->path does not exist during deploy");
        }
        return new PreparedDeploy('rev-2', $this->previousRevision, 'bucket', 'key', $this->consoleVersion, $this->previousConsoleVersion);
    }

    public function release(AppConfig $config, PreparedDeploy $prepared, ?callable $onStep = null): DeployResult
    {
        $this->calls[] = 'release';
        if ($this->releaseError !== null) {
            throw $this->releaseError;
        }
        return new DeployResult('abc.on.aws', $prepared->revisionId, $prepared->previousRevisionId, $prepared->bucket, $prepared->key, [], $prepared->consoleVersion, $prepared->previousConsoleVersion);
    }

    public function activateConsole(AppConfig $config, string $version): void
    {
        $this->calls[] = "live:$version";
        if ($this->activateError !== null) {
            throw $this->activateError;
        }
    }

    public function prune(AppConfig $config, PreparedDeploy $prepared, ?callable $onStep = null): void
    {
        $this->calls[] = 'prune';
        if ($this->pruneWarning !== null && $onStep !== null) {
            $onStep($this->pruneWarning, ['function' => 'f', 'error' => 'AccessDenied']);
        }
    }

    public function rollback(AppConfig $config, string $revisionId, ?string $consoleVersion = null): void
    {
        $this->calls[] = "rollback:$revisionId" . ($consoleVersion === null ? '' : ":console=$consoleVersion");
    }

    public function attachDomains(AppConfig $config, DeployResult $result, array $cachedPaths, ?callable $onStep = null): DeployResult
    {
        $this->calls[] = 'domains:' . implode(',', $cachedPaths);
        return $result->withDomains($config->domains);
    }

    public function hasDomainResources(AppConfig $config): bool { return $this->domainResources; }
    public function remove(AppConfig $config, ?callable $onStep = null): void { $this->calls[] = 'remove'; }
    public function describe(AppConfig $config): ?AppStatus { $this->calls[] = 'describe'; return $this->status; }
}
