<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\E2e;

use Aws\Exception\AwsException;
use Multiapi\Arco\Aws\AccountMismatch;
use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Deploy\Domains\Aws;
use Multiapi\Arco\Logs\LogGroups;

/**
 * Deletes what `arco remove` keeps after a real-AWS end-to-end run: the retained S3 buckets (emptied
 * first, every version), DynamoDB tables and SQS queues (with their dead-letter queues), the run's CloudWatch
 * log groups, plus the run's APP_KEY parameter. This is the only code in the project that deletes real AWS resources, so:
 *
 * - An instance exists only after connect() checked that no endpoint override is set and that the
 *   caller's account (sts:GetCallerIdentity, the first and only call before the check) is exactly
 *   the expected one.
 * - plan() only reads, and only for a run app name (arco-e2e-<8 hex>, see newAppName()), never a
 *   real app's. A bucket, table or queue is planned only when its name starts with
 *   "<app>-e2e-" AND the service itself reports both tags arco:app=<app> and arco:environment=e2e.
 *   Anything else it finds (right name without those tags, tagged but named otherwise) is reported
 *   through skipped() and left alone.
 * - A log group is planned only when its name is exactly one the run's functions write to
 *   (/aws/lambda/<app>-e2e[-.]..., /aws/lambda/web/<app>-e2e[-.]...: <app> carries 8 random hex digits,
 *   so no other run's, no real app's group can match) AND its tags do not contradict the run: it
 *   carries both arco:app=<app> and arco:environment=e2e (groups Arco created, or tagged), or neither
 *   (Lambda creates a group untagged on the first invoke, and a profile without logs:TagResource leaves
 *   it so). A group tagged for another app or environment is skipped and reported.
 * - The APP_KEY parameter is the one exception to tags: it is deleted by its exact name,
 *   /<app>/e2e/app-key, which only this run's random app name produces.
 * - apply() deletes only items of the plan of the same instance's app and checks name and tags
 *   again right before each delete.
 */
final class AwsE2eCleanup
{
    public const ENVIRONMENT = 'e2e';
    public const APP_PATTERN = '/^arco-e2e-[0-9a-f]{8}$/D';

    private const BUCKET = 's3-bucket';
    private const TABLE = 'dynamodb-table';
    private const QUEUE = 'sqs-queue';
    private const LOG_GROUP = 'logs-log-group';
    private const PARAMETER = 'ssm-parameter';
    /** apply() order: nothing depends on a queue being gone, but the bucket takes longest. */
    private const ORDER = [self::QUEUE, self::TABLE, self::BUCKET, self::LOG_GROUP, self::PARAMETER];

    /** The app of the last plan(); apply() works for it only. */
    private ?string $app = null;
    /** @var list<array{type: string, name: string, reason: string}> */
    private array $skipped = [];
    /** @var \Closure(string): void */
    private \Closure $say;

    /** @param (\Closure(string): void)|null $say progress lines (the E2E prints them to stderr) */
    private function __construct(private readonly AwsClients $aws, private readonly string $account, ?\Closure $say)
    {
        $this->say = $say ?? static function (string $line): void {
        };
    }

    /** A fresh app name for one run: arco-e2e-<8 hex>. */
    public static function newAppName(): string
    {
        return 'arco-e2e-' . bin2hex(random_bytes(4));
    }

    /**
     * Refuses unless no AWS_ENDPOINT_URL* override is set, $expectedAccount is a 12-digit id and the
     * credentials of $aws belong to exactly that account; makes no AWS call before the first two
     * checks and none but sts:GetCallerIdentity before the last.
     *
     * @param array<string, string>|null $env the process environment (getenv()) when null
     * @param (\Closure(string): void)|null $say
     */
    public static function connect(AwsClients $aws, string $expectedAccount, ?array $env = null, ?\Closure $say = null): self
    {
        foreach ($env ?? getenv() as $name => $value) {
            if (str_starts_with((string) $name, 'AWS_ENDPOINT_URL') && $value !== '') {
                throw new \RuntimeException("Refusing the real-AWS end-to-end run: $name is set ($value). Unset it: this run must talk to AWS itself.");
            }
        }
        if (preg_match('/^\d{12}$/D', $expectedAccount) !== 1) {
            throw new \RuntimeException('Refusing the real-AWS end-to-end run: ARCO_E2E_ACCOUNT must be the 12-digit id of the dedicated E2E account (is "' . $expectedAccount . '").');
        }
        $actual = Aws::string($aws->sts()->getCallerIdentity()['Account'] ?? null);
        if ($actual !== $expectedAccount) {
            throw new AccountMismatch($expectedAccount, $actual ?? '(none)', $aws->profile);
        }
        return new self($aws, $actual, $say);
    }

