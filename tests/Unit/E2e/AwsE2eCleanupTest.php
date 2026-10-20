<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\E2e;

use Multiapi\Arco\Aws\AccountMismatch;
use Multiapi\Arco\Tests\E2e\AwsE2eCleanup;
use PHPUnit\Framework\TestCase;

/**
 * The only code in the project that deletes real AWS resources, exercised against FakeAwsAccount
 * (in memory: no network, no profile, no real path).
 */
final class AwsE2eCleanupTest extends TestCase
{
    private const APP = 'arco-e2e-0123abcd';
    private const ACCOUNT = '111122223333';
    private const BUCKET = 'arco-e2e-0123abcd-e2e-files-111122223333';
    private const TABLE = 'arco-e2e-0123abcd-e2e-cache';
    private const QUEUE = 'arco-e2e-0123abcd-e2e-default';
    private const DLQ = 'arco-e2e-0123abcd-e2e-default-dlq';
    private const PARAMETER = '/arco-e2e-0123abcd/e2e/app-key';
    private const TAGS = ['arco:app' => self::APP, 'arco:environment' => 'e2e', 'arco:resource' => 'x'];

    private FakeAwsAccount $aws;

    protected function setUp(): void
    {
        $this->aws = new FakeAwsAccount(self::ACCOUNT);
    }

    /** What a finished run leaves behind: retained bucket, table, queue and DLQ, and the APP_KEY parameter. */
    private function leftovers(): void
    {
        $this->aws->bucket(self::BUCKET, self::TAGS, objects: 5);
        $this->aws->tables[self::TABLE] = self::TAGS;
        $this->aws->queues[self::QUEUE] = self::TAGS;
        $this->aws->queues[self::DLQ] = self::TAGS;
        $this->aws->parameters[self::PARAMETER] = ['arco:app' => self::APP];
    }

    private const WEB_GROUP = '/aws/lambda/web/arco-e2e-0123abcd-e2e';
    private const LOCAL_GROUP = '/aws/lambda/web/arco-e2e-0123abcd-e2e.local';
    private const CONSOLE_GROUP = '/aws/lambda/arco-e2e-0123abcd-e2e-console';
    private const WORKER_GROUP = '/aws/lambda/arco-e2e-0123abcd-e2e-queue-default';

    /** The run's log groups: Arco-created (tagged), and Lambda-created (untagged). */
    private function logGroups(): void
    {
        $this->aws->logGroups[self::WEB_GROUP] = ['arco:app' => self::APP, 'arco:environment' => 'e2e'];
        $this->aws->logGroups[self::LOCAL_GROUP] = [];
        $this->aws->logGroups[self::CONSOLE_GROUP] = ['arco:app' => self::APP, 'arco:environment' => 'e2e'];
        $this->aws->logGroups[self::WORKER_GROUP] = [];
    }

    private function cleanup(): AwsE2eCleanup
    {
        return AwsE2eCleanup::connect($this->aws->clients(), self::ACCOUNT, []);
    }

    public function test_plans_the_tagged_bucket_table_queue_and_dlq_and_the_parameter(): void
    {
        $this->leftovers();

        $plan = $this->cleanup()->plan(self::APP);

        self::assertEqualsCanonicalizing([
            ['type' => 'sqs-queue', 'name' => self::QUEUE],
            ['type' => 'sqs-queue', 'name' => self::DLQ],
            ['type' => 'dynamodb-table', 'name' => self::TABLE],
            ['type' => 's3-bucket', 'name' => self::BUCKET],
            ['type' => 'ssm-parameter', 'name' => self::PARAMETER],
        ], $plan);
    }

    public function test_plan_is_a_dry_run_that_calls_no_mutating_api(): void
    {
        $this->leftovers();
        $before = [$this->aws->buckets, $this->aws->tables, $this->aws->queues, $this->aws->parameters];

        $this->cleanup()->plan(self::APP);

        self::assertSame([], $this->aws->mutatingCalls());
        self::assertSame($before, [$this->aws->buckets, $this->aws->tables, $this->aws->queues, $this->aws->parameters]);
    }

