<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Commands;

use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Deploy\AppStatus;
use Multiapi\Arco\Deploy\Deployer;
use Multiapi\Arco\Deploy\DeployResult;
use Multiapi\Arco\Package\Package;

final class FakeDeployer implements Deployer
{
    /** @var list<string> */
    public array $calls = [];
    /** @var array<string, string> */
    public array $variables = [];
    public ?Package $package = null;

    public function __construct(public ?string $previousRevision = null, public ?AppStatus $status = null, public bool $domainResources = false)
    {
    }

    public function deploy(AppConfig $config, Package $package, array $variables, ?callable $onStep = null): DeployResult
    {
        $this->calls[] = 'deploy';
        $this->variables = $variables;
        $this->package = $package;
        self::assertZip($package->path);
        return new DeployResult('abc.on.aws', 'rev-2', $this->previousRevision, 'bucket', 'key');
    }

    public function rollback(AppConfig $config, string $revisionId): void { $this->calls[] = "rollback:$revisionId"; }
    public function attachDomains(AppConfig $config, DeployResult $result, array $cachedPaths, ?callable $onStep = null): DeployResult
    {
        $this->calls[] = 'domains:' . implode(',', $cachedPaths);
        return $result->withDomains($config->domains);
    }

    public function hasDomainResources(AppConfig $config): bool { return $this->domainResources; }
    public function remove(AppConfig $config, ?callable $onStep = null): void { $this->calls[] = 'remove'; }
    public function describe(AppConfig $config): ?AppStatus { $this->calls[] = 'describe'; return $this->status; }

    private static function assertZip(string $path): void
    {
        if (! is_file($path)) {
            throw new \LogicException("Package $path does not exist during deploy");
        }
    }
}