    /**
     * What apply() would delete for $app, found with read-only calls (List*, Get*, Describe*).
     *
     * @return list<array{type: string, name: string}>
     */
    public function plan(string $app): array
    {
        if (preg_match(self::APP_PATTERN, $app) !== 1) {
            throw new \InvalidArgumentException("Not an end-to-end run app name: \"$app\" (expected arco-e2e-<8 hex>).");
        }
        $this->app = $app;
        $prefix = $this->prefix();

        /** @var array<string, array{type: string, name: string}> $candidates */
        $candidates = [];
        $add = static function (string $type, string $name) use (&$candidates): void {
            $candidates["$type $name"] = ['type' => $type, 'name' => $name];
        };
        foreach ($this->bucketsNamed($prefix) as $name) {
            $add(self::BUCKET, $name);
        }
        foreach ($this->tablesNamed($prefix) as $name) {
            $add(self::TABLE, $name);
        }
        foreach ($this->queuesNamed($prefix) as $name) {
            $add(self::QUEUE, $name);
        }
        foreach ($this->logGroupsNamed() as $name) {
            $add(self::LOG_GROUP, $name);
        }
        foreach ($this->taggedResources($app) as $resource) {
            $add($resource['type'], $resource['name']);
        }

        $plan = [];
        foreach ($candidates as $candidate) {
            $problem = $this->problem($candidate['type'], $candidate['name']);
            if ($problem === null) {
                $plan[] = $candidate;
            } else {
                $this->skip($candidate['type'], $candidate['name'], $problem);
            }
        }
        if ($this->parameterExists($this->parameterName())) {
            $plan[] = ['type' => self::PARAMETER, 'name' => $this->parameterName()];
        }
        usort($plan, static fn (array $a, array $b): int => [array_search($a['type'], self::ORDER, true), $a['name']] <=> [array_search($b['type'], self::ORDER, true), $b['name']]);
        return $plan;
    }

    /**
     * Deletes the items of a plan() of this instance, checking each again first; goes on past a
     * failed delete and throws at the end with every failure.
     *
     * @param list<array{type: string, name: string}> $plan
     */
    public function apply(array $plan): void
    {
        if ($this->app === null) {
            throw new \LogicException('apply() needs a plan() of the same cleanup first.');
        }
        $failures = [];
        foreach ($plan as $item) {
            ['type' => $type, 'name' => $name] = $item;
            try {
                $problem = $this->problem($type, $name);
                if ($problem !== null) {
                    $this->skip($type, $name, $problem);
                    continue;
                }
                match ($type) {
                    self::QUEUE => $this->deleteQueue($name),
                    self::TABLE => Aws::call($this->aws->dynamoDb(), 'DeleteTable', ['TableName' => $name]),
                    self::BUCKET => $this->deleteBucket($name),
                    self::LOG_GROUP => Aws::call($this->aws->logs(), 'DeleteLogGroup', ['logGroupName' => $name]),
                    self::PARAMETER => Aws::call($this->aws->ssm(), 'DeleteParameter', ['Name' => $name]),
                    default => throw new \LogicException("problem() let an unknown type through: $type"),
                };
                ($this->say)("[cleanup] deleted $type $name");
            } catch (\RuntimeException $e) {
                $failures[] = "$type $name: " . ($e instanceof AwsException ? ($e->getAwsErrorCode() ?? 'error') . ' ' . ($e->getAwsErrorMessage() ?? '') : $e->getMessage());
            }
        }
        if ($failures !== []) {
            throw new \RuntimeException("The end-to-end cleanup could not delete:\n- " . implode("\n- ", $failures) . "\nDelete them by hand in account {$this->account}.");
        }
    }