    public function test_same_name_resources_without_the_run_tags_are_skipped_and_reported(): void
    {
        $this->leftovers();
        // Right name, no tags at all; right name, another app's tags; right name, another environment.
        $this->aws->bucket(self::BUCKET, null, objects: 1);
        $this->aws->tables[self::TABLE] = ['arco:app' => 'arco-example', 'arco:environment' => 'e2e'];
        $this->aws->queues[self::QUEUE] = ['arco:app' => self::APP, 'arco:environment' => 'production'];

        $cleanup = $this->cleanup();
        $plan = $cleanup->plan(self::APP);

        self::assertEqualsCanonicalizing([
            ['type' => 'sqs-queue', 'name' => self::DLQ],
            ['type' => 'ssm-parameter', 'name' => self::PARAMETER],
        ], $plan);
        $skipped = array_map(static fn (array $s): string => "{$s['type']} {$s['name']}", $cleanup->skipped());
        self::assertEqualsCanonicalizing(['s3-bucket ' . self::BUCKET, 'dynamodb-table ' . self::TABLE, 'sqs-queue ' . self::QUEUE], $skipped);
        foreach ($cleanup->skipped() as $skip) {
            self::assertStringContainsString('arco:app=' . self::APP, $skip['reason']);
        }

        $cleanup->apply($plan);

        self::assertArrayHasKey(self::BUCKET, $this->aws->buckets);
        self::assertCount(1, $this->aws->buckets[self::BUCKET]['versions']);
        self::assertArrayHasKey(self::TABLE, $this->aws->tables);
        self::assertArrayHasKey(self::QUEUE, $this->aws->queues);
        self::assertArrayNotHasKey(self::DLQ, $this->aws->queues);
        self::assertSame([], $this->aws->parameters);
    }

    public function test_a_tagged_resource_outside_the_run_name_prefix_is_skipped_and_reported(): void
    {
        $this->aws->bucket('somebody-elses-bucket', self::TAGS);

        $cleanup = $this->cleanup();

        self::assertSame([], $cleanup->plan(self::APP));
        self::assertSame('somebody-elses-bucket', $cleanup->skipped()[0]['name'] ?? null);
    }

    public function test_apply_empties_the_bucket_first_then_deletes_everything_planned(): void
    {
        $this->leftovers();
        $this->aws->bucket('arco-example-e2e-files-111122223333', ['arco:app' => 'arco-example', 'arco:environment' => 'e2e'], objects: 1);
        $this->aws->tables['arco-example-e2e-cache'] = ['arco:app' => 'arco-example', 'arco:environment' => 'e2e'];
        $this->aws->parameters['/arco-example/e2e/app-key'] = [];

        $cleanup = $this->cleanup();
        $cleanup->apply($cleanup->plan(self::APP));

        self::assertSame(['arco-example-e2e-files-111122223333'], array_keys($this->aws->buckets));
        self::assertSame(['arco-example-e2e-cache'], array_keys($this->aws->tables));
        self::assertSame([], $this->aws->queues);
        self::assertSame(['/arco-example/e2e/app-key'], array_keys($this->aws->parameters));
        $calls = $this->aws->calls;
        self::assertLessThan(array_search('DeleteBucket', $calls, true), array_search('DeleteObjects', $calls, true));
        self::assertSame(3, count(array_keys($calls, 'DeleteObjects', true)), '5 versions, 2 per page');
    }

    public function test_every_s3_bucket_call_names_the_guarded_account_as_expected_owner(): void
    {
        $this->leftovers();

        $cleanup = $this->cleanup();
        $cleanup->apply($cleanup->plan(self::APP));

        $operations = array_unique(array_column($this->aws->bucketOwners, 0));
        sort($operations);
        self::assertSame(['DeleteBucket', 'DeleteObjects', 'GetBucketTagging', 'ListObjectVersions'], $operations);
        foreach ($this->aws->bucketOwners as [$operation, $owner]) {
            self::assertSame(self::ACCOUNT, $owner, $operation);
        }
    }

