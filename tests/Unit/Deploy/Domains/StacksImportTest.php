<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy\Domains;

use Aws\CloudFormation\Exception\CloudFormationException;
use Aws\Result;
use Multiapi\Arco\Deploy\DeployException;
use Multiapi\Arco\Deploy\Domains\Stacks;
use Multiapi\Arco\Tests\Unit\Deploy\AwsMocks;
use PHPUnit\Framework\TestCase;

final class StacksImportTest extends TestCase
{
    private const NAME = 'arco-shop-production-resources';
    private const URL = 'https://sqs.us-east-1.amazonaws.com/111111111111/shop-production-emails';

    private static function stack(string $status): Result
    {
        return new Result(['Stacks' => [['StackName' => self::NAME, 'StackStatus' => $status]]]);
    }

    private static function missing(): CloudFormationException
    {
        return AwsMocks::error(CloudFormationException::class, 'DescribeStacks', 'ValidationError', 400, 'Stack with id ' . self::NAME . ' does not exist');
    }

    /** @return list<array{ResourceType: string, LogicalResourceId: string, ResourceIdentifier: array<string, string>}> */
    private static function resources(): array
    {
        return [['ResourceType' => 'AWS::SQS::Queue', 'LogicalResourceId' => 'QueueEmails', 'ResourceIdentifier' => ['QueueUrl' => self::URL]]];
    }

    private function import(AwsMocks $mocks): void
    {
        (new Stacks($mocks->clients()->cloudFormation(), AwsMocks::noSleep()))->import(self::NAME, '{"Resources":{}}', self::resources(), ['arco:app' => 'shop', 'arco:environment' => 'production']);
    }

    public function test_import_creates_and_executes_an_import_change_set(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::stack('UPDATE_COMPLETE'),
            new Result(['Id' => 'cs']),                           // CreateChangeSet
            new Result(['Status' => 'CREATE_PENDING']),
            new Result(['Status' => 'CREATE_COMPLETE']),
            new Result([]),                                       // ExecuteChangeSet
            new Result(['Status' => 'CREATE_COMPLETE', 'ExecutionStatus' => 'EXECUTE_IN_PROGRESS']),
            new Result(['Status' => 'CREATE_COMPLETE', 'ExecutionStatus' => 'EXECUTE_COMPLETE']),
            self::stack('IMPORT_COMPLETE'),
        );

        $this->import($mocks);

