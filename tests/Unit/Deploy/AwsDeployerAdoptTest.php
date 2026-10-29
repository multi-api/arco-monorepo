<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy;

use Aws\CloudFormation\Exception\CloudFormationException;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\Sqs\Exception\SqsException;
use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ConfigLayer;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Config\Environment;
use Multiapi\Arco\Deploy\AwsDeployer;
use Multiapi\Arco\Deploy\ChangeTrackingDeployer;
use Multiapi\Arco\Deploy\DeployException;
use Multiapi\Arco\Deploy\ResourcesStack;
use PHPUnit\Framework\TestCase;

/** A config layer entry with `adopt: true` imports a retained queue, bucket or table that is provably this environment's. */
final class AwsDeployerAdoptTest extends TestCase
{
    private const STACK = 'arco-shop-production-resources';
    private const QUEUE_URL = 'https://sqs.us-east-1.amazonaws.com/111111111111/shop-production-emails';
    private const DLQ_URL = 'https://sqs.us-east-1.amazonaws.com/111111111111/shop-production-emails-dlq';
    private const BUCKET = 'shop-production-uploads-111111111111';

    /** @param array<string, mixed> $entry the layer's environment entry */
    private static function config(array $entry): AppConfig
    {
        $raw = App::make('shop')->region('us-east-1')->environment('production', fn (Environment $e) => $e->account('111111111111'))->toArray();
        /** @var array{queues?: array<string, array<string, mixed>>, storage?: array<string, array<string, mixed>>, tables?: array<string, array<string, mixed>>} $entry */
        return ConfigResolver::resolve($raw, 'production', new ConfigLayer('layer.json', 'shop', 'us-east-1', 'production', $entry));
    }

    private static function adoptQueue(): AppConfig
    {
        return self::config(['queues' => ['emails' => ['adopt' => true]]]);
    }

    private static function stackMissing(): CloudFormationException
    {
        return AwsMocks::error(CloudFormationException::class, 'DescribeStacks', 'ValidationError', 400, 'Stack with id ' . self::STACK . ' does not exist');
    }

    private static function stack(string $status = 'UPDATE_COMPLETE'): Result
    {
        return new Result(['Stacks' => [[
            'StackName' => self::STACK,
            'StackStatus' => $status,
            'Tags' => [['Key' => 'arco:app', 'Value' => 'shop'], ['Key' => 'arco:environment', 'Value' => 'production']],
        ]]]);
    }

    /** @return array<string, string> */
    private static function tags(string $resource, string $environment = 'production'): array
    {
        return ['arco:app' => 'shop', 'arco:environment' => $environment, 'arco:resource' => $resource];
    }

    /** @param array<string, string> $tags @return list<array{Key: string, Value: string}> */
    private static function tagList(array $tags): array
    {
        return array_map(fn (string $k, string $v) => ['Key' => $k, 'Value' => $v], array_keys($tags), array_values($tags));
    }

    /** The calls of a successful import of a new stack, then its update. @return list<Result> */
    private static function importThenUpdate(): array
    {
        return [
            self::stackMissing(),                                  // Stacks::import
            new Result(['Id' => 'cs']),                            // CreateChangeSet
            new Result(['Status' => 'CREATE_COMPLETE']),           // DescribeChangeSet
            new Result([]),                                        // ExecuteChangeSet
            new Result(['ExecutionStatus' => 'EXECUTE_COMPLETE']), // DescribeChangeSet
            self::stack('IMPORT_COMPLETE'),
            self::stack('IMPORT_COMPLETE'),                        // Stacks::deploy
            new Result([]),                                        // UpdateStack
            self::stack('UPDATE_COMPLETE'),
        ];
    }

    /** @return list<array{0: string, 1: array<string, string|int>}> */
    private static function deployResources(AwsMocks $mocks, AppConfig $config): array
    {
        $steps = [];
        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->deployResources($config, function (string $key, array $params) use (&$steps): void { $steps[] = [$key, $params]; });
        return $steps;
    }

