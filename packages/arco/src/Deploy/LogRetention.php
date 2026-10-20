<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Aws\CloudWatchLogs\Exception\CloudWatchLogsException;
use Closure;

/** Sets how long CloudWatch keeps the log groups of an environment, and tags them for it. */
final class LogRetention
{
    private const DENIED = ['AccessDenied', 'AccessDeniedException'];

    public function __construct(private readonly CloudWatchLogsClient $logs)
    {
    }

    /**
     * Creates each group when missing (Lambda only creates it on first invoke, and a policy needs it),
     * then sets its retention in days, or with false removes the policy so logs never expire.
     *
     * With $tags, a new group is created with them and an existing one (often made untagged by Lambda)
     * is adopted: logs:TagResource on its ARN ($arn). A profile that may not tag gets the group created
     * untagged, and the retention is still set; those groups are returned.
     *
     * @param list<string> $groups
     * @param array<string, string> $tags
     * @param (Closure(string): string)|null $arn the ARN of a group, for tagging an existing one
     * @return list<array{group: string, error: string}> the groups whose tags could not be added
     */
    public function apply(array $groups, int|false $days, array $tags = [], ?Closure $arn = null): array
    {
        $untagged = [];
        foreach ($groups as $group) {
            $error = $this->create($group, $tags, $arn);
            if ($error !== null) {
                $untagged[] = ['group' => $group, 'error' => $error];
            }
            if ($days === false) {
                $this->logs->deleteRetentionPolicy(['logGroupName' => $group]);
            } else {
                $this->logs->putRetentionPolicy(['logGroupName' => $group, 'retentionInDays' => $days]);
            }
        }
        return $untagged;
    }

    /**
     * Creates the group (tagged), or tags the existing one.
     *
     * @param array<string, string> $tags
     * @param (Closure(string): string)|null $arn
     * @return string|null why the tags could not be added
     */
    private function create(string $group, array $tags, ?Closure $arn = null): ?string
    {
        try {
            $this->logs->createLogGroup(['logGroupName' => $group, ...($tags === [] ? [] : ['tags' => $tags])]);
            return null;
        } catch (CloudWatchLogsException $e) {
            if ($tags !== [] && in_array($e->getAwsErrorCode(), self::DENIED, true)) {
                // Creating with tags also needs logs:TagResource: create it untagged, and say so.
                $this->create($group, []);
                return self::message($e);
            }
            if ($e->getAwsErrorCode() !== 'ResourceAlreadyExistsException') {
                throw $e;
            }
        }
        if ($tags === [] || $arn === null) {
            return null;
        }
        try {
            $this->logs->tagResource(['resourceArn' => $arn($group), 'tags' => $tags]);
        } catch (CloudWatchLogsException $e) {
            if (! in_array($e->getAwsErrorCode(), self::DENIED, true)) {
                throw $e;
            }
            return self::message($e);
        }
        return null;
    }

    private static function message(CloudWatchLogsException $e): string
    {
        $message = $e->getAwsErrorMessage();
        return is_string($message) && $message !== '' ? $message : $e->getMessage();
    }
}