    /** @return list<array{type: string, name: string, reason: string}> what plan() and apply() left alone, and why */
    public function skipped(): array
    {
        return $this->skipped;
    }

    private function prefix(): string
    {
        return $this->app . '-' . self::ENVIRONMENT . '-';
    }

    private function parameterName(): string
    {
        return '/' . $this->app . '/' . self::ENVIRONMENT . '/app-key';
    }

    /** Why $type $name must not be deleted for this run, or null when it may (read-only calls only). */
    private function problem(string $type, string $name): ?string
    {
        if ($type === self::PARAMETER) {
            return $name === $this->parameterName() ? null : 'not the run\'s APP_KEY parameter ' . $this->parameterName();
        }
        if ($type === self::LOG_GROUP) {
            return $this->logGroupProblem($name);
        }
        if (! in_array($type, [self::BUCKET, self::TABLE, self::QUEUE], true)) {
            return 'not a resource type the cleanup deletes';
        }
        if (! str_starts_with($name, $this->prefix())) {
            return 'name does not start with ' . $this->prefix();
        }
        $tags = $this->tagsOf($type, $name);
        if ($tags === null) {
            return 'no longer exists';
        }
        if (($tags['arco:app'] ?? null) !== $this->app || ($tags['arco:environment'] ?? null) !== self::ENVIRONMENT) {
            $found = $tags === [] ? 'none' : implode(', ', array_map(static fn (string $k, string $v): string => "$k=$v", array_keys($tags), array_values($tags)));
            return 'not tagged arco:app=' . $this->app . ' and arco:environment=' . self::ENVIRONMENT . " (tags: $found)";
        }
        return null;
    }

    /** @return list<string> the groups of the run's functions: web (and its .local), console and queue workers */
    private function logGroupPrefixes(): array
    {
        return ['/aws/lambda/' . $this->app . '-' . self::ENVIRONMENT, '/aws/lambda/web/' . $this->app . '-' . self::ENVIRONMENT];
    }

