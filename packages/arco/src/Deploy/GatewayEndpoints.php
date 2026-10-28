<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Aws\Ec2\Ec2Client;
use Aws\Exception\AwsException;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Deploy\Domains\Aws;

/**
 * The S3 and DynamoDB gateway endpoints of vpc(): they keep the traffic to those services inside the VPC, where a
 * function without a NAT gateway has no other way out. plan() only reads; it decides per service whether the
 * environment's route tables are served and what to do if they are not.
 */
final class GatewayEndpoints
{
    public const MARKER_KEY = 'arco:managed';
    public const MARKER_VALUE = 'gateway-endpoint';
    /** The Web Function tag that records the endpoints the environment uses (space-separated ids). */
    public const RECORD_TAG = 'arco:gateway-endpoints';
    public const MAX_TAGS = 50;
    private const USAGE_PREFIX = 'arco:uses:';
    /** Endpoints in these states are gone or going: they neither cover route tables nor count as existing. */
    private const IGNORED_STATES = ['deleting', 'deleted', 'failed', 'rejected', 'expired'];

    public function __construct(
        private readonly Ec2Client $ec2,
        private readonly Sleeper $sleeper, // @phpstan-ignore property.onlyWritten (apply() waits on endpoint states with it)
        private readonly string $region,
    ) {
    }

    /** `arco:uses:<app>/<env>`: the tag an environment puts on an Arco endpoint it uses. */
    public static function usageTag(AppConfig $config): string
    {
        return self::USAGE_PREFIX . $config->app . '/' . $config->environment;
    }

    /**
     * One plan per endpoint service of vpc(), in declaration order. Call it only when vpc() declares endpoints.
     *
     * @return list<EndpointPlan>
     * @throws DeployException deploy.endpoint_vpc_mixed, deploy.endpoint_not_found, deploy.endpoint_wrong_type,
     *                         deploy.endpoint_wrong_service, deploy.endpoint_wrong_vpc
     */
    public function plan(AppConfig $config): array
    {
        $vpc = $config->vpc;
        if ($vpc === null) {
            return [];
        }
        $subnets = $vpc->subnets;
        $vpcId = $this->vpcOf($subnets);
        $tables = $this->routeTablesOf($vpcId, $subnets);
        $plans = [];
        foreach ($vpc->endpointIds() as $service => $named) {
            $plans[] = $this->planService($service, $named, $vpcId, $tables);
        }
        return $plans;
    }

    /** @param list<string> $subnets */
    private function vpcOf(array $subnets): string
    {
        $vpcs = [];
        $token = null;
        do {
            $page = $this->ec2->describeSubnets([
                'SubnetIds' => $subnets,
                ...($token === null ? [] : ['NextToken' => $token]),
            ]);
            foreach (Aws::maps($page['Subnets'] ?? []) as $subnet) {
                $vpc = Aws::string($subnet['VpcId'] ?? null);
                if ($vpc !== null) {
                    $vpcs[$vpc] = true;
                }
            }
            $token = self::next($page);
        } while ($token !== null);
        $vpcs = array_keys($vpcs);
        sort($vpcs);
        if (count($vpcs) > 1) {
            throw DeployException::make('deploy.endpoint_vpc_mixed', ['vpcs' => implode(', ', $vpcs)]);
        }
        return $vpcs[0] ?? '';
    }

    /**
     * The route tables the subnets use: the one associated with the subnet, else the VPC's main table.
     *
     * @param list<string> $subnets
     * @return list<string>
     */
    private function routeTablesOf(string $vpcId, array $subnets): array
    {
        $explicit = [];
        $main = null;
        $token = null;
        do {
            $page = $this->ec2->describeRouteTables([
                'Filters' => [['Name' => 'vpc-id', 'Values' => [$vpcId]]],
                ...($token === null ? [] : ['NextToken' => $token]),
            ]);
            foreach (Aws::maps($page['RouteTables'] ?? []) as $table) {
                $id = Aws::string($table['RouteTableId'] ?? null);
                if ($id === null) {
                    continue;
                }
                foreach (Aws::maps($table['Associations'] ?? []) as $association) {
                    $subnet = Aws::string($association['SubnetId'] ?? null);
                    if ($subnet !== null) {
                        $explicit[$subnet] = $id;
                    }
                    if (($association['Main'] ?? false) === true) {
                        $main = $id;
                    }
                }
            }
            $token = self::next($page);
        } while ($token !== null);

        $tables = [];
        foreach ($subnets as $subnet) {
            $table = $explicit[$subnet] ?? $main;
            if ($table !== null && !in_array($table, $tables, true)) {
                $tables[] = $table;
            }
        }
        return $tables;
    }

