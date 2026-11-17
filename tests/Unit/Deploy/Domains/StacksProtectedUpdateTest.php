<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy\Domains;

use Aws\CloudFormation\Exception\CloudFormationException;
use Aws\Result;
use Multiapi\Arco\Deploy\DeployException;
use Multiapi\Arco\Deploy\Domains\Stacks;
use Multiapi\Arco\Tests\Unit\Deploy\AwsMocks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StacksProtectedUpdateTest extends TestCase
{
    private const NAME = 'arco-shop-production-tenants';
    private const SET = ['StackName' => self::NAME, 'ChangeSetName' => 'arco-update-1000000'];

    private static function stack(string $status, string $updated = '2026-11-01T00:00:00Z'): Result
    {
        return new Result(['Stacks' => [[
            'StackName' => self::NAME, 'StackStatus' => $status, 'LastUpdatedTime' => $updated,
            'Outputs' => [['OutputKey' => 'DistributionId', 'OutputValue' => 'E123']],
        ]]]);
    }

    /** @return array<string, mixed> */
    private static function change(string $logicalId, string $action = 'Modify', ?string $replacement = 'False'): array
    {
        $change = ['LogicalResourceId' => $logicalId, 'Action' => $action];
        if ($replacement !== null) {
            $change['Replacement'] = $replacement;
        }
        return ['Type' => 'Resource', 'ResourceChange' => $change];
    }

    /** @return array<string, string> */
    private function deploy(AwsMocks $mocks): array
    {
        return (new Stacks($mocks->clients()->cloudFormation(), AwsMocks::noSleep(), 'domains.stack_'))
            ->deployProtected(self::NAME, '{"Resources":{}}', ['arco:app' => 'shop'], ['Distribution', 'ConnectionGroup']);
    }

    public function test_a_new_stack_is_created_without_a_change_set(): void
    {
        $mocks = new AwsMocks();
        $missing = AwsMocks::error(CloudFormationException::class, 'DescribeStacks', 'ValidationError', 400, 'Stack with id ' . self::NAME . ' does not exist');
        $mocks->handler->append($missing, $missing, new Result([]), self::stack('CREATE_COMPLETE'));

        self::assertSame(['DistributionId' => 'E123'], $this->deploy($mocks));
        self::assertSame(['DescribeStacks', 'DescribeStacks', 'CreateStack', 'DescribeStacks'], $mocks->calls);
    }

    public function test_an_update_that_keeps_the_protected_resources_runs_through_a_change_set(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::stack('UPDATE_COMPLETE'),
            new Result(['Id' => 'cs']),
            new Result(['Status' => 'CREATE_IN_PROGRESS']),
            new Result(['Status' => 'CREATE_COMPLETE', 'Changes' => [self::change('Distribution'), self::change('ForwardHost', 'Modify', 'True'), self::change('NewPolicy', 'Add', null)]]),
            new Result([]),
            new Result(['ExecutionStatus' => 'EXECUTE_COMPLETE']),
            self::stack('UPDATE_COMPLETE', '2026-11-17T00:00:00Z'),
        );

        self::assertSame(['DistributionId' => 'E123'], $this->deploy($mocks));
        self::assertSame(['DescribeStacks', 'CreateChangeSet', 'DescribeChangeSet', 'DescribeChangeSet', 'ExecuteChangeSet', 'DescribeChangeSet', 'DescribeStacks'], $mocks->calls);
        $create = $mocks->params[1];
        self::assertSame('UPDATE', $create['ChangeSetType']);
        self::assertSame('{"Resources":{}}', $create['TemplateBody']);
        self::assertSame([['Key' => 'arco:app', 'Value' => 'shop']], $create['Tags']);
        self::assertSame(self::SET, array_intersect_key($mocks->params[4], self::SET));
    }

    public function test_the_stack_is_read_after_the_change_set_has_run_even_when_it_was_removed(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::stack('UPDATE_COMPLETE'),
            new Result(['Id' => 'cs']),
            new Result(['Status' => 'CREATE_COMPLETE', 'Changes' => [self::change('Distribution')]]),
            new Result([]),
            AwsMocks::error(CloudFormationException::class, 'DescribeChangeSet', 'ChangeSetNotFound', 404),
            self::stack('UPDATE_COMPLETE'),                              // still as before: not the result yet
            self::stack('UPDATE_IN_PROGRESS', '2026-11-17T00:00:00Z'),
            self::stack('UPDATE_COMPLETE', '2026-11-17T00:00:00Z'),
        );

        self::assertSame(['DistributionId' => 'E123'], $this->deploy($mocks));
        self::assertSame(3, count(array_keys($mocks->calls, 'DescribeStacks', true)) - 1);
    }

    /** @return iterable<string, array{list<array<string, mixed>>, string}> */
    public static function dangerousChanges(): iterable
    {
        yield 'the distribution is replaced' => [[self::change('ForwardHost'), self::change('Distribution', 'Modify', 'True')], 'Distribution'];
        yield 'a replacement that depends on values not known yet' => [[self::change('ConnectionGroup', 'Modify', 'Conditional')], 'ConnectionGroup'];
        yield 'the connection group is removed' => [[self::change('ConnectionGroup', 'Remove', null)], 'ConnectionGroup'];
        yield 'both' => [[self::change('Distribution', 'Remove', null), self::change('ConnectionGroup', 'Modify', 'True')], 'Distribution, ConnectionGroup'];
        yield 'a modification without a replacement answer' => [[self::change('Distribution', 'Modify', null)], 'Distribution'];
        yield 'an action Arco does not know' => [[self::change('Distribution', 'Dynamic', 'False')], 'Distribution'];
    }

    /** @param list<array<string, mixed>> $changes */
    #[DataProvider('dangerousChanges')]
    public function test_an_update_that_would_replace_or_remove_a_protected_resource_is_never_executed(array $changes, string $resources): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::stack('UPDATE_COMPLETE'),
            new Result(['Id' => 'cs']),
            new Result(['Status' => 'CREATE_COMPLETE', 'Changes' => $changes]),
            new Result([]),
        );

        try {
            $this->deploy($mocks);
            self::fail('the update must be refused');
        } catch (DeployException $e) {
            self::assertSame('domains.stack_replacement', $e->key);
            self::assertSame(['stack' => self::NAME, 'resources' => $resources], $e->params);
        }
        self::assertSame(['DescribeStacks', 'CreateChangeSet', 'DescribeChangeSet', 'DeleteChangeSet'], $mocks->calls);
        self::assertSame(self::SET, array_intersect_key($mocks->params[3], self::SET));
    }

    public function test_every_page_of_changes_is_read_before_the_update_runs(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::stack('UPDATE_COMPLETE'),
            new Result(['Id' => 'cs']),
            new Result(['Status' => 'CREATE_COMPLETE', 'Changes' => [self::change('ForwardHost')], 'NextToken' => 't1']),
            new Result(['Status' => 'CREATE_COMPLETE', 'Changes' => [self::change('Distribution', 'Modify', 'True')]]),
            new Result([]),
        );

        try {
            $this->deploy($mocks);
            self::fail('the replacement is on the second page');
        } catch (DeployException $e) {
            self::assertSame('domains.stack_replacement', $e->key);
        }
        self::assertSame('t1', $mocks->params[3]['NextToken']);
        self::assertNotContains('ExecuteChangeSet', $mocks->calls);
    }

    /** @return iterable<string, array{list<Result>}> */
    public static function unreadableChanges(): iterable
    {
        yield 'no list of changes' => [[new Result(['Status' => 'CREATE_COMPLETE'])]];
        yield 'a change that is not a map' => [[new Result(['Status' => 'CREATE_COMPLETE', 'Changes' => ['x']])]];
        yield 'a change without its resource' => [[new Result(['Status' => 'CREATE_COMPLETE', 'Changes' => [['Type' => 'Resource']]])]];
        yield 'a change without an action' => [[new Result(['Status' => 'CREATE_COMPLETE', 'Changes' => [['Type' => 'Resource', 'ResourceChange' => ['LogicalResourceId' => 'Distribution']]]])]];
        yield 'an empty cursor' => [[new Result(['Status' => 'CREATE_COMPLETE', 'Changes' => [], 'NextToken' => ''])]];
        yield 'a repeated cursor' => [[
            new Result(['Status' => 'CREATE_COMPLETE', 'Changes' => [], 'NextToken' => 't1']),
            new Result(['Status' => 'CREATE_COMPLETE', 'Changes' => [], 'NextToken' => 't1']),
        ]];
    }

    /** @param list<Result> $pages */
    #[DataProvider('unreadableChanges')]
    public function test_changes_that_cannot_be_read_completely_stop_the_update(array $pages): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(self::stack('UPDATE_COMPLETE'), new Result(['Id' => 'cs']), ...$pages, ...[new Result([])]);

        try {
            $this->deploy($mocks);
            self::fail('the update must be refused');
        } catch (DeployException $e) {
            self::assertSame('domains.stack_changes_unreadable', $e->key);
            self::assertSame(['stack' => self::NAME], $e->params);
        }
        self::assertSame('DeleteChangeSet', $mocks->calls[count($mocks->calls) - 1]);
        self::assertNotContains('ExecuteChangeSet', $mocks->calls);
    }

    public function test_a_change_set_without_changes_is_discarded_and_the_stack_left_alone(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::stack('UPDATE_COMPLETE'),
            new Result(['Id' => 'cs']),
            new Result(['Status' => 'FAILED', 'StatusReason' => "The submitted information didn't contain changes. Submit different information to create a change set."]),
            new Result([]),
        );

        self::assertSame(['DistributionId' => 'E123'], $this->deploy($mocks));
        self::assertSame(['DescribeStacks', 'CreateChangeSet', 'DescribeChangeSet', 'DeleteChangeSet'], $mocks->calls);
    }

    public function test_a_change_set_that_fails_is_discarded_and_reported(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::stack('UPDATE_COMPLETE'),
            new Result(['Id' => 'cs']),
            new Result(['Status' => 'FAILED', 'StatusReason' => 'Template error: bad reference']),
            new Result([]),
        );

        try {
            $this->deploy($mocks);
            self::fail('the failure must be reported');
        } catch (DeployException $e) {
            self::assertSame('domains.stack_failed', $e->key);
            self::assertSame('Template error: bad reference', $e->params['reason']);
        }
        self::assertSame('DeleteChangeSet', $mocks->calls[3]);
    }

    public function test_a_rolled_back_update_is_reported_with_its_cause(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::stack('UPDATE_COMPLETE'),
            new Result(['Id' => 'cs']),
            new Result(['Status' => 'CREATE_COMPLETE', 'Changes' => [self::change('Distribution')]]),
            new Result([]),
            new Result(['ExecutionStatus' => 'EXECUTE_FAILED']),
            self::stack('UPDATE_ROLLBACK_COMPLETE', '2026-11-17T00:00:00Z'),
            new Result(['StackEvents' => [
                ['LogicalResourceId' => 'Distribution', 'ResourceStatus' => 'UPDATE_FAILED', 'ResourceStatusReason' => 'Invalid origin'],
                ['LogicalResourceId' => self::NAME, 'ResourceStatus' => 'UPDATE_IN_PROGRESS'],
            ]]),
        );

        try {
            $this->deploy($mocks);
            self::fail('the failure must be reported');
        } catch (DeployException $e) {
            self::assertSame('domains.stack_failed', $e->key);
            self::assertSame('UPDATE_ROLLBACK_COMPLETE', $e->params['status']);
            self::assertSame('Distribution: Invalid origin', $e->params['reason']);
        }
    }

    public function test_a_stack_that_cannot_be_updated_is_left_alone(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(self::stack('UPDATE_ROLLBACK_FAILED'));

        try {
            $this->deploy($mocks);
            self::fail('the stack cannot be updated');
        } catch (DeployException $e) {
            self::assertSame('domains.stack_not_updatable', $e->key);
        }
        self::assertSame(['DescribeStacks'], $mocks->calls);
    }
}
