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
    /** thrown by attachDomains(), e.g. the DNS profile's credentials expiring mid-deploy */
    public ?\Throwable $domainsError = null;
    /** thrown by describe(), e.g. expired credentials */
    public ?\Throwable $describeError = null;
    /** answer of consoleExists() */
    public bool $consoleExists = false;
    /** how many times consoleExists() was asked */
    public int $consoleLookups = 0;
    /** thrown by removeConsole(), e.g. AWS refusing lambda:DeleteFunction */
    public ?\Throwable $removeConsoleError = null;
    /** whether the scheduler rule exists, for syncScheduler() with the scheduler off */
    public bool $schedulerRuleExists = false;
    /** thrown by syncScheduler(), e.g. AWS refusing events:PutRule */
    public ?\Throwable $syncSchedulerError = null;
    /** thrown by applyLogRetention(), e.g. AWS refusing logs:PutRetentionPolicy */
    public ?\Throwable $logRetentionError = null;
    /** whether the resources stack exists: deployResources() then acts even without queues */
    public bool $resourcesStack = false;
    /** thrown by deployResources(), e.g. a queue name retained in AWS */
    public ?\Throwable $resourcesError = null;
    /** @var list<array{0: string, 1: array<string, string|int>}> steps reported by remove() */
    public array $removeSteps = [];

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
        if ($this->domainsError !== null) {
            throw $this->domainsError;
        }
        return $result->withDomains($config->domains);
    }

    public function hasDomainResources(AppConfig $config): bool { return $this->domainResources; }
    public function remove(AppConfig $config, ?callable $onStep = null): void
    {
        $this->calls[] = 'remove';
        foreach ($this->removeSteps as [$key, $params]) {
            $onStep !== null && $onStep($key, $params);
        }
    }
    public function consoleExists(AppConfig $config): bool { $this->consoleLookups++; return $this->consoleExists; }
    public function removeConsole(AppConfig $config, \Closure $onStep): void { $this->calls[] = 'removeConsole'; if ($this->removeConsoleError !== null) { throw $this->removeConsoleError; } }
    public function removeScheduler(AppConfig $config, \Closure $onStep): void
    {
        $this->calls[] = 'scheduler:remove';
        if ($this->schedulerRuleExists) {
            $onStep('deploy.scheduler_removed', []);
        }
    }
    public function syncScheduler(AppConfig $config, \Closure $onStep): void
    {
        $this->calls[] = 'scheduler:' . ($config->scheduler ?? 'off');
        if ($this->syncSchedulerError !== null) {
            throw $this->syncSchedulerError;
        }
        if ($config->scheduler !== null) {
            $onStep('deploy.scheduler_set', ['every' => $config->scheduler]);
        } elseif ($this->schedulerRuleExists) {
            $onStep('deploy.scheduler_removed', []);
        }
    }
    public function applyLogRetention(AppConfig $config, \Closure $onStep): void
    {
        if ($config->logRetention === null) {
            return;
        }
        $this->calls[] = 'retention:' . ($config->logRetention === false ? 'never' : $config->logRetention);
        if ($this->logRetentionError !== null) {
            throw $this->logRetentionError;
        }
        $onStep(...\Multiapi\Arco\Deploy\AwsDeployer::logRetentionStep($config->logRetention, $config->consoleEnabled() ? 'all' : 'web'));
    }
    public function deployResources(AppConfig $config, \Closure $onStep): void
    {
        // Like AwsDeployer: nothing happens without created queues or a stack left from earlier ones.
        $created = array_filter($config->queues, fn ($queue) => $queue->existing === null);
        if ($created === [] && ! $this->resourcesStack) {
            return;
        }
        $this->calls[] = 'resources';
        if ($this->resourcesError !== null) {
            throw $this->resourcesError;
        }
        foreach ($config->queues as $queue) {
            if ($queue->existing === null) {
                $onStep('deploy.queue_created', ['queue' => $queue->name, 'name' => $queue->physicalName($config)]);
            }
        }
    }
    public function describe(AppConfig $config): ?AppStatus { $this->calls[] = 'describe'; return $this->describeError !== null ? throw $this->describeError : $this->status; }
}
