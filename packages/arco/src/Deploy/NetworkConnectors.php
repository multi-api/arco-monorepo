<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Aws\Exception\AwsException;
use Aws\LambdaCore\Exception\LambdaCoreException;
use Aws\LambdaCore\LambdaCoreClient;
use Multiapi\Arco\Config\AppConfig;

/**
 * The Lambda network connector of an environment with vpc() and its operator role: the role the service
 * assumes to manage the network interfaces. One connector per environment, named like the web function.
 * The lambda-core API cannot read a connector's tags, so ownership is the operator role: a connector with
 * this name whose OperatorRole is Arco's role for the environment (IAM can read that role's tags).
 */
final class NetworkConnectors
{
    private const SERVICE = 'network-connectors.lambda.amazonaws.com';
    private const TIMEOUT = 600;
    private const POLL = 10;
    private const SUFFIX = '-network';

    public function __construct(
        private readonly LambdaCoreClient $core,
        private readonly ExecutionRole $roles,
        private readonly string $account,
        private readonly Sleeper $sleeper,
    ) {
    }

    /** `arco-<app>-<env>-network`, at most 64 characters; the suffix is kept so it never equals the execution role. */
    public static function operatorRoleName(AppConfig $config): string
    {
        return substr('arco-' . $config->functionName(), 0, 64 - strlen(self::SUFFIX)) . self::SUFFIX;
    }

    /** @return array<string, mixed> */
    public static function operatorPolicy(): array
    {
        return [
            'Version' => '2012-10-17',
            'Statement' => [
                [
                    'Effect' => 'Allow',
                    'Action' => ['ec2:CreateNetworkInterface', 'ec2:CreateTags'],
                    'Resource' => '*',
                    'Condition' => ['StringEquals' => ['ec2:ManagedResourceOperator' => self::SERVICE]],
                ],
                [
                    'Effect' => 'Allow',
                    'Action' => ['ec2:DeleteNetworkInterface', 'ec2:DescribeNetworkInterfaces', 'ec2:DescribeSubnets', 'ec2:DescribeSecurityGroups'],
                    'Resource' => '*',
                ],
            ],
        ];
    }

    private function roleArn(AppConfig $config): string
    {
        return "arn:aws:iam::{$this->account}:role/" . self::operatorRoleName($config);
    }

    /**
     * Creates the operator role and the connector, or updates the connector to the configured subnets and
     * groups, and waits until it is usable. Returns the version ARN a revision pins.
     *
     * @param array<string, string> $tags
     * @param callable(string, array<string, string|int>): void $step
     * @throws DeployException deploy.network_foreign, deploy.network_failed, deploy.network_timeout
     */
    public function ensure(AppConfig $config, array $tags, callable $step): string
    {
        $vpc = $config->vpc ?? throw new \LogicException('NetworkConnectors::ensure needs vpc().');
        $name = $config->functionName();
        $step('deploy.step_network', ['connector' => $name, 'subnets' => implode(', ', $vpc->subnets), 'groups' => implode(', ', $vpc->securityGroups)]);

        ['arn' => $roleArn] = $this->roles->ensure(self::operatorRoleName($config), self::operatorPolicy(), $tags, null, $step, $config->permissionsBoundary);
        $desired = [
            'SubnetIds' => $vpc->subnets,
            'SecurityGroupIds' => $vpc->securityGroups,
            'NetworkProtocol' => 'IPv4',
            'AssociatedComputeResourceTypes' => ['WebFunction'],
        ];

        $current = $this->find($config);
        if ($current === null) {
            $created = $this->core->createNetworkConnector([
                'Name' => $name,
                'Configuration' => ['VpcEgressConfiguration' => $desired],
                'OperatorRole' => $roleArn,
                ...($tags === [] ? [] : ['Tags' => $tags]),
            ]);
            $this->wait($name, $step, fn (array $c) => $c['State'] === 'ACTIVE');
            return self::text($created['VersionArn'] ?? null);
        }

        if ($current['State'] === 'PENDING') {
            $current = $this->wait($name, $step, fn (array $c) => $c['State'] === 'ACTIVE');
        }
        if ($current['State'] !== 'ACTIVE') {
            throw self::failed($name, $current, 'State');
        }
        if (($current['LastUpdateStatus'] ?? null) === 'InProgress') {
            $current = $this->wait($name, $step, fn (array $c) => ($c['LastUpdateStatus'] ?? null) !== 'InProgress', updating: true, ignoreFailedUpdate: true);
        }
        $latest = self::text($current['LatestVersionArn'] ?? null);
        if (self::sameConfiguration($current, $desired)) {
            return $latest;
        }

        $this->core->updateNetworkConnector(['Identifier' => $name, 'Configuration' => ['VpcEgressConfiguration' => $desired]]);
        // A new version, not just a Successful status: the answer right after the update may still be the old one.
        $updated = $this->wait($name, $step, fn (array $c) => ($c['LastUpdateStatus'] ?? null) === 'Successful' && self::text($c['LatestVersionArn'] ?? null) !== $latest, updating: true);
        $step('deploy.network_updated', ['connector' => $name, 'version' => self::int($updated['LatestVersion'] ?? null)]);
        return self::text($updated['LatestVersionArn'] ?? null);
    }