    private static function expectError(AwsMocks $mocks, AppConfig $config, string $key): DeployException
    {
        try {
            self::deployResources($mocks, $config);
        } catch (DeployException $e) {
            self::assertSame($key, $e->key);
            return $e;
        }
        self::fail("expected $key");
    }

    /** @param list<string> $calls */
    private static function assertNoCloudFormationWrite(array $calls): void
    {
        self::assertSame([], array_values(array_intersect($calls, ['CreateChangeSet', 'ExecuteChangeSet', 'CreateStack', 'UpdateStack', 'DeleteStack'])));
    }

    public function test_owned_adopt_entry_is_a_normal_update(): void
    {
        $config = self::adoptQueue();
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::stack(),
            new Result(['StackResources' => [
                ['LogicalResourceId' => 'QueueEmails', 'PhysicalResourceId' => self::QUEUE_URL, 'ResourceType' => 'AWS::SQS::Queue', 'ResourceStatus' => 'CREATE_COMPLETE'],
                ['LogicalResourceId' => 'QueueEmailsDlq', 'PhysicalResourceId' => self::DLQ_URL, 'ResourceType' => 'AWS::SQS::Queue', 'ResourceStatus' => 'CREATE_COMPLETE'],
            ]]),
            new Result(['TemplateBody' => ResourcesStack::body(ResourcesStack::template($config))]),
            self::stack(),
            new Result([]),
            self::stack(),
        );

        $steps = self::deployResources($mocks, $config);