    public function test_a_bucket_of_another_account_is_not_emptied(): void
    {
        $this->leftovers();
        $cleanup = $this->cleanup();
        $plan = $cleanup->plan(self::APP);
        // The same names now answer for another owner: S3 refuses the calls that name the expected owner.
        $this->aws->account = '999999999999';

        try {
            $cleanup->apply($plan);
            self::fail('apply() must report the bucket it could not delete.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('s3-bucket ' . self::BUCKET . ': AccessDenied', $e->getMessage());
        }

        self::assertCount(5, $this->aws->buckets[self::BUCKET]['versions']);
        self::assertNotContains('DeleteObjects', $this->aws->calls);
        self::assertNotContains('DeleteBucket', $this->aws->calls);
    }

    public function test_apply_checks_the_tags_again_before_deleting(): void
    {
        $this->leftovers();
        $cleanup = $this->cleanup();
        $plan = $cleanup->plan(self::APP);

        // Between plan and apply the names now belong to something else.
        $this->aws->bucket(self::BUCKET, ['arco:app' => 'arco-example', 'arco:environment' => 'e2e'], objects: 2);
        $this->aws->tables[self::TABLE] = [];

        $cleanup->apply($plan);

        self::assertArrayHasKey(self::BUCKET, $this->aws->buckets);
        self::assertCount(2, $this->aws->buckets[self::BUCKET]['versions']);
        self::assertArrayHasKey(self::TABLE, $this->aws->tables);
        self::assertSame([], $this->aws->queues);
        self::assertNotContains('DeleteObjects', $this->aws->calls);
        self::assertCount(2, $cleanup->skipped());
    }

    public function test_apply_refuses_items_that_are_not_this_runs(): void
    {
        $this->aws->bucket('arco-example-e2e-files-111122223333', ['arco:app' => 'arco-example', 'arco:environment' => 'e2e']);
        $this->aws->parameters['/arco-example/e2e/app-key'] = [];
        $cleanup = $this->cleanup();
        $cleanup->plan(self::APP);

        $cleanup->apply([
            ['type' => 's3-bucket', 'name' => 'arco-example-e2e-files-111122223333'],
            ['type' => 'ssm-parameter', 'name' => '/arco-example/e2e/app-key'],
            ['type' => 'iam-role', 'name' => self::APP . '-e2e-role'],
        ]);

        self::assertSame([], $this->aws->mutatingCalls());
        self::assertCount(3, $cleanup->skipped());
    }

    public function test_apply_needs_a_plan_from_the_same_cleanup(): void
    {
        $this->leftovers();

        try {
            $this->cleanup()->apply([['type' => 'sqs-queue', 'name' => self::QUEUE]]);
            self::fail('apply without plan must throw');
        } catch (\LogicException) {
        }
        self::assertSame([], $this->aws->mutatingCalls());
        self::assertArrayHasKey(self::QUEUE, $this->aws->queues);
    }

    public function test_plan_refuses_an_app_name_that_is_not_a_run_name(): void
    {
        $this->aws->bucket('arco-example-e2e-files-111122223333', ['arco:app' => 'arco-example', 'arco:environment' => 'e2e']);
        $cleanup = $this->cleanup();
        $calls = count($this->aws->calls);

        foreach (['arco-example', 'arco-e2e-', 'arco-e2e-0123ABCD', 'arco-e2e-0123abcd-x', ''] as $app) {
            try {
                $cleanup->plan($app);
                self::fail("plan('$app') must throw");
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertCount($calls, $this->aws->calls);
    }

    public function test_wrong_account_is_refused_before_any_other_call(): void
    {
        $this->leftovers();
        $this->aws->account = '999999999999';

        try {
            AwsE2eCleanup::connect($this->aws->clients(), self::ACCOUNT, []);
            self::fail('a different account must be refused');
        } catch (AccountMismatch $e) {
            self::assertSame('999999999999', $e->actual);
        }
        self::assertSame(['GetCallerIdentity'], $this->aws->calls);
    }

    public function test_endpoint_override_or_bad_account_id_is_refused_before_any_call(): void
    {
        $cases = [
            [self::ACCOUNT, ['AWS_ENDPOINT_URL' => 'http://localhost:4566']],
            [self::ACCOUNT, ['AWS_ENDPOINT_URL_S3' => 'http://localhost:4566']],
            ['', []],
            ['12345', []],
            ['1111222233334', []],
        ];
        foreach ($cases as [$account, $env]) {
            try {
                AwsE2eCleanup::connect($this->aws->clients(), $account, $env);
                self::fail('must refuse: ' . json_encode([$account, $env]));
            } catch (\RuntimeException $e) {
                self::assertNotInstanceOf(AccountMismatch::class, $e);
            }
        }
        self::assertSame([], $this->aws->calls);
    }

    public function test_new_app_names_are_run_names(): void
    {
        self::assertMatchesRegularExpression(AwsE2eCleanup::APP_PATTERN, AwsE2eCleanup::newAppName());
        self::assertNotSame(AwsE2eCleanup::newAppName(), AwsE2eCleanup::newAppName());
    }

    public function test_plans_the_runs_log_groups_tagged_or_made_untagged_by_lambda_and_never_others(): void
    {
        $this->leftovers();
        $this->logGroups();
        // Never: another run's, a real app's, a sibling-named group, and groups tagged for someone else.
        $this->aws->logGroups['/aws/lambda/web/arco-e2e-ffffffff-e2e'] = [];
        $this->aws->logGroups['/aws/lambda/web/shop-production'] = [];
        $this->aws->logGroups['/aws/lambda/arco-e2e-0123abcd-e2e2-console'] = [];
        $this->aws->logGroups['/aws/lambda/arco-e2e-0123abcd-e2e-other'] = ['arco:app' => 'shop', 'arco:environment' => 'production'];
        $this->aws->logGroups['/aws/lambda/arco-e2e-0123abcd-e2e-half'] = ['arco:app' => self::APP];
        $this->aws->logGroups['/aws/lambda/other'] = self::TAGS;

        $cleanup = $this->cleanup();
        $plan = $cleanup->plan(self::APP);

        $groups = array_column(array_filter($plan, static fn (array $i): bool => $i['type'] === 'logs-log-group'), 'name');
        self::assertEqualsCanonicalizing([self::WEB_GROUP, self::LOCAL_GROUP, self::CONSOLE_GROUP, self::WORKER_GROUP], $groups);
        $skipped = array_column($cleanup->skipped(), 'name');
        self::assertContains('/aws/lambda/arco-e2e-0123abcd-e2e-other', $skipped);
        self::assertContains('/aws/lambda/arco-e2e-0123abcd-e2e-half', $skipped);
        self::assertNotContains('/aws/lambda/web/arco-e2e-ffffffff-e2e', $skipped, 'another run\'s group is not even a candidate');
        self::assertSame([], $this->aws->mutatingCalls(), 'plan is a dry run');

        $cleanup->apply($plan);

        self::assertEqualsCanonicalizing([
            '/aws/lambda/web/arco-e2e-ffffffff-e2e', '/aws/lambda/web/shop-production', '/aws/lambda/arco-e2e-0123abcd-e2e2-console',
            '/aws/lambda/arco-e2e-0123abcd-e2e-other', '/aws/lambda/arco-e2e-0123abcd-e2e-half', '/aws/lambda/other',
        ], array_keys($this->aws->logGroups));
        self::assertSame(4, count(array_keys($this->aws->calls, 'DeleteLogGroup', true)));
    }

    public function test_apply_checks_a_log_groups_tags_again_and_reports_each_delete(): void
    {
        $this->logGroups();
        $said = [];
        $cleanup = AwsE2eCleanup::connect($this->aws->clients(), self::ACCOUNT, [], function (string $line) use (&$said): void { $said[] = $line; });
        $plan = $cleanup->plan(self::APP);

        // Between plan and apply one group is retagged for another app and one disappears.
        $this->aws->logGroups[self::WEB_GROUP] = ['arco:app' => 'shop', 'arco:environment' => 'e2e'];
        unset($this->aws->logGroups[self::WORKER_GROUP]);
        $cleanup->apply($plan);

        self::assertSame([self::WEB_GROUP], array_keys($this->aws->logGroups));
        self::assertContains('[cleanup] deleted logs-log-group ' . self::CONSOLE_GROUP, $said);
        self::assertContains('[cleanup] deleted logs-log-group ' . self::LOCAL_GROUP, $said);
        self::assertCount(2, $cleanup->skipped());
    }

    public function test_apply_refuses_a_log_group_that_is_not_this_runs(): void
    {
        $this->aws->logGroups['/aws/lambda/web/shop-production'] = [];
        $cleanup = $this->cleanup();
        $cleanup->plan(self::APP);

        $cleanup->apply([['type' => 'logs-log-group', 'name' => '/aws/lambda/web/shop-production']]);

        self::assertSame([], $this->aws->mutatingCalls());
        self::assertCount(1, $cleanup->skipped());
    }

    public function test_log_groups_that_cannot_be_listed_are_reported_and_the_rest_is_still_planned(): void
    {
        $this->leftovers();
        $this->logGroups();
        $this->aws->denied = ['DescribeLogGroups'];
        $cleanup = $this->cleanup();

        $plan = $cleanup->plan(self::APP);

        self::assertCount(5, $plan, 'bucket, table, queue, DLQ and parameter are still planned');
        self::assertNotContains('logs-log-group', array_column($plan, 'type'));
        $reasons = array_column($cleanup->skipped(), 'reason');
        self::assertCount(1, $reasons);
        self::assertStringStartsWith('log groups not checked: AccessDeniedException', $reasons[0]);
        self::assertSame([], $this->aws->mutatingCalls());
    }

    public function test_log_groups_whose_tags_cannot_be_read_are_never_planned(): void
    {
        $this->leftovers();
        $this->logGroups();
        $this->aws->denied = ['ListTagsForResource'];
        $cleanup = $this->cleanup();

        $plan = $cleanup->plan(self::APP);
        $cleanup->apply($plan);

        self::assertCount(5, $plan);
        self::assertCount(4, array_filter($cleanup->skipped(), static fn (array $s): bool => str_starts_with($s['reason'], 'log groups not checked: AccessDeniedException')));
        self::assertNotContains('DeleteLogGroup', $this->aws->calls);
        self::assertCount(4, $this->aws->logGroups, 'every log group is still there');
    }
}