    /**
     * The GetNetworkConnector result when Arco owns the connector of this environment, null when there is none.
     *
     * @return array<string, mixed>|null
     * @throws DeployException deploy.network_foreign
     */
    public function find(AppConfig $config): ?array
    {
        $connector = $this->get($config->functionName());
        if ($connector === null) {
            return null;
        }
        if (($connector['OperatorRole'] ?? null) !== $this->roleArn($config)) {
            throw DeployException::make('deploy.network_foreign', ['connector' => $config->functionName(), 'role' => self::operatorRoleName($config)]);
        }
        return $connector;
    }

    /**
     * Deletes the versions that are neither the latest nor in $keepVersionArns. A failure is a warning:
     * the deploy already succeeded.
     *
     * @param list<string> $keepVersionArns
     * @param callable(string, array<string, string|int>): void $step
     */
    public function pruneVersions(AppConfig $config, array $keepVersionArns, callable $step): void
    {
        $name = $config->functionName();
        try {
            $versions = [];
            $marker = null;
            do {
                $page = $this->core->listNetworkConnectorVersions(['Identifier' => $name, ...($marker === null ? [] : ['Marker' => $marker])]);
                foreach (is_array($page['NetworkConnectorVersions'] ?? null) ? $page['NetworkConnectorVersions'] : [] as $version) {
                    if (is_array($version) && is_string($version['VersionArn'] ?? null)) {
                        $versions[$version['VersionArn']] = self::int($version['Version'] ?? null);
                    }
                }
                $marker = is_string($page['NextMarker'] ?? null) && $page['NextMarker'] !== '' ? $page['NextMarker'] : null;
            } while ($marker !== null);
        } catch (AwsException $e) {
            $step('deploy.network_prune_failed', ['connector' => $name, 'error' => self::error($e)]);
            return;
        }
        if ($versions === []) {
            return;
        }
        $keep = [...$keepVersionArns, (string) array_search(max($versions), $versions, true)];
        foreach (array_keys($versions) as $arn) {
            if (in_array($arn, $keep, true)) {
                continue;
            }
            try {
                $this->core->deleteNetworkConnector(['Identifier' => $arn]);
            } catch (AwsException $e) {
                if ($e->getAwsErrorCode() !== 'ResourceNotFoundException') {
                    $step('deploy.network_prune_failed', ['connector' => $name, 'error' => self::error($e)]);
                }
            }
        }
    }

    /**
     * Deletes the connector (every version), waits until it is gone, then the operator role. The caller checks
     * ownership first (find()).
     *
     * @param callable(string, array<string, string|int>): void $step
     * @throws DeployException deploy.network_failed, deploy.network_timeout
     */
    public function delete(AppConfig $config, callable $step): void
    {
        $name = $config->functionName();
        try {
            $this->core->deleteNetworkConnector(['Identifier' => $name]);
            $this->waitGone($name);
        } catch (LambdaCoreException $e) {
            // Gone already (a second delete fails once the connector has disappeared).
            if ($e->getAwsErrorCode() !== 'ResourceNotFoundException') {
                throw $e;
            }
        }
        $this->roles->delete(self::operatorRoleName($config));
        $step('deploy.network_removed', ['connector' => $name]);
    }

