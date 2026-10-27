<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy\Domains;

use Aws\CloudFront\CloudFrontClient;
use Aws\LambdaWeb\Exception\LambdaWebException;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Deploy\DeployException;
use Multiapi\Arco\Deploy\Sleeper;
use Multiapi\Arco\Deploy\WebFunctions;

/**
 * With domain(), only the environment's CloudFront distribution may invoke its endpoint: the
 * distribution signs through its origin access control (DomainStack), one statement of the Web
 * Function's resource-based policy lets it invoke, and the endpoint is IamAuth. Arco writes and
 * removes only its own statement (SID); other statements are kept as they are.
 */
final class CloudFrontAccess
{
    public const SID = 'ArcoCloudFront';
    private const POLL = 15;
    private const DEPLOYED_TIMEOUT = 1800;
    /** A HomeRegion policy change can take a short time to take effect; the endpoint waits for it. */
    private const POLICY_SETTLE = 10;
    private const CONFLICT_RETRIES = 3;

    public function __construct(private readonly WebFunctions $web, private readonly CloudFrontClient $cloudFront, private readonly Sleeper $sleeper)
    {
    }

    /**
     * Locks the endpoint to the distribution in the order that keeps it serving: the distribution
     * signs (the stack put the origin access control on the origin, and it is deployed), the policy
     * lets it invoke, and only then the endpoint requires signatures. An endpoint that is already
     * IamAuth (a new environment, or a redeploy) only gets the policy checked.
     *
     * @param callable(string, array<string, string|int>): void $step
     */
    public function protect(AppConfig $config, string $distributionId, callable $step): void
    {
        $function = $config->functionName();
        $endpoint = $this->web->endpoint($function) ?? throw DeployException::make('deploy.endpoint_missing', ['function' => $function]);
        $functionArn = WebFunctions::text($endpoint['functionArn'] ?? null);
        $endpointArn = WebFunctions::text($endpoint['endpointArn'] ?? null);
        // A grant naming the wrong account or distribution would lock CloudFront out once the endpoint is IamAuth.
        if (preg_match('/^arn:([^:]+):lambda:[^:]+:(\d{12}):web-function\/[^\/]+$/D', $functionArn, $arn) !== 1
            || ! str_starts_with($endpointArn, $functionArn . '/endpoint/')
            || preg_match('/^[A-Z0-9]+$/D', $distributionId) !== 1) {
            throw DeployException::make('deploy.access_arn_invalid', ['function' => $functionArn, 'endpoint' => $endpointArn, 'distribution' => $distributionId]);
        }
        $distributionArn = "arn:{$arn[1]}:cloudfront::{$arn[2]}:distribution/$distributionId";

        $step('deploy.oac_policy', ['function' => $function, 'distribution' => $distributionId]);
        $changed = $this->grant($functionArn, self::statement($endpointArn, $distributionArn));
        if (($endpoint['authType'] ?? null) === 'IamAuth') {
            return;
        }

        $this->waitDeployed($distributionId);
        if ($changed) {
            $this->sleeper->sleep(self::POLICY_SETTLE);
        }
        $step('deploy.endpoint_protected', ['function' => $function]);
        $this->web->waitEndpoint($function);
        $this->web->setAuth($function, 'IamAuth');
        $this->web->waitEndpoint($function);
    }

    /**
     * Removes Arco's statement, and only it; the whole policy when nothing else is left in it.
     *
     * @return bool whether there was a statement to remove
     */
    public function revoke(string $functionArn): bool
    {
        for ($attempt = 1; ; $attempt++) {
            $current = $this->web->policy($functionArn);
            if ($current === null) {
                return false;
            }
            $statements = self::statements($current['policy'], $functionArn);
            $others = self::others($statements);
            if (count($others) === count($statements)) {
                return false;
            }
            try {
                if ($others === []) {
                    $this->web->deletePolicy($functionArn, $current['revisionId']);
                } else {
                    $this->web->putPolicy($functionArn, ['Statement' => $others] + $current['policy'], $current['revisionId']);
                }
                return true;
            } catch (LambdaWebException $e) {
                $this->retryConflict($e, $attempt);
            }
        }
    }

