<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Aws\Ec2\Ec2Client;
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
        private readonly Sleeper $sleeper,
    ) {
    }

    /**
     * Waits until no interface of $functions is left. Lambda describes each as "AWS Lambda VPC ENI-<function>",
     * the function that made it (one interface serves every function with the same subnets and groups).
     *
     * @param list<string> $functions
     * @return list<string> the interface ids still there at the deadline; [] once they are gone
     * @throws \Aws\Exception\AwsException a refused or failed lookup
     */
    public function waitGone(array $functions): array
    {
        $deadline = $this->sleeper->now() + self::TIMEOUT;
        while (true) {
            $left = $this->find($functions);
            if ($left === [] || $this->sleeper->now() >= $deadline) {
                return $left;
            }
            $this->sleeper->sleep(self::POLL);
        }
    }

    /**
     * @param list<string> $functions
     * @return list<string>
     */
    private function find(array $functions): array
    {
        $descriptions = [];
        foreach ($functions as $function) {
            $descriptions[] = 'AWS Lambda VPC ENI-' . $function;
            $descriptions[] = 'AWS Lambda VPC ENI-' . $function . '-*';
        }
        $ids = [];
        $token = null;
        do {
            $page = $this->ec2->describeNetworkInterfaces([
                'Filters' => [['Name' => 'description', 'Values' => $descriptions]],
                ...($token === null ? [] : ['NextToken' => $token]),
            ]);
            foreach (Aws::maps($page['NetworkInterfaces'] ?? []) as $interface) {
                $id = Aws::string($interface['NetworkInterfaceId'] ?? null);
                if ($id !== null) {
                    $ids[] = $id;
                }
            }
            $token = Aws::string($page['NextToken'] ?? null);
            $token = $token === '' ? null : $token;
        } while ($token !== null);
        sort($ids);
        return $ids;
    }
}