    /** @return array<string, mixed>|null */
    private function get(string $name): ?array
    {
        try {
            $result = $this->core->getNetworkConnector(['Identifier' => $name])->toArray();
        } catch (LambdaCoreException $e) {
            if ($e->getAwsErrorCode() === 'ResourceNotFoundException') {
                return null;
            }
            throw $e;
        }
        /** @var array<string, mixed> $result */
        $result['State'] = self::text($result['State'] ?? null);
        return $result;
    }

    /**
     * Polls until $done; a FAILED connector, or an update that Failed (when $updating), stops with its reason.
     *
     * @param callable(string, array<string, string|int>): void $step
     * @param callable(array<string, mixed>): bool $done
     * @return array<string, mixed>
     */
    private function wait(string $name, callable $step, callable $done, bool $updating = false, bool $ignoreFailedUpdate = false): array
    {
        $deadline = $this->sleeper->now() + self::TIMEOUT;
        $announced = false;
        while (true) {
            $connector = $this->get($name) ?? throw DeployException::make('deploy.network_failed', ['connector' => $name, 'reason' => 'gone', 'code' => 'ResourceNotFoundException']);
            if ($connector['State'] === 'FAILED') {
                throw self::failed($name, $connector, 'State');
            }
            if ($updating && ! $ignoreFailedUpdate && ($connector['LastUpdateStatus'] ?? null) === 'Failed') {
                throw self::failed($name, $connector, 'LastUpdateStatus');
            }
            if ($done($connector)) {
                return $connector;
            }
            if ($this->sleeper->now() >= $deadline) {
                throw DeployException::make('deploy.network_timeout', ['connector' => $name, 'seconds' => self::TIMEOUT]);
            }
            if (! $announced) {
                $step('deploy.network_waiting', ['connector' => $name]);
                $announced = true;
            }
            $this->sleeper->sleep(self::POLL);
        }
    }

    private function waitGone(string $name): void
    {
        $deadline = $this->sleeper->now() + self::TIMEOUT;
        while (($connector = $this->get($name)) !== null) {
            if ($connector['State'] === 'DELETE_FAILED') {
                throw self::failed($name, $connector, 'State');
            }
            if ($this->sleeper->now() >= $deadline) {
                throw DeployException::make('deploy.network_timeout', ['connector' => $name, 'seconds' => self::TIMEOUT]);
            }
            $this->sleeper->sleep(self::POLL);
        }
    }

    /**
     * @param array<string, mixed> $connector
     * @param 'State'|'LastUpdateStatus' $field which status failed: its reason code and text are read from the matching keys
     */
    private static function failed(string $name, array $connector, string $field): DeployException
    {
        $prefix = $field === 'State' ? 'State' : 'LastUpdateStatus';
        $code = self::text($connector[$prefix . 'ReasonCode'] ?? null);
        $reason = self::text($connector[$prefix . 'Reason'] ?? null);
        return DeployException::make('deploy.network_failed', [
            'connector' => $name,
            'reason' => $reason !== '' ? $reason : ($code !== '' ? $code : self::text($connector[$field] ?? null)),
            'code' => $code,
        ]);
    }

    /**
     * Same subnets and groups (as sets), protocol and compute types: what Arco would send again.
     *
     * @param array<string, mixed> $connector
     * @param array<string, mixed> $desired
     */
    private static function sameConfiguration(array $connector, array $desired): bool
    {
        $configuration = $connector['Configuration'] ?? null;
        $vpc = is_array($configuration) && is_array($configuration['VpcEgressConfiguration'] ?? null) ? $configuration['VpcEgressConfiguration'] : [];
        $set = static function (mixed $values): array {
            $list = is_array($values) ? array_values(array_map(static fn (mixed $v): string => self::text($v), $values)) : [];
            sort($list);
            return $list;
        };
        return $set($vpc['SubnetIds'] ?? null) === $set($desired['SubnetIds'])
            && $set($vpc['SecurityGroupIds'] ?? null) === $set($desired['SecurityGroupIds'])
            && ($vpc['NetworkProtocol'] ?? null) === $desired['NetworkProtocol']
            && $set($vpc['AssociatedComputeResourceTypes'] ?? null) === $set($desired['AssociatedComputeResourceTypes']);
    }

    private static function error(AwsException $e): string
    {
        return trim((string) $e->getAwsErrorCode() . ' ' . (string) $e->getAwsErrorMessage());
    }

    private static function int(mixed $value): int
    {
        return is_int($value) ? $value : 0;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