        self::assertSame(['DescribeStacks', 'DescribeStackResources', 'GetTemplate', 'DescribeStacks', 'UpdateStack', 'DescribeStacks'], $mocks->calls);
        self::assertSame([
            ['deploy.step_resources', ['stack' => self::STACK]],
            ['deploy.queue_updated', ['queue' => 'emails', 'name' => 'shop-production-emails']],
        ], $steps);
    }

    public function test_a_free_adopt_entry_is_created(): void
    {
        $mocks = new AwsMocks();
        $missing = AwsMocks::error(SqsException::class, 'GetQueueUrl', 'AWS.SimpleQueueService.NonExistentQueue', 400);
        $mocks->handler->append(self::stackMissing(), $missing, $missing, self::stackMissing(), new Result([]), self::stack('CREATE_COMPLETE'));

        $steps = self::deployResources($mocks, self::adoptQueue());

        self::assertSame(['DescribeStacks', 'GetQueueUrl', 'GetQueueUrl', 'DescribeStacks', 'CreateStack', 'DescribeStacks'], $mocks->calls);
        self::assertSame('deploy.queue_created', $steps[1][0]);
    }

    public function test_retained_queue_with_matching_tags_is_imported(): void
    {
        $config = self::adoptQueue();
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::stackMissing(),
            new Result(['QueueUrl' => self::QUEUE_URL]),
            new Result(['Tags' => self::tags('queue:emails')]),
            new Result(['QueueUrl' => self::DLQ_URL]),
            new Result(['Tags' => self::tags('queue:emails:dlq')]),
            ...self::importThenUpdate(),
        );

        $steps = self::deployResources($mocks, $config);

        self::assertSame([
            'DescribeStacks', 'GetQueueUrl', 'ListQueueTags', 'GetQueueUrl', 'ListQueueTags',
            'DescribeStacks', 'CreateChangeSet', 'DescribeChangeSet', 'ExecuteChangeSet', 'DescribeChangeSet', 'DescribeStacks',
            'DescribeStacks', 'UpdateStack', 'DescribeStacks',
        ], $mocks->calls);
        self::assertSame(self::QUEUE_URL, $mocks->params[2]['QueueUrl']);
        $create = $mocks->params[6];
        self::assertSame('IMPORT', $create['ChangeSetType']);
        self::assertSame([
            ['ResourceType' => 'AWS::SQS::Queue', 'LogicalResourceId' => 'QueueEmails', 'ResourceIdentifier' => ['QueueUrl' => self::QUEUE_URL]],
            ['ResourceType' => 'AWS::SQS::Queue', 'LogicalResourceId' => 'QueueEmailsDlq', 'ResourceIdentifier' => ['QueueUrl' => self::DLQ_URL]],
        ], $create['ResourcesToImport']);
        $template = json_decode((string) $create['TemplateBody'], true);
        self::assertIsArray($template);
        self::assertSame(['QueueEmails', 'QueueEmailsDlq'], array_keys($template['Resources']));
        self::assertSame([
            'Type' => 'AWS::SQS::Queue',
            'DeletionPolicy' => 'Retain',
            'UpdateReplacePolicy' => 'Retain',
            'Properties' => ['QueueName' => 'shop-production-emails', 'Tags' => self::tagList(self::tags('queue:emails'))],
        ], $template['Resources']['QueueEmails'], 'literal properties only (no redrive policy referencing the DLQ), always retained');
        self::assertArrayNotHasKey('Outputs', $template);
        // Then the full template, with every option.
        $full = json_decode((string) $mocks->params[12]['TemplateBody'], true);
        self::assertIsArray($full);
        self::assertArrayHasKey('RedrivePolicy', $full['Resources']['QueueEmails']['Properties']);
        self::assertSame(ResourcesStack::DELETION_POLICY, $full['Resources']['QueueEmails']['DeletionPolicy'] ?? null, 'the update sets the usual policy');
        self::assertSame([
            ['deploy.step_resources', ['stack' => self::STACK]],
            ['deploy.step_import', ['stack' => self::STACK, 'count' => 2]],
            ['deploy.queue_adopted', ['queue' => 'emails', 'name' => 'shop-production-emails']],
        ], $steps);
    }

    public function test_a_queue_without_an_arco_resource_tag_is_imported(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::stackMissing(),
            new Result(['QueueUrl' => self::QUEUE_URL]),
            new Result(['Tags' => ['arco:app' => 'shop', 'arco:environment' => 'production']]),
            new Result(['QueueUrl' => self::DLQ_URL]),
            new Result(['Tags' => ['arco:app' => 'shop', 'arco:environment' => 'production']]),
            ...self::importThenUpdate(),
        );

        $steps = self::deployResources($mocks, self::adoptQueue());

        self::assertContains(['deploy.queue_adopted', ['queue' => 'emails', 'name' => 'shop-production-emails']], $steps);
    }

    public function test_an_owned_queue_with_a_retained_dlq_imports_the_dlq(): void
    {
        $config = self::adoptQueue();
        $deployed = ResourcesStack::template(self::config(['queues' => ['emails' => ['dlq' => false]]]));
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::stack(),
            new Result(['StackResources' => [
                ['LogicalResourceId' => 'QueueEmails', 'PhysicalResourceId' => self::QUEUE_URL, 'ResourceType' => 'AWS::SQS::Queue', 'ResourceStatus' => 'CREATE_COMPLETE'],
            ]]),
            new Result(['QueueUrl' => self::DLQ_URL]),
            new Result(['Tags' => self::tags('queue:emails:dlq')]),
            new Result(['TemplateBody' => ResourcesStack::body($deployed)]),   // GetTemplate, for the import
            self::stack(),                                                     // Stacks::import
            new Result(['Id' => 'cs']),
            new Result(['Status' => 'CREATE_COMPLETE']),
            new Result([]),
            new Result(['ExecutionStatus' => 'EXECUTE_COMPLETE']),
            self::stack('IMPORT_COMPLETE'),
            self::stack('IMPORT_COMPLETE'),
            new Result([]),
            self::stack(),
        );

        $steps = self::deployResources($mocks, $config);

        self::assertSame([
            'DescribeStacks', 'DescribeStackResources', 'GetQueueUrl', 'ListQueueTags', 'GetTemplate',
            'DescribeStacks', 'CreateChangeSet', 'DescribeChangeSet', 'ExecuteChangeSet', 'DescribeChangeSet', 'DescribeStacks',
            'DescribeStacks', 'UpdateStack', 'DescribeStacks',
        ], $mocks->calls);
        self::assertSame([['ResourceType' => 'AWS::SQS::Queue', 'LogicalResourceId' => 'QueueEmailsDlq', 'ResourceIdentifier' => ['QueueUrl' => self::DLQ_URL]]], $mocks->params[6]['ResourcesToImport']);
        $template = json_decode((string) $mocks->params[6]['TemplateBody'], true);
        self::assertIsArray($template);
        self::assertSame($deployed['Resources']['QueueEmails'], $template['Resources']['QueueEmails'], 'the deployed definition, unchanged');
        self::assertSame($deployed['Outputs'], $template['Outputs']);
        self::assertSame([
            ['deploy.step_resources', ['stack' => self::STACK]],
            ['deploy.step_import', ['stack' => self::STACK, 'count' => 1]],
            ['deploy.queue_changed', ['queue' => 'emails', 'name' => 'shop-production-emails']],
        ], $steps);
    }

    public function test_retained_bucket_and_table_are_imported(): void
    {
        $config = self::config(['storage' => ['uploads' => ['adopt' => true]], 'tables' => ['state' => ['adopt' => true]]]);
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::stackMissing(),
            new Result([]),                                                                // HeadBucket: ours
            new Result(['TagSet' => self::tagList(self::tags('storage:uploads'))]),       // GetBucketTagging
            new Result(['Table' => ['TableName' => 'shop-production-state']]),            // DescribeTable: exists
            new Result(['Table' => ['TableArn' => 'arn:aws:dynamodb:us-east-1:111111111111:table/shop-production-state']]),
            new Result(['Tags' => self::tagList(self::tags('table:state'))]),             // ListTagsOfResource
            ...self::importThenUpdate(),
        );

        $steps = self::deployResources($mocks, $config);

        self::assertSame([
            'DescribeStacks', 'HeadBucket', 'GetBucketTagging', 'DescribeTable', 'DescribeTable', 'ListTagsOfResource',
            'DescribeStacks', 'CreateChangeSet', 'DescribeChangeSet', 'ExecuteChangeSet', 'DescribeChangeSet', 'DescribeStacks',
            'DescribeStacks', 'UpdateStack', 'DescribeStacks',
        ], $mocks->calls);
        self::assertSame('arn:aws:dynamodb:us-east-1:111111111111:table/shop-production-state', $mocks->params[5]['ResourceArn']);
        self::assertSame([
            ['ResourceType' => 'AWS::S3::Bucket', 'LogicalResourceId' => 'StorageUploads', 'ResourceIdentifier' => ['BucketName' => self::BUCKET]],
            ['ResourceType' => 'AWS::DynamoDB::Table', 'LogicalResourceId' => 'AppTableState', 'ResourceIdentifier' => ['TableName' => 'shop-production-state']],
        ], $mocks->params[7]['ResourcesToImport']);
        $template = json_decode((string) $mocks->params[7]['TemplateBody'], true);
        self::assertIsArray($template);
        self::assertSame(['BucketName' => self::BUCKET, 'Tags' => self::tagList(self::tags('storage:uploads'))], $template['Resources']['StorageUploads']['Properties']);
        self::assertSame('Retain', $template['Resources']['StorageUploads']['DeletionPolicy']);
        self::assertSame(array_replace(ResourcesStack::template($config)['Resources']['AppTableState'], ['DeletionPolicy' => 'Retain', 'UpdateReplacePolicy' => 'Retain']), $template['Resources']['AppTableState']);
        self::assertSame([
            ['deploy.step_resources', ['stack' => self::STACK]],
            ['deploy.step_import', ['stack' => self::STACK, 'count' => 2]],
            ['deploy.storage_adopted', ['storage' => 'uploads', 'bucket' => self::BUCKET]],
            ['deploy.generic_table_adopted', ['table' => 'state', 'name' => 'shop-production-state']],
        ], $steps);
    }

    public function test_tags_of_another_environment_keep_the_retained_error(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::stackMissing(),
            new Result(['QueueUrl' => self::QUEUE_URL]),
            new Result(['Tags' => self::tags('queue:emails', 'staging')]),
        );

        $e = self::expectError($mocks, self::adoptQueue(), 'deploy.queue_retained');

        self::assertSame(['queue' => 'emails', 'existing' => "queue('emails', existing: 'arn:aws:sqs:us-east-1:111111111111:shop-production-emails')"], $e->params);
        self::assertSame(['DescribeStacks', 'GetQueueUrl', 'ListQueueTags'], $mocks->calls);
    }

    public function test_another_arco_resource_tag_keeps_the_retained_error(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::stackMissing(),
            new Result(['QueueUrl' => self::QUEUE_URL]),
            new Result(['Tags' => self::tags('queue:other')]),
        );

        self::expectError($mocks, self::adoptQueue(), 'deploy.queue_retained');
        self::assertNoCloudFormationWrite($mocks->calls);
    }

    public function test_unreadable_tags_keep_the_retained_error(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::stackMissing(),
            new Result(['QueueUrl' => self::QUEUE_URL]),
            AwsMocks::error(SqsException::class, 'ListQueueTags', 'AccessDenied', 403),
        );

        self::expectError($mocks, self::adoptQueue(), 'deploy.queue_retained');
        self::assertSame(['DescribeStacks', 'GetQueueUrl', 'ListQueueTags'], $mocks->calls);
    }

    public function test_dlq_must_also_match(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::stackMissing(),
            new Result(['QueueUrl' => self::QUEUE_URL]),
            new Result(['Tags' => self::tags('queue:emails')]),
            new Result(['QueueUrl' => self::DLQ_URL]),
            new Result(['Tags' => self::tags('queue:emails:dlq', 'staging')]),
        );

        $e = self::expectError($mocks, self::adoptQueue(), 'deploy.queue_dlq_retained');

        self::assertSame(['queue' => 'emails', 'dlq' => 'shop-production-emails-dlq'], $e->params);
        self::assertNoCloudFormationWrite($mocks->calls);
        self::assertSame(['DescribeStacks', 'GetQueueUrl', 'ListQueueTags', 'GetQueueUrl', 'ListQueueTags'], $mocks->calls);
    }

    public function test_bucket_of_another_account_is_taken(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(self::stackMissing(), AwsMocks::error(S3Exception::class, 'HeadBucket', 'Forbidden', 403));

        $e = self::expectError($mocks, self::config(['storage' => ['uploads' => ['adopt' => true]]]), 'deploy.bucket_taken');

        self::assertSame(['bucket' => self::BUCKET], $e->params);
        self::assertSame(['DescribeStacks', 'HeadBucket'], $mocks->calls);
    }

    public function test_an_untagged_bucket_keeps_the_retained_error(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(self::stackMissing(), new Result([]), AwsMocks::error(S3Exception::class, 'GetBucketTagging', 'NoSuchTagSet', 404));

        self::expectError($mocks, self::config(['storage' => ['uploads' => ['adopt' => true]]]), 'deploy.storage_retained');
        self::assertSame(['DescribeStacks', 'HeadBucket', 'GetBucketTagging'], $mocks->calls);
    }

    public function test_a_table_of_another_app_keeps_the_retained_error(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::stackMissing(),
            new Result(['Table' => ['TableName' => 'shop-production-state']]),
            new Result(['Table' => ['TableArn' => 'arn:aws:dynamodb:us-east-1:111111111111:table/shop-production-state']]),
            new Result(['Tags' => self::tagList(['arco:app' => 'blog', 'arco:environment' => 'production'])]),
        );

        self::expectError($mocks, self::config(['tables' => ['state' => ['adopt' => true]]]), 'deploy.generic_table_retained');
        self::assertNoCloudFormationWrite($mocks->calls);
    }

    public function test_check_adoptions_only_reads(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::stackMissing(),
            new Result(['QueueUrl' => self::QUEUE_URL]),
            new Result(['Tags' => self::tags('queue:emails')]),
            new Result(['QueueUrl' => self::DLQ_URL]),
            new Result(['Tags' => self::tags('queue:emails:dlq')]),
        );
        $deployer = new ChangeTrackingDeployer(new AwsDeployer($mocks->clients(), AwsMocks::noSleep()));

        $deployer->checkAdoptions(self::adoptQueue(), fn () => null);

        self::assertSame(['DescribeStacks', 'GetQueueUrl', 'ListQueueTags', 'GetQueueUrl', 'ListQueueTags'], $mocks->calls);
        self::assertFalse($deployer->changed());
    }

    public function test_check_adoptions_refuses_before_any_change(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::stackMissing(),
            new Result(['QueueUrl' => self::QUEUE_URL]),
            new Result(['Tags' => self::tags('queue:emails', 'staging')]),
        );
        $deployer = new ChangeTrackingDeployer(new AwsDeployer($mocks->clients(), AwsMocks::noSleep()));

        try {
            $deployer->checkAdoptions(self::adoptQueue(), fn () => null);
            self::fail('expected deploy.queue_retained');
        } catch (DeployException $e) {
            self::assertSame('deploy.queue_retained', $e->key);
        }
        self::assertFalse($deployer->changed());
    }

    public function test_an_unreadable_running_template_is_refused_by_the_check(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::stack(),
            new Result(['StackResources' => [
                ['LogicalResourceId' => 'QueueEmails', 'PhysicalResourceId' => self::QUEUE_URL, 'ResourceType' => 'AWS::SQS::Queue', 'ResourceStatus' => 'CREATE_COMPLETE'],
            ]]),
            new Result(['QueueUrl' => self::DLQ_URL]),
            new Result(['Tags' => self::tags('queue:emails:dlq')]),
            AwsMocks::error(CloudFormationException::class, 'GetTemplate', 'AccessDenied', 403),
        );
        $deployer = new ChangeTrackingDeployer(new AwsDeployer($mocks->clients(), AwsMocks::noSleep()));

        try {
            $deployer->checkAdoptions(self::adoptQueue(), fn () => null);
            self::fail('expected deploy.import_failed');
        } catch (DeployException $e) {
            self::assertSame('deploy.import_failed', $e->key);
            self::assertSame(['stack' => self::STACK, 'reason' => 'cloudformation:GetTemplate'], $e->params);
        }
        self::assertSame(['DescribeStacks', 'DescribeStackResources', 'GetQueueUrl', 'ListQueueTags', 'GetTemplate'], $mocks->calls);
        self::assertFalse($deployer->changed(), 'a refusal before any change');
    }

    public function test_check_adoptions_makes_no_call_without_adopt_entries(): void
    {
        $mocks = new AwsMocks();
        $config = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->queue('emails')->environment('production')->toArray(), 'production');

        (new AwsDeployer($mocks->clients(), AwsMocks::noSleep()))->checkAdoptions($config, fn () => null);

        self::assertSame([], $mocks->calls);
    }

    public function test_import_template_keeps_the_deployed_template_and_adds_literal_resources(): void
    {
        $config = self::config(['queues' => ['emails' => ['adopt' => true, 'fifo' => true]]]);
        $deployed = ['AWSTemplateFormatVersion' => '2010-09-09', 'Resources' => ['TableCache' => ['Type' => 'AWS::DynamoDB::Table']], 'Outputs' => ['X' => ['Value' => 'y']]];

        $template = ResourcesStack::importTemplate($config, $deployed, ['QueueEmails']);

        self::assertSame(['TableCache', 'QueueEmails'], array_keys($template['Resources']));
        self::assertSame(['Type' => 'AWS::DynamoDB::Table'], $template['Resources']['TableCache'], 'deployed resources stay exactly as deployed');
        self::assertSame(['Retain', 'Retain'], [$template['Resources']['QueueEmails']['DeletionPolicy'], $template['Resources']['QueueEmails']['UpdateReplacePolicy']]);
        self::assertSame($deployed['Outputs'], $template['Outputs']);
        self::assertSame(['QueueName' => 'shop-production-emails.fifo', 'FifoQueue' => true, 'Tags' => self::tagList(self::tags('queue:emails'))], $template['Resources']['QueueEmails']['Properties']);
    }
}