    /**
     * @return array<string, mixed> the statement that lets the distribution (and only it) invoke the endpoint
     */
    public static function statement(string $endpointArn, string $distributionArn): array
    {
        return [
            'Sid' => self::SID,
            'Effect' => 'Allow',
            'Principal' => ['Service' => 'cloudfront.amazonaws.com'],
            'Action' => 'lambda:InvokeWebFunctionEndpoint',
            'Resource' => $endpointArn,
            'Condition' => ['StringEquals' => ['aws:SourceArn' => $distributionArn]],
        ];
    }

    /**
     * Puts the statement in the policy, keeping every other statement; no call when it is already there.
     *
     * @param array<string, mixed> $statement
     * @return bool whether the policy changed
     */
    private function grant(string $functionArn, array $statement): bool
    {
        for ($attempt = 1; ; $attempt++) {
            $current = $this->web->policy($functionArn);
            $statements = self::statements($current['policy'] ?? [], $functionArn);
            $ours = array_values(array_filter($statements, fn (array $s) => ($s['Sid'] ?? null) === self::SID));
            if (count($ours) === 1 && self::canonical($ours[0]) === self::canonical($statement)) {
                return false;
            }
            $policy = ['Version' => '2012-10-17', ...($current['policy'] ?? [])];
            $policy['Statement'] = [...self::others($statements), $statement];
            try {
                // The revision read makes a concurrent change fail (ConflictException) instead of being overwritten.
                $this->web->putPolicy($functionArn, $policy, $current['revisionId'] ?? null);
                return true;
            } catch (LambdaWebException $e) {
                $this->retryConflict($e, $attempt);
            }
        }
    }

    private function waitDeployed(string $distributionId): void
    {
        for ($waited = 0; ; $waited += self::POLL) {
            $distribution = Aws::map(Aws::call($this->cloudFront, 'GetDistribution', ['Id' => $distributionId])['Distribution'] ?? null);
            if (($distribution['Status'] ?? null) === 'Deployed') {
                return;
            }
            if ($waited >= self::DEPLOYED_TIMEOUT) {
                throw DeployException::make('deploy.timeout', ['what' => "distribution $distributionId"]);
            }
            $this->sleeper->sleep(self::POLL);
        }
    }

    /** A conflict is a changed revision or a busy function: read again after a pause, a bounded number of times. */
    private function retryConflict(LambdaWebException $e, int $attempt): void
    {
        if ($e->getAwsErrorCode() !== 'ConflictException' || $attempt >= self::CONFLICT_RETRIES) {
            throw $e;
        }
        $this->sleeper->sleep(3);
    }

    /**
     * A policy's statements; a single statement may be written as an object. Anything else stops the
     * deploy: rewriting a policy Arco cannot read would drop the statements it did not write.
     *
     * @param array<string, mixed> $policy
     * @return list<array<string, mixed>>
     */
    private static function statements(array $policy, string $functionArn): array
    {
        $statements = $policy['Statement'] ?? [];
        if (is_array($statements) && ! array_is_list($statements)) {
            $statements = [$statements];
        }
        if (! is_array($statements) || array_filter($statements, fn (mixed $s) => ! is_array($s) || ($s !== [] && array_is_list($s))) !== []) {
            throw DeployException::make('deploy.policy_unreadable', ['arn' => $functionArn]);
        }
        return Aws::maps($statements);
    }

    /**
     * @param list<array<string, mixed>> $statements
     * @return list<array<string, mixed>>
     */
    private static function others(array $statements): array
    {
        return array_values(array_filter($statements, fn (array $s) => ($s['Sid'] ?? null) !== self::SID));
    }

    /** JSON with sorted keys, so a statement read back compares equal whatever order AWS keeps. */
    private static function canonical(mixed $value): string
    {
        $sort = function (mixed $value) use (&$sort): mixed {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value);
            }
            return array_map($sort, $value);
        };
        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