        self::assertSame(['DescribeStacks', 'CreateChangeSet', 'DescribeChangeSet', 'DescribeChangeSet', 'ExecuteChangeSet', 'DescribeChangeSet', 'DescribeChangeSet', 'DescribeStacks'], $mocks->calls, 'the stack is read once the change set has run');
        $create = $mocks->params[1];
        self::assertSame(self::NAME, $create['StackName']);
        self::assertSame('IMPORT', $create['ChangeSetType']);
        self::assertSame('arco-import-1000000', $create['ChangeSetName']);
        self::assertSame('{"Resources":{}}', $create['TemplateBody']);
        self::assertSame(self::resources(), $create['ResourcesToImport']);
        self::assertSame([['Key' => 'arco:app', 'Value' => 'shop'], ['Key' => 'arco:environment', 'Value' => 'production']], $create['Tags']);
        foreach ([2, 4] as $i) {
            self::assertSame(['StackName' => self::NAME, 'ChangeSetName' => 'arco-import-1000000'], array_intersect_key($mocks->params[$i], ['StackName' => 1, 'ChangeSetName' => 1]));
        }
    }

    public function test_import_creates_a_new_stack(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::missing(),
            new Result(['Id' => 'cs']),
            new Result(['Status' => 'CREATE_COMPLETE']),
            new Result([]),
            new Result(['ExecutionStatus' => 'EXECUTE_COMPLETE']),
            self::stack('IMPORT_COMPLETE'),
        );

        $this->import($mocks);

        self::assertSame(['DescribeStacks', 'CreateChangeSet', 'DescribeChangeSet', 'ExecuteChangeSet', 'DescribeChangeSet', 'DescribeStacks'], $mocks->calls);
        self::assertSame('IMPORT', $mocks->params[1]['ChangeSetType']);
    }

    public function test_failed_change_set_is_deleted_and_reported(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::stack('UPDATE_COMPLETE'),
            new Result(['Id' => 'cs']),
            new Result(['Status' => 'FAILED', 'StatusReason' => 'Resource QueueEmails is not importable']),
            new Result([]),                                       // DeleteChangeSet
        );

        try {
            $this->import($mocks);
            self::fail('expected deploy.import_failed');
        } catch (DeployException $e) {
            self::assertSame('deploy.import_failed', $e->key);
            self::assertSame(['stack' => self::NAME, 'reason' => 'Resource QueueEmails is not importable'], $e->params);
        }
        self::assertSame(['DescribeStacks', 'CreateChangeSet', 'DescribeChangeSet', 'DeleteChangeSet'], $mocks->calls, 'nothing executed');
    }

    public function test_failed_change_set_of_a_new_stack_deletes_the_empty_stack(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::missing(),
            new Result(['Id' => 'cs']),
            new Result(['Status' => 'FAILED', 'StatusReason' => 'no']),
            new Result([]),                                       // DeleteChangeSet
            new Result([]),                                       // DeleteStack: the stack left in REVIEW_IN_PROGRESS
        );

        try {
            $this->import($mocks);
            self::fail('expected deploy.import_failed');
        } catch (DeployException $e) {
            self::assertSame('deploy.import_failed', $e->key);
        }
        self::assertSame(['DescribeStacks', 'CreateChangeSet', 'DescribeChangeSet', 'DeleteChangeSet', 'DeleteStack'], $mocks->calls);
    }

    public function test_import_rollback_is_reported(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::stack('UPDATE_COMPLETE'),
            new Result(['Id' => 'cs']),
            new Result(['Status' => 'CREATE_COMPLETE']),
            new Result([]),
            new Result(['ExecutionStatus' => 'EXECUTE_FAILED']),
            self::stack('IMPORT_ROLLBACK_COMPLETE'),
            new Result(['StackEvents' => [
                ['LogicalResourceId' => self::NAME, 'ResourceStatus' => 'IMPORT_ROLLBACK_COMPLETE'],
                ['LogicalResourceId' => 'QueueEmails', 'ResourceStatus' => 'IMPORT_FAILED', 'ResourceStatusReason' => 'tags differ'],
                ['LogicalResourceId' => self::NAME, 'ResourceStatus' => 'IMPORT_IN_PROGRESS'],
                ['LogicalResourceId' => 'QueueOld', 'ResourceStatus' => 'UPDATE_FAILED', 'ResourceStatusReason' => 'an older failure'],
            ]]),
        );

        try {
            $this->import($mocks);
            self::fail('expected deploy.import_failed');
        } catch (DeployException $e) {
            self::assertSame('deploy.import_failed', $e->key);
            self::assertSame(['stack' => self::NAME, 'reason' => 'QueueEmails: tags differ'], $e->params);
        }
    }

    public function test_a_stale_stack_status_after_execution_is_not_taken_for_the_result(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::stack('UPDATE_COMPLETE'),
            new Result(['Id' => 'cs']),
            new Result(['Status' => 'CREATE_COMPLETE']),
            new Result([]),                                       // ExecuteChangeSet
            AwsMocks::error(CloudFormationException::class, 'DescribeChangeSet', 'ChangeSetNotFound', 404),
            self::stack('UPDATE_COMPLETE'),                       // not started yet: the status from before
            self::stack('IMPORT_IN_PROGRESS'),
            self::stack('IMPORT_COMPLETE'),
        );

        $this->import($mocks);

        self::assertSame(['DescribeStacks', 'CreateChangeSet', 'DescribeChangeSet', 'ExecuteChangeSet', 'DescribeChangeSet', 'DescribeStacks', 'DescribeStacks', 'DescribeStacks'], $mocks->calls);
    }

    private static function stackAt(string $status, string $updated): Result
    {
        return new Result(['Stacks' => [['StackName' => self::NAME, 'StackStatus' => $status, 'LastUpdatedTime' => $updated]]]);
    }

    private static function changeSetGone(): CloudFormationException
    {
        return AwsMocks::error(CloudFormationException::class, 'DescribeChangeSet', 'ChangeSetNotFound', 404);
    }

    public function test_an_earlier_import_complete_is_not_taken_for_this_import(): void
    {
        // The stack ends an earlier adoption in IMPORT_COMPLETE; this import then fails and rolls back.
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::stackAt('IMPORT_COMPLETE', '2026-10-01T10:00:00Z'),
            new Result(['Id' => 'cs']),
            new Result(['Status' => 'CREATE_COMPLETE']),
            new Result([]),                                       // ExecuteChangeSet
            self::changeSetGone(),
            self::stackAt('IMPORT_COMPLETE', '2026-10-01T10:00:00Z'), // not started yet: the earlier import's status
            self::stackAt('IMPORT_ROLLBACK_COMPLETE', '2026-10-28T12:00:00Z'),
            new Result(['StackEvents' => [
                ['LogicalResourceId' => self::NAME, 'ResourceStatus' => 'IMPORT_ROLLBACK_COMPLETE'],
                ['LogicalResourceId' => 'QueueEmails', 'ResourceStatus' => 'IMPORT_FAILED', 'ResourceStatusReason' => 'tags differ'],
                ['LogicalResourceId' => self::NAME, 'ResourceStatus' => 'IMPORT_IN_PROGRESS'],
            ]]),
        );

        try {
            $this->import($mocks);
            self::fail('expected deploy.import_failed');
        } catch (DeployException $e) {
            self::assertSame('deploy.import_failed', $e->key);
            self::assertSame(['stack' => self::NAME, 'reason' => 'QueueEmails: tags differ'], $e->params);
        }
    }

    public function test_a_newer_import_complete_after_an_earlier_one_is_accepted(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::stackAt('IMPORT_COMPLETE', '2026-10-01T10:00:00Z'),
            new Result(['Id' => 'cs']),
            new Result(['Status' => 'CREATE_COMPLETE']),
            new Result([]),
            self::changeSetGone(),
            self::stackAt('IMPORT_COMPLETE', '2026-10-28T12:00:00Z'),
        );

        $this->import($mocks);

        self::assertSame(['DescribeStacks', 'CreateChangeSet', 'DescribeChangeSet', 'ExecuteChangeSet', 'DescribeChangeSet', 'DescribeStacks'], $mocks->calls);
    }

    public function test_a_change_set_gone_without_running_is_an_import_failure(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::stackAt('UPDATE_COMPLETE', '2026-10-01T10:00:00Z'),
            new Result(['Id' => 'cs']),
            new Result(['Status' => 'CREATE_COMPLETE']),
            new Result([]),
            self::changeSetGone(),
            self::stackAt('UPDATE_COMPLETE', '2026-10-01T10:00:00Z'),
            self::stackAt('UPDATE_COMPLETE', '2026-10-01T10:00:00Z'),
            self::stackAt('UPDATE_COMPLETE', '2026-10-01T10:00:00Z'),
        );

        try {
            (new Stacks($mocks->clients()->cloudFormation(), AwsMocks::noSleep()))->import(self::NAME, '{}', self::resources(), [], 30);
            self::fail('expected deploy.import_failed');
        } catch (DeployException $e) {
            self::assertSame('deploy.import_failed', $e->key);
            self::assertSame(self::NAME, $e->params['stack']);
            self::assertStringContainsString('change set', (string) $e->params['reason']);
        }
        self::assertSame(['DescribeStacks', 'CreateChangeSet', 'DescribeChangeSet', 'ExecuteChangeSet', 'DescribeChangeSet', 'DescribeStacks', 'DescribeStacks', 'DescribeStacks'], $mocks->calls);
    }

    public function test_a_change_set_that_never_gets_ready_is_cleaned_up(): void
    {
        foreach ([[self::stack('UPDATE_COMPLETE'), ['DeleteChangeSet']], [self::missing(), ['DeleteChangeSet', 'DeleteStack']]] as [$before, $cleanup]) {
            $mocks = new AwsMocks();
            $mocks->handler->append($before, new Result(['Id' => 'cs']));
            for ($i = 0; $i <= 2; $i++) {
                $mocks->handler->append(new Result(['Status' => 'CREATE_IN_PROGRESS']));
            }
            $mocks->handler->append(new Result([]), new Result([]));

            try {
                (new Stacks($mocks->clients()->cloudFormation(), AwsMocks::noSleep()))->import(self::NAME, '{}', self::resources(), [], 10);
                self::fail('expected deploy.timeout');
            } catch (DeployException $e) {
                self::assertSame('deploy.timeout', $e->key);
            }
            self::assertSame(['DescribeStacks', 'CreateChangeSet', 'DescribeChangeSet', 'DescribeChangeSet', 'DescribeChangeSet', ...$cleanup], $mocks->calls);
        }
    }

    public function test_an_empty_stack_left_in_review_is_deleted_before_the_import(): void
    {
        // An earlier IMPORT on a new stack that was interrupted: REVIEW_IN_PROGRESS never moves on by itself.
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::stack('REVIEW_IN_PROGRESS'),
            self::stack('REVIEW_IN_PROGRESS'),                    // Stacks::delete
            new Result([]),                                       // DeleteStack
            self::missing(),
            new Result(['Id' => 'cs']),
            new Result(['Status' => 'CREATE_COMPLETE']),
            new Result([]),
            new Result(['ExecutionStatus' => 'EXECUTE_COMPLETE']),
            self::stack('IMPORT_COMPLETE'),
        );

        $this->import($mocks);

        self::assertSame(['DescribeStacks', 'DescribeStacks', 'DeleteStack', 'DescribeStacks', 'CreateChangeSet', 'DescribeChangeSet', 'ExecuteChangeSet', 'DescribeChangeSet', 'DescribeStacks'], $mocks->calls, 'no wait on a status that never changes');
    }

    public function test_a_failed_import_after_a_review_stack_deletes_the_empty_stack_it_creates(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::stack('REVIEW_IN_PROGRESS'),
            self::stack('REVIEW_IN_PROGRESS'),
            new Result([]),                                       // DeleteStack
            self::missing(),
            new Result(['Id' => 'cs']),
            new Result(['Status' => 'FAILED', 'StatusReason' => 'no']),
            new Result([]),                                       // DeleteChangeSet
            new Result([]),                                       // DeleteStack
        );

        try {
            $this->import($mocks);
            self::fail('expected deploy.import_failed');
        } catch (DeployException $e) {
            self::assertSame('deploy.import_failed', $e->key);
        }
        self::assertSame(['DescribeStacks', 'DescribeStacks', 'DeleteStack', 'DescribeStacks', 'CreateChangeSet', 'DescribeChangeSet', 'DeleteChangeSet', 'DeleteStack'], $mocks->calls);
    }

    public function test_deploy_replaces_an_empty_stack_left_in_review(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::stack('REVIEW_IN_PROGRESS'),
            self::stack('REVIEW_IN_PROGRESS'),                    // Stacks::delete
            new Result([]),                                       // DeleteStack
            self::missing(),
            new Result([]),                                       // CreateStack
            new Result(['Stacks' => [['StackName' => self::NAME, 'StackStatus' => 'CREATE_COMPLETE', 'Outputs' => [['OutputKey' => 'Queue', 'OutputValue' => 'url']]]]]),
        );

        $outputs = (new Stacks($mocks->clients()->cloudFormation(), AwsMocks::noSleep()))->deploy(self::NAME, '{"Resources":{}}', ['arco:app' => 'shop']);

        self::assertSame(['Queue' => 'url'], $outputs);
        self::assertSame(['DescribeStacks', 'DescribeStacks', 'DeleteStack', 'DescribeStacks', 'CreateStack', 'DescribeStacks'], $mocks->calls);
    }

    public function test_a_stack_that_is_not_updatable_is_refused_before_the_change_set(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(self::stack('UPDATE_ROLLBACK_FAILED'));

        try {
            $this->import($mocks);
            self::fail('expected stack.not_updatable');
        } catch (DeployException $e) {
            self::assertSame('stack.not_updatable', $e->key);
        }
        self::assertSame(['DescribeStacks'], $mocks->calls);
    }
}