    /** @param list<string> $tables */
    private function planService(string $service, ?string $named, string $vpcId, array $tables): EndpointPlan
    {
        $endpoints = $this->endpoints($service, $vpcId);
        $covering = [];
        $covered = [];
        foreach ($endpoints as $endpoint) {
            $served = array_values(array_intersect($tables, $endpoint['tables']));
            if ($served !== []) {
                $covering[] = ['id' => $endpoint['id'], 'arco' => $endpoint['arco'], 'uses' => $endpoint['uses']];
                $covered = [...$covered, ...$served];
            }
        }
        $user = array_values(array_map(fn (array $e) => $e['id'], array_filter($endpoints, fn (array $e) => !$e['arco'])));
        $context = [$service, $vpcId, $tables, $user, $covering];

        if ($named !== null) {
            $endpoint = $endpoints[$named] ?? $this->named($service, $named, $vpcId);
            // A route table belongs to one gateway endpoint per service: what another endpoint serves is not missing.
            $missing = array_values(array_diff($tables, [...$endpoint['tables'], ...$covered]));
            return self::result($missing, $missing === [] ? EndpointPlan::NONE : EndpointPlan::ASSOCIATE, $named, $endpoint['arco'], ...$context);
        }

        $missing = array_values(array_diff($tables, $covered));
        if ($missing === []) {
            $arco = null;
            foreach ($covering as $endpoint) {
                if ($endpoint['arco']) {
                    $arco = $endpoint['id'];
                    break;
                }
            }
            return self::result([], EndpointPlan::NONE, $arco, $arco !== null, ...$context);
        }
        foreach ($endpoints as $endpoint) {
            if ($endpoint['arco']) {
                return self::result($missing, EndpointPlan::ASSOCIATE, $endpoint['id'], true, ...$context);
            }
        }
        return self::result($missing, $endpoints === [] ? EndpointPlan::CREATE : EndpointPlan::DECIDE, null, false, ...$context);
    }

    /**
     * @param list<string> $missing the route tables left to serve
     * @param list<string> $tables
     * @param list<string> $user
     * @param list<array{id: string, arco: bool, uses: int}> $covering
     */
    private static function result(array $missing, string $action, ?string $target, bool $arco, string $service, string $vpcId, array $tables, array $user, array $covering): EndpointPlan
    {
        return new EndpointPlan($service, $vpcId, $tables, $missing, $action, $target, $arco, $user, $covering);
    }

    /**
     * The live gateway endpoints of the service in the VPC, by id.
     *
     * @return array<string, array{id: string, arco: bool, uses: int, tables: list<string>}>
     */
    private function endpoints(string $service, string $vpcId): array
    {
        $found = [];
        $token = null;
        do {
            $page = $this->ec2->describeVpcEndpoints([
                'Filters' => [
                    ['Name' => 'vpc-id', 'Values' => [$vpcId]],
                    ['Name' => 'service-name', 'Values' => [$this->serviceName($service)]],
                    ['Name' => 'vpc-endpoint-type', 'Values' => ['Gateway']],
                ],
                ...($token === null ? [] : ['NextToken' => $token]),
            ]);
            foreach (Aws::maps($page['VpcEndpoints'] ?? []) as $raw) {
                $endpoint = self::parse($raw);
                if ($endpoint !== null) {
                    $found[$endpoint['id']] = $endpoint;
                }
            }
            $token = self::next($page);
        } while ($token !== null);
        ksort($found);
        return $found;
    }

    /**
     * The endpoint a vpc() line names that the filtered list did not return: say why it does not fit.
     *
     * @return array{id: string, arco: bool, uses: int, tables: list<string>}
     */
    private function named(string $service, string $id, string $vpcId): array
    {
        $params = ['service' => $service, 'endpoint' => $id];
        try {
            $page = $this->ec2->describeVpcEndpoints(['VpcEndpointIds' => [$id]]);
        } catch (AwsException $e) {
            if (str_contains((string) $e->getAwsErrorCode(), 'NotFound')) {
                throw DeployException::make('deploy.endpoint_not_found', $params, $e);
            }
            throw $e;
        }
        $raw = Aws::maps($page['VpcEndpoints'] ?? [])[0] ?? null;
        $endpoint = $raw === null ? null : self::parse($raw);
        if ($endpoint === null) {
            throw DeployException::make('deploy.endpoint_not_found', $params);
        }
        if (Aws::string($raw['VpcEndpointType'] ?? null) !== 'Gateway') {
            throw DeployException::make('deploy.endpoint_wrong_type', $params);
        }
        if (Aws::string($raw['ServiceName'] ?? null) !== $this->serviceName($service)) {
            throw DeployException::make('deploy.endpoint_wrong_service', $params);
        }
        throw DeployException::make('deploy.endpoint_wrong_vpc', $params);
    }

    /**
     * @param array<string, mixed> $raw
     * @return array{id: string, arco: bool, uses: int, tables: list<string>}|null null for a missing id or an endpoint that is gone
     */
    private static function parse(array $raw): ?array
    {
        $id = Aws::string($raw['VpcEndpointId'] ?? null);
        if ($id === null || in_array(strtolower((string) Aws::string($raw['State'] ?? null)), self::IGNORED_STATES, true)) {
            return null;
        }
        $arco = false;
        $uses = 0;
        foreach (Aws::maps($raw['Tags'] ?? []) as $tag) {
            $key = Aws::string($tag['Key'] ?? null) ?? '';
            if ($key === self::MARKER_KEY && Aws::string($tag['Value'] ?? null) === self::MARKER_VALUE) {
                $arco = true;
            } elseif (str_starts_with($key, self::USAGE_PREFIX)) {
                $uses++;
            }
        }
        return ['id' => $id, 'arco' => $arco, 'uses' => $uses, 'tables' => Aws::strings($raw['RouteTableIds'] ?? [])];
    }

    private function serviceName(string $service): string
    {
        return "com.amazonaws.{$this->region}.{$service}";
    }

    /** @param \ArrayAccess<string, mixed> $page */
    private static function next(\ArrayAccess $page): ?string
    {
        $token = Aws::string($page['NextToken'] ?? null);
        return $token === '' ? null : $token;
    }
}