    private function logGroupProblem(string $name): ?string
    {
        if (preg_match('#^/aws/lambda/(web/)?' . preg_quote((string) $this->app . '-' . self::ENVIRONMENT, '#') . '([-.].*)?$#D', $name) !== 1) {
            return 'not a log group of the run: ' . $this->logGroupPrefixes()[0] . '* or ' . $this->logGroupPrefixes()[1] . '*';
        }
        try {
            $tags = Aws::map(Aws::call($this->aws->logs(), 'ListTagsForResource', [
                'resourceArn' => LogGroups::arn($this->aws->region, $this->account, $name),
            ])['tags'] ?? []);
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'ResourceNotFoundException') {
                return 'no longer exists';
            }
            throw $e;
        }
        $app = $tags['arco:app'] ?? null;
        $environment = $tags['arco:environment'] ?? null;
        if (($app === null && $environment === null) || ($app === $this->app && $environment === self::ENVIRONMENT)) {
            return null;
        }
        return 'tagged for another app or environment, not arco:app=' . $this->app . ' and arco:environment=' . self::ENVIRONMENT
            . ' (arco:app=' . (is_string($app) ? $app : 'none') . ', arco:environment=' . (is_string($environment) ? $environment : 'none') . ')';
    }

    /** @return list<string> */
    private function logGroupsNamed(): array
    {
        $names = [];
        foreach ($this->logGroupPrefixes() as $prefix) {
            $token = null;
            do {
                $page = Aws::call($this->aws->logs(), 'DescribeLogGroups', ['logGroupNamePrefix' => $prefix, ...($token === null ? [] : ['nextToken' => $token])]);
                foreach (Aws::maps($page['logGroups'] ?? []) as $group) {
                    $name = Aws::string($group['logGroupName'] ?? null);
                    if ($name !== null && str_starts_with($name, $prefix)) {
                        $names[] = $name;
                    }
                }
                $token = Aws::string($page['nextToken'] ?? null);
            } while ($token !== null);
        }
        return array_values(array_unique($names));
    }

    /** @return array<string, string>|null the resource's tags as its own service reports them; null when it does not exist */
    private function tagsOf(string $type, string $name): ?array
    {
        try {
            switch ($type) {
                case self::QUEUE:
                    $map = Aws::map(Aws::call($this->aws->sqs(), 'ListQueueTags', ['QueueUrl' => $this->queueUrl($name)])['Tags'] ?? []);
                    return array_filter($map, 'is_string');
                case self::TABLE:
                    $table = Aws::map(Aws::call($this->aws->dynamoDb(), 'DescribeTable', ['TableName' => $name])['Table'] ?? []);
                    $list = Aws::call($this->aws->dynamoDb(), 'ListTagsOfResource', ['ResourceArn' => Aws::string($table['TableArn'] ?? null) ?? ''])['Tags'] ?? [];
                    break;
                default:
                    $list = Aws::call($this->aws->s3(), 'GetBucketTagging', ['Bucket' => $name, 'ExpectedBucketOwner' => $this->account])['TagSet'] ?? [];
            }
        } catch (AwsException $e) {
            return match ($e->getAwsErrorCode()) {
                'NoSuchTagSet' => [],
                'NoSuchBucket', 'ResourceNotFoundException', 'AWS.SimpleQueueService.NonExistentQueue', 'QueueDoesNotExist' => null,
                default => throw $e,
            };
        }
        $tags = [];
        foreach (Aws::maps($list) as $tag) {
            $key = Aws::string($tag['Key'] ?? null);
            if ($key !== null) {
                $tags[$key] = is_string($tag['Value'] ?? null) ? $tag['Value'] : '';
            }
        }
        return $tags;
    }

    private function queueUrl(string $name): string
    {
        return Aws::string(Aws::call($this->aws->sqs(), 'GetQueueUrl', ['QueueName' => $name])['QueueUrl'] ?? null)
            ?? throw new \UnexpectedValueException("sqs:GetQueueUrl returned no URL for $name.");
    }

    private function deleteQueue(string $name): void
    {
        Aws::call($this->aws->sqs(), 'DeleteQueue', ['QueueUrl' => $this->queueUrl($name)]);
    }

    /** Deletes every object version and delete marker, then the bucket; S3 refuses if it is not the guarded account's. */
    private function deleteBucket(string $name): void
    {
        $s3 = $this->aws->s3();
        do {
            $page = Aws::call($s3, 'ListObjectVersions', ['Bucket' => $name, 'ExpectedBucketOwner' => $this->account, 'MaxKeys' => 1000]);
            $objects = [];
            foreach ([...Aws::maps($page['Versions'] ?? []), ...Aws::maps($page['DeleteMarkers'] ?? [])] as $version) {
                $key = Aws::string($version['Key'] ?? null);
                if ($key !== null) {
                    $objects[] = ['Key' => $key, 'VersionId' => Aws::string($version['VersionId'] ?? null) ?? 'null'];
                }
            }
            if ($objects !== []) {
                $result = Aws::call($s3, 'DeleteObjects', ['Bucket' => $name, 'ExpectedBucketOwner' => $this->account, 'Delete' => ['Objects' => $objects, 'Quiet' => true]]);
                $errors = Aws::maps($result['Errors'] ?? []);
                if ($errors !== []) {
                    throw new \RuntimeException("s3:DeleteObjects failed in $name: " . json_encode($errors));
                }
            }
            // Always list again from the start: what was listed is gone, so no marker is needed.
        } while ($objects !== [] && ($page['IsTruncated'] ?? false) === true);
        Aws::call($s3, 'DeleteBucket', ['Bucket' => $name, 'ExpectedBucketOwner' => $this->account]);
    }

    /** @return list<string> */
    private function bucketsNamed(string $prefix): array
    {
        $names = [];
        $token = null;
        do {
            $page = Aws::call($this->aws->s3(), 'ListBuckets', ['Prefix' => $prefix, ...($token === null ? [] : ['ContinuationToken' => $token])]);
            foreach (Aws::maps($page['Buckets'] ?? []) as $bucket) {
                $name = Aws::string($bucket['Name'] ?? null);
                if ($name !== null && str_starts_with($name, $prefix)) {
                    $names[] = $name;
                }
            }
            $token = Aws::string($page['ContinuationToken'] ?? null);
        } while ($token !== null);
        return $names;
    }

    /** @return list<string> */
    private function tablesNamed(string $prefix): array
    {
        $names = [];
        $start = null;
        do {
            $page = Aws::call($this->aws->dynamoDb(), 'ListTables', $start === null ? [] : ['ExclusiveStartTableName' => $start]);
            foreach (Aws::strings($page['TableNames'] ?? []) as $name) {
                if (str_starts_with($name, $prefix)) {
                    $names[] = $name;
                }
            }
            $start = Aws::string($page['LastEvaluatedTableName'] ?? null);
        } while ($start !== null);
        return $names;
    }

    /** @return list<string> */
    private function queuesNamed(string $prefix): array
    {
        $names = [];
        $token = null;
        do {
            $page = Aws::call($this->aws->sqs(), 'ListQueues', ['QueueNamePrefix' => $prefix, ...($token === null ? [] : ['NextToken' => $token])]);
            foreach (Aws::strings($page['QueueUrls'] ?? []) as $url) {
                $name = basename($url);
                if (str_starts_with($name, $prefix)) {
                    $names[] = $name;
                }
            }
            $token = Aws::string($page['NextToken'] ?? null);
        } while ($token !== null);
        return $names;
    }

    /**
     * Buckets, tables and queues the Resource Groups Tagging API lists with both run tags, whatever
     * their names (plan() then skips and reports those outside the run's name prefix).
     *
     * @return list<array{type: string, name: string}>
     */
    private function taggedResources(string $app): array
    {
        $found = [];
        $token = null;
        do {
            $page = Aws::call($this->aws->taggingApi(), 'GetResources', [
                'ResourceTypeFilters' => ['s3', 'dynamodb:table', 'sqs'],
                'TagFilters' => [
                    ['Key' => 'arco:app', 'Values' => [$app]],
                    ['Key' => 'arco:environment', 'Values' => [self::ENVIRONMENT]],
                ],
                ...($token === null ? [] : ['PaginationToken' => $token]),
            ]);
            foreach (Aws::maps($page['ResourceTagMappingList'] ?? []) as $resource) {
                $arn = Aws::string($resource['ResourceARN'] ?? null) ?? '';
                if (preg_match('/^arn:[^:]+:s3:::([^\/]+)$/', $arn, $m) === 1) {
                    $found[] = ['type' => self::BUCKET, 'name' => $m[1]];
                } elseif (preg_match('/^arn:[^:]+:dynamodb:[^:]*:\d*:table\/([^\/]+)$/', $arn, $m) === 1) {
                    $found[] = ['type' => self::TABLE, 'name' => $m[1]];
                } elseif (preg_match('/^arn:[^:]+:sqs:[^:]*:\d*:([^:\/]+)$/', $arn, $m) === 1) {
                    $found[] = ['type' => self::QUEUE, 'name' => $m[1]];
                }
            }
            $token = Aws::string($page['PaginationToken'] ?? null);
        } while ($token !== null);
        return $found;
    }

    private function parameterExists(string $name): bool
    {
        $page = Aws::call($this->aws->ssm(), 'DescribeParameters', [
            'ParameterFilters' => [['Key' => 'Name', 'Option' => 'Equals', 'Values' => [$name]]],
        ]);
        foreach (Aws::maps($page['Parameters'] ?? []) as $parameter) {
            if (($parameter['Name'] ?? null) === $name) {
                return true;
            }
        }
        return false;
    }

    private function skip(string $type, string $name, string $reason): void
    {
        $this->skipped[] = ['type' => $type, 'name' => $name, 'reason' => $reason];
        ($this->say)("[cleanup] SKIPPED $type $name: $reason");
    }
}
