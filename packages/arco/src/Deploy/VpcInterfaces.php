<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Aws\Ec2\Ec2Client;
use Aws\Lambda\LambdaClient;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Deploy\Domains\Aws;

/**
 * The VPC network interfaces Lambda creates for the console and the workers (classic VpcConfig). Lambda deletes
 * one only after no function version uses it, up to about 20 minutes later, and it needs the function's execution
 * role to do it: the role must outlive the interfaces, or they stay in the VPC for good (and block deleting its
 * subnets and security groups).
 */
final class VpcInterfaces
{
    private const TIMEOUT = 1800;
    private const POLL = 15;

    public function __construct(
        private readonly Ec2Client $ec2,
        private readonly LambdaClient $lambda,
        private readonly Sleeper $sleeper,
    ) {
    }

    /** The environment's console and workers, as the summary of a step or warning. */
    public static function functions(AppConfig $config): string
    {
        return $config->consoleFunctionName() . ', ' . $config->functionName() . '-queue-*';
    }

    /**
     * Waits until Lambda has deleted the interfaces of the environment's console and workers. Lambda describes
     * each as "AWS Lambda VPC ENI-<function>", the function that made it; one interface serves every function of
     * the account with the same subnets and security groups, so one that a function of another environment or
     * app still uses (any version, $LATEST included) is not waited for: it stays, and so does that function's role.
     *
     * @return list<string> the interface ids still there at the deadline; [] once they are gone
     * @throws \Aws\Exception\AwsException a refused or failed lookup
     */
    public function waitGone(AppConfig $config): array
    {
        $deadline = $this->sleeper->now() + self::TIMEOUT;
        $shared = null;
        while (true) {
            $left = $this->find($config);
            if ($left !== []) {
                $shared ??= $this->othersInVpc($config);
                $left = array_keys(array_filter($left, fn (array $interface) => ! self::usedByAny($interface, $shared)));
            }
            if ($left === [] || $this->sleeper->now() >= $deadline) {
                return $left;
            }
            $this->sleeper->sleep(self::POLL);
        }
    }

    /** @return array<string, array{subnet: string, groups: list<string>}> by interface id */
    private function find(AppConfig $config): array
    {
        $prefix = 'AWS Lambda VPC ENI-';
        $descriptions = [$prefix . $config->consoleFunctionName(), $prefix . $config->consoleFunctionName() . '-*', $prefix . $config->functionName() . '-queue-*'];
        $found = [];
        $token = null;
        do {
            $page = $this->ec2->describeNetworkInterfaces([
                'Filters' => [['Name' => 'description', 'Values' => $descriptions]],
                ...($token === null ? [] : ['NextToken' => $token]),
            ]);
            foreach (Aws::maps($page['NetworkInterfaces'] ?? []) as $interface) {
                $id = Aws::string($interface['NetworkInterfaceId'] ?? null);
                if ($id === null) {
                    continue;
                }
                $groups = [];
                foreach (Aws::maps($interface['Groups'] ?? []) as $group) {
                    $groupId = Aws::string($group['GroupId'] ?? null);
                    if ($groupId !== null) {
                        $groups[] = $groupId;
                    }
                }
                sort($groups);
                $found[$id] = ['subnet' => (string) Aws::string($interface['SubnetId'] ?? null), 'groups' => $groups];
            }
            $token = Aws::string($page['NextToken'] ?? null);
            $token = $token === '' ? null : $token;
        } while ($token !== null);
        ksort($found);
        return $found;
    }

    /**
     * The VPC settings of every function version in the region that is not this environment's console or worker.
     *
     * @return list<array{subnets: list<string>, groups: list<string>}>
     */
    private function othersInVpc(AppConfig $config): array
    {
        $console = $config->consoleFunctionName();
        $workers = $config->functionName() . '-queue-';
        $others = [];
        $marker = null;
        do {
            $page = $this->lambda->listFunctions(['FunctionVersion' => 'ALL', ...($marker === null ? [] : ['Marker' => $marker])]);
            foreach (Aws::maps($page['Functions'] ?? []) as $function) {
                $name = (string) Aws::string($function['FunctionName'] ?? null);
                if ($name === $console || str_starts_with($name, $workers)) {
                    continue;
                }
                $vpc = is_array($function['VpcConfig'] ?? null) ? $function['VpcConfig'] : [];
                $subnets = array_values(array_filter(is_array($vpc['SubnetIds'] ?? null) ? $vpc['SubnetIds'] : [], 'is_string'));
                if ($subnets === []) {
                    continue;
                }
                $groups = array_values(array_filter(is_array($vpc['SecurityGroupIds'] ?? null) ? $vpc['SecurityGroupIds'] : [], 'is_string'));
                sort($groups);
                $others[] = ['subnets' => $subnets, 'groups' => $groups];
            }
            $marker = Aws::string($page['NextMarker'] ?? null);
            $marker = $marker === '' ? null : $marker;
        } while ($marker !== null);
        return $others;
    }

    /**
     * @param array{subnet: string, groups: list<string>} $interface
     * @param list<array{subnets: list<string>, groups: list<string>}> $functions
     */
    private static function usedByAny(array $interface, array $functions): bool
    {
        foreach ($functions as $function) {
            if (in_array($interface['subnet'], $function['subnets'], true) && $function['groups'] === $interface['groups']) {
                return true;
            }
        }
        return false;
    }
}
