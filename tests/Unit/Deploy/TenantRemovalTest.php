<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy;

use Aws\CloudFormation\Exception\CloudFormationException;
use Aws\CloudFront\Exception\CloudFrontException;
use Aws\CommandInterface;
use Aws\Iam\Exception\IamException;
use Aws\Lambda\Exception\LambdaException;
use Aws\Result;
use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Config\Environment;
use Multiapi\Arco\Deploy\DeployException;
use Multiapi\Arco\Deploy\Domains\TenantTarget;
use Multiapi\Arco\Deploy\Sleeper;
use Multiapi\Arco\Deploy\TenantRemoval;
use PHPUnit\Framework\TestCase;

/**
 * arco remove and the customer domains: the mediator is disabled first and stays disabled, every tenant is counted
 * on a fresh listing, tenants go only with an explicit force, and the distribution only goes when none is left.
 * A scripted account answers by operation name and keeps the state a remove changes.
 */
final class TenantRemovalTest extends TestCase
{
    private const FUNCTION = 'shop-production-tenants';
    private const ROLE = 'arco-shop-production-tenants';
    private const STACK = 'arco-shop-production-tenants';
    private const ENDPOINT = 'd222.cloudfront.net';
    private const TARGET = 'routing.example.com';
    private const WEB = 'arn:aws:lambda:us-east-1:111111111111:web-function:shop-production';
    private const OURS = ['arco:app' => 'shop', 'arco:environment' => 'production'];

    private AwsMocks $mocks;
    private Sleeper $sleeper;
    /** @var array<string, int|string|bool> what the account holds and what the remove changed */
    private array $account;
    /** @var array<string, array<string, mixed>> distribution tenants by id */
    private array $tenants = [];
    /** @var list<array<string, mixed>> the UpdateDistributionTenant and DeleteDistributionTenant arguments, in order */
    private array $writes = [];
    /** @var list<string> */
    private array $dnsCalls = [];
    /** @var list<string> */
    private array $revokes = [];
    /** @var list<array{string, array<string, string|int>}> */
    private array $steps = [];
    private string $dnsOutcome = TenantTarget::DELETED;
    private int $pageSize = 100;
    /** @var array<string, \Closure> operation overrides: closure(CommandInterface): mixed */
    private array $overrides = [];
    private int $settleReads = 2;
    /** real CloudFront answers GetDistributionTenant without Tags; true models the API reference's inline shape */
    private bool $inlineTags = false;

    private function config(): AppConfig
    {
        return ConfigResolver::resolve(App::make('shop')->region('us-east-1')->environment('production', fn (Environment $e) => $e->domain('shop.example.com')->tenantDomains(self::TARGET))->toArray(), 'production');
    }

    protected function setUp(): void
    {
        $this->mocks = new AwsMocks();
        $this->sleeper = AwsMocks::noSleep();
        $this->account = ['stack' => true, 'function' => true, 'role' => true, 'timeout' => 45, 'reserved' => -1, 'readback' => 0, 'stackStatus' => 'CREATE_COMPLETE'];
        $this->tenants = [];
        $this->writes = [];
        $this->dnsCalls = [];
        $this->revokes = [];
        $this->steps = [];
        $this->dnsOutcome = TenantTarget::DELETED;
        $this->pageSize = 100;
        $this->overrides = [];
        $this->settleReads = 2;
        $this->override = null;
        $this->inlineTags = false;
    }

    /** @param array<string, string> $tags */
    private function tenant(string $id, array $tags = self::OURS, bool $enabled = true, string $status = 'Deployed', string $group = 'cg_1', string $distribution = 'ETENANT'): void
    {
        $this->tenants[$id] = ['etag' => 1, 'enabled' => $enabled, 'inprogress' => $status === 'Deployed' ? 0 : 99, 'tags' => $tags, 'group' => $group, 'distribution' => $distribution, 'domains' => ["$id.customer.com"]];
    }

    /** @param array<string, string> $tags @return list<array{Key: string, Value: string}> */
    private function tagItems(array $tags): array
    {
        return array_map(fn (string $k, string $v) => ['Key' => $k, 'Value' => $v], array_keys($tags), array_values($tags));
    }

    /** @return array<string, mixed> */
    private function tenantBody(string $id): array
    {
        $t = $this->tenants[$id];
        return [
            'Id' => $id, 'Name' => $id, 'DistributionId' => $t['distribution'], 'ConnectionGroupId' => $t['group'],
            'Domains' => array_map(fn (string $d) => ['Domain' => $d, 'Status' => 'active'], $t['domains']),
            'Enabled' => $t['enabled'], 'Status' => $t['inprogress'] > 0 ? 'InProgress' : 'Deployed',
            'Arn' => 'arn:aws:cloudfront::111111111111:distribution-tenant/' . $id,
        ] + ($this->inlineTags ? ['Tags' => ['Items' => $this->tagItems($t['tags'])]] : []);
    }

    private static function error(string $class, string $operation, string $code, int $status = 400): \Throwable
    {
        return AwsMocks::error($class, $operation, $code, $status, $code === 'ValidationError' ? 'Stack with id ' . self::STACK . ' does not exist' : "$operation: $code");
    }

    /** @param array<string, string> $tags */
    private function dispatch(array $tags = self::OURS, array $stackTags = self::OURS, array $outputs = []): void
    {
        $tagList = fn (array $t) => array_map(fn (string $k, string $v) => ['Key' => $k, 'Value' => $v], array_keys($t), array_values($t));
        $outputs += ['DistributionId' => 'ETENANT', 'ConnectionGroupId' => 'cg_1', 'RoutingEndpoint' => self::ENDPOINT, 'Target' => self::TARGET];
        $ops = [
            'DescribeStacks' => fn () => $this->account['stack']
                ? new Result(['Stacks' => [['StackName' => self::STACK, 'StackStatus' => $this->account['stackStatus'], 'Tags' => $tagList($stackTags),
                    'Outputs' => array_values(array_filter(array_map(fn (string $k, string $v) => $v === '' ? null : ['OutputKey' => $k, 'OutputValue' => $v], array_keys($outputs), array_values($outputs))))]]])
                : self::error(CloudFormationException::class, 'DescribeStacks', 'ValidationError'),
            'DeleteStack' => function (): Result {
                $this->account['stack'] = false;
                return new Result([]);
            },
            'GetFunctionConfiguration' => fn () => $this->account['function']
                ? new Result(['FunctionName' => self::FUNCTION, 'FunctionArn' => 'arn:aws:lambda:us-east-1:111111111111:function:' . self::FUNCTION, 'Description' => 'Arco tenant domains mediator (Arco 0.25.0)', 'Timeout' => $this->account['timeout']])
                : self::error(LambdaException::class, 'GetFunctionConfiguration', 'ResourceNotFoundException', 404),
            'ListTags' => fn () => new Result(['Tags' => $tags]),
            'PutFunctionConcurrency' => function (CommandInterface $command): Result {
                $this->account['reserved'] = $command['ReservedConcurrentExecutions'];
                return new Result([]);
            },
            'GetFunctionConcurrency' => fn () => new Result(['ReservedConcurrentExecutions' => $this->account['readback'] === 0 ? $this->account['reserved'] : $this->account['readback']]),
            'DeleteFunction' => function (): Result {
                $this->account['function'] = false;
                return new Result([]);
            },
            'GetRole' => fn () => $this->account['role']
                ? new Result(['Role' => ['Arn' => 'arn:aws:iam::111111111111:role/' . self::ROLE, 'Tags' => $tagList($tags)]])
                : self::error(IamException::class, 'GetRole', 'NoSuchEntity', 404),
            'DeleteRolePolicy' => new Result([]),
            'DeleteRole' => function (): Result {
                $this->account['role'] = false;
                return new Result([]);
            },
            'ListDistributionTenants' => function (CommandInterface $command): Result {
                $ids = array_keys($this->tenants);
                $start = isset($command['Marker']) ? (int) $command['Marker'] : 0;
                $page = array_slice($ids, $start, $this->pageSize);
                $next = $start + $this->pageSize;
                return new Result(['DistributionTenantList' => array_map(fn (string $id) => ['Id' => $id, 'Name' => $id], $page)] + ($next < count($ids) ? ['NextMarker' => (string) $next] : []));
            },
            'GetDistributionTenant' => function (CommandInterface $command): Result {
                $id = (string) $command['Identifier'];
                if (! isset($this->tenants[$id])) {
                    throw self::error(CloudFrontException::class, 'GetDistributionTenant', 'EntityNotFound', 404);
                }
                $body = $this->tenantBody($id);
                $this->tenants[$id]['inprogress'] = max(0, $this->tenants[$id]['inprogress'] - 1);
                return new Result(['DistributionTenant' => $body, 'ETag' => 'E' . $this->tenants[$id]['etag']]);
            },
            'ListTagsForResource' => function (CommandInterface $command): Result {
                $id = substr((string) strrchr((string) $command['Resource'], '/'), 1);
                if (! isset($this->tenants[$id])) {
                    throw self::error(CloudFrontException::class, 'ListTagsForResource', 'EntityNotFound', 404);
                }
                return new Result(['Tags' => ['Items' => $this->tagItems($this->tenants[$id]['tags'])]]);
            },
            'UpdateDistributionTenant' => function (CommandInterface $command): Result {
                $this->writes[] = ['op' => 'update'] + $command->toArray();
                $id = (string) $command['Id'];
                if ('E' . $this->tenants[$id]['etag'] !== $command['IfMatch']) {
                    throw self::error(CloudFrontException::class, 'UpdateDistributionTenant', 'PreconditionFailed', 412);
                }
                $this->tenants[$id]['enabled'] = (bool) $command['Enabled'];
                $this->tenants[$id]['etag']++;
                $this->tenants[$id]['inprogress'] = $this->settleReads;
                return new Result([]);
            },
            'DeleteDistributionTenant' => function (CommandInterface $command): Result {
                $this->writes[] = ['op' => 'delete'] + $command->toArray();
                $id = (string) $command['Id'];
                if (! isset($this->tenants[$id])) {
                    throw self::error(CloudFrontException::class, 'DeleteDistributionTenant', 'EntityNotFound', 404);
                }
                if ('E' . $this->tenants[$id]['etag'] !== $command['IfMatch']) {
                    throw self::error(CloudFrontException::class, 'DeleteDistributionTenant', 'PreconditionFailed', 412);
                }
                if ($this->tenants[$id]['enabled'] || $this->tenants[$id]['inprogress'] > 0) {
                    throw self::error(CloudFrontException::class, 'DeleteDistributionTenant', 'ResourceNotDisabled', 409);
                }
                unset($this->tenants[$id]);
                return new Result([]);
            },
        ];
        $ops = $this->overrides + $ops;
        $handler = static function (CommandInterface $command) use (&$ops): mixed {
            $name = $command->getName();
            if (! array_key_exists($name, $ops)) {
                throw new \LogicException("unexpected call $name");
            }
            $answer = $ops[$name];
            return $answer instanceof \Closure ? $answer($command) : $answer;
        };
        for ($i = 0; $i < 400; $i++) {
            $this->mocks->handler->append($handler);
        }
    }

    private ?AppConfig $override = null;

    private function remove(bool $force = false, ?string $web = self::WEB): bool
    {
        $removal = new TenantRemoval(
            $this->mocks->clients(),
            $this->sleeper,
            function (string $target, string $endpoint) {
                $this->dnsCalls[] = "$target $endpoint";
                return $this->dnsOutcome;
            },
            function (string $arn): bool {
                $this->revokes[] = $arn;
                return true;
            },
        );
        return $removal->remove($this->override ?? $this->config(), $force, $web, function (string $key, array $params = []): void { $this->steps[] = [$key, $params]; });
    }

    /** @return list<string> the step keys */
    private function keys(): array
    {
        return array_column($this->steps, 0);
    }

    private function failsWith(\Closure $run, string $key): DeployException
    {
        try {
            $run();
        } catch (DeployException $e) {
            self::assertSame($key, $e->key);
            return $e;
        }
        self::fail("expected $key");
    }

    /** No change anywhere: only reads, and never a concurrency restored. */
    private function assertNothingChanged(): void
    {
        foreach (['PutFunctionConcurrency', 'DeleteFunctionConcurrency', 'UpdateDistributionTenant', 'DeleteDistributionTenant', 'DeleteStack', 'DeleteFunction', 'DeleteRole', 'DeleteRolePolicy'] as $write) {
            self::assertNotContains($write, $this->mocks->calls, $write);
        }
        self::assertSame([], $this->dnsCalls);
        self::assertSame([], $this->revokes);
    }

    // ---- detection ------------------------------------------------------------------------------------------------

    public function test_an_environment_without_tenant_resources_makes_three_reads_and_changes_nothing(): void
    {
        $this->account['stack'] = $this->account['function'] = $this->account['role'] = false;
        $this->dispatch();

        self::assertFalse($this->remove());

        self::assertSame(['DescribeStacks', 'GetFunctionConfiguration', 'GetRole'], $this->mocks->calls);
        self::assertSame([], $this->steps);
        $this->assertNothingChanged();
    }

    private static function denied(string $class, string $operation, string $code): \Closure
    {
        return fn () => AwsMocks::error($class, $operation, $code, 403, 'denied');
    }

    private function plain(): void
    {
        $this->override = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->environment('production')->toArray(), 'production');
    }

    public function test_a_plain_environment_whose_profile_cannot_read_the_mediator_function_or_role_has_no_tenant_resources(): void
    {
        $this->plain();
        $this->account['stack'] = false;
        $this->overrides['GetFunctionConfiguration'] = self::denied(LambdaException::class, 'GetFunctionConfiguration', 'AccessDeniedException');
        $this->overrides['GetRole'] = self::denied(IamException::class, 'GetRole', 'AccessDenied');
        $this->dispatch();

        self::assertFalse($this->remove());

        self::assertSame(['DescribeStacks', 'GetFunctionConfiguration', 'GetRole'], $this->mocks->calls);
        $this->assertNothingChanged();
    }

    public function test_a_plain_environment_with_the_stack_function_and_role_all_denied_has_no_tenant_resources(): void
    {
        $this->plain();
        $this->overrides['DescribeStacks'] = self::denied(CloudFormationException::class, 'DescribeStacks', 'AccessDenied');
        $this->overrides['GetFunctionConfiguration'] = self::denied(LambdaException::class, 'GetFunctionConfiguration', 'AccessDeniedException');
        $this->overrides['GetRole'] = self::denied(IamException::class, 'GetRole', 'AccessDenied');
        $this->dispatch();

        self::assertFalse($this->remove());

        $this->assertNothingChanged();
    }

    public function test_a_plain_environment_with_a_tenant_stack_must_be_able_to_read_the_function(): void
    {
        $this->plain();
        $this->overrides['GetFunctionConfiguration'] = self::denied(LambdaException::class, 'GetFunctionConfiguration', 'AccessDeniedException');
        $this->dispatch();

        try {
            $this->remove();
            self::fail('A denied read next to an existing stack stops the remove.');
        } catch (LambdaException $e) {
            self::assertSame('AccessDeniedException', $e->getAwsErrorCode());
        }
        $this->assertNothingChanged();
    }

    public function test_a_plain_environment_with_a_role_stops_when_the_function_cannot_be_read(): void
    {
        $this->plain();
        $this->account['stack'] = false;
        $this->overrides['GetFunctionConfiguration'] = self::denied(LambdaException::class, 'GetFunctionConfiguration', 'AccessDeniedException');
        $this->dispatch();

        $this->expectException(LambdaException::class);
        try {
            $this->remove();
        } finally {
            $this->assertNothingChanged();
        }
    }

    public function test_with_tenant_domains_in_arco_php_a_denied_function_or_role_read_stops(): void
    {
        foreach (['GetFunctionConfiguration' => [LambdaException::class, 'AccessDeniedException'], 'GetRole' => [IamException::class, 'AccessDenied']] as $operation => [$class, $code]) {
            $this->setUp();
            $this->account['stack'] = $this->account['function'] = $this->account['role'] = false;
            $this->overrides[$operation] = self::denied($class, $operation, $code);
            $this->dispatch();

            try {
                $this->remove();
                self::fail("a denied $operation must stop the remove");
            } catch (\Aws\Exception\AwsException $e) {
                self::assertSame($code, $e->getAwsErrorCode());
            }
            $this->assertNothingChanged();
        }
    }

    public function test_a_plain_environment_ignores_a_function_and_role_that_another_environment_owns_at_the_mediator_names(): void
    {
        foreach ([
            'another environment' => ['arco:app' => 'shop', 'arco:environment' => 'production-tenants'],
            'another app' => ['arco:app' => 'other', 'arco:environment' => 'production'],
            'no tags' => [],
        ] as $label => $tags) {
            $this->setUp();
            $this->plain();
            $this->account['stack'] = false;
            $this->dispatch($tags);

            self::assertFalse($this->remove(), $label);

            self::assertSame(['DescribeStacks', 'GetFunctionConfiguration', 'ListTags', 'GetRole'], $this->mocks->calls, $label);
            $this->assertNothingChanged();
        }
    }

    public function test_a_plain_environment_ignores_an_untagged_function_without_the_marker_and_unreadable_tags(): void
    {
        $this->plain();
        $this->account['stack'] = $this->account['role'] = false;
        $this->overrides['GetFunctionConfiguration'] = fn () => new Result(['FunctionName' => self::FUNCTION, 'FunctionArn' => 'arn:x', 'Description' => 'Someone else\'s function', 'Timeout' => 3]);
        $this->dispatch(self::OURS);
        self::assertFalse($this->remove(), 'this environment\'s tags but not the mediator\'s description');
        $this->assertNothingChanged();

        $this->setUp();
        $this->plain();
        $this->account['stack'] = $this->account['role'] = false;
        $this->overrides['ListTags'] = self::denied(LambdaException::class, 'ListTags', 'AccessDeniedException');
        $this->dispatch();
        self::assertFalse($this->remove(), 'unreadable tags');
        $this->assertNothingChanged();
    }

    public function test_a_plain_environment_still_handles_a_leftover_mediator_that_is_its_own(): void
    {
        $this->plain();
        $this->account['stack'] = false;
        $this->dispatch();

        self::assertTrue($this->remove());

        self::assertContains('PutFunctionConcurrency', $this->mocks->calls);
        self::assertSame([false, false], [$this->account['function'], $this->account['role']]);
    }

    public function test_with_tenant_domains_in_arco_php_a_foreign_function_still_stops(): void
    {
        $this->account['stack'] = false;
        $this->dispatch(['arco:app' => 'shop', 'arco:environment' => 'production-tenants']);

        $this->failsWith(fn () => $this->remove(), 'remove.not_owned');

        $this->assertNothingChanged();
    }

    public function test_a_plain_environment_ignores_a_stack_of_another_environment_at_the_tenant_stack_name(): void
    {
        $this->plain();
        $this->account['function'] = $this->account['role'] = false;
        $this->dispatch(stackTags: ['arco:app' => 'shop', 'arco:environment' => 'production-tenants']);

        self::assertFalse($this->remove());

        self::assertSame(['DescribeStacks', 'GetFunctionConfiguration', 'GetRole'], $this->mocks->calls);
        $this->assertNothingChanged();

        $this->setUp();
        $this->plain();
        $this->dispatch(['arco:app' => 'shop', 'arco:environment' => 'production-tenants'], ['arco:app' => 'shop', 'arco:environment' => 'production-tenants']);
        self::assertFalse($this->remove(), 'with a foreign function and role too');
        $this->assertNothingChanged();
    }

    public function test_a_plain_environment_handles_its_own_leftover_tenant_stack_normally(): void
    {
        $this->plain();
        $this->dispatch();

        self::assertTrue($this->remove());

        self::assertContains('DeleteStack', $this->mocks->calls);
        self::assertFalse($this->account['stack']);
    }

    public function test_with_tenant_domains_in_arco_php_a_foreign_stack_still_stops(): void
    {
        $this->dispatch(stackTags: ['arco:app' => 'shop', 'arco:environment' => 'production-tenants']);

        $this->failsWith(fn () => $this->remove(), 'domains.stack_not_ours');

        $this->assertNothingChanged();
    }

    public function test_a_non_denial_error_on_the_mediator_reads_always_propagates(): void
    {
        $this->plain();
        $this->account['stack'] = false;
        $this->overrides['GetRole'] = self::denied(IamException::class, 'GetRole', 'ServiceFailure');
        $this->dispatch();

        $this->expectException(IamException::class);
        $this->remove();
    }

    public function test_with_tenant_domains_in_arco_php_a_refused_stack_read_stops_even_without_a_function_or_role(): void
    {
        $this->account['function'] = $this->account['role'] = false;
        $this->overrides['DescribeStacks'] = self::denied(CloudFormationException::class, 'DescribeStacks', 'AccessDenied');
        $this->dispatch();

        $this->failsWith(fn () => $this->remove(), 'remove.tenant_stack_unreadable');

        $this->assertNothingChanged();
    }

    public function test_a_refused_stack_read_with_a_function_stops_before_any_change(): void
    {
        $this->overrides['DescribeStacks'] = fn () => self::error(CloudFormationException::class, 'DescribeStacks', 'AccessDenied', 403);
        $this->dispatch();

        $e = $this->failsWith(fn () => $this->remove(), 'remove.tenant_stack_unreadable');

        self::assertSame(self::STACK, $e->params['stack']);
        $this->assertNothingChanged();
    }

    public function test_a_stack_of_another_environment_stops_before_any_change(): void
    {
        $this->dispatch(stackTags: ['arco:app' => 'shop', 'arco:environment' => 'staging']);

        $this->failsWith(fn () => $this->remove(), 'domains.stack_not_ours');

        $this->assertNothingChanged();
    }

    public function test_a_stack_without_tags_is_not_ours(): void
    {
        $this->dispatch(stackTags: []);

        $this->failsWith(fn () => $this->remove(), 'domains.stack_not_ours');

        $this->assertNothingChanged();
    }

    public function test_a_mediator_function_of_another_environment_stops_before_any_change(): void
    {
        $this->dispatch(['arco:app' => 'shop', 'arco:environment' => 'staging']);

        $e = $this->failsWith(fn () => $this->remove(), 'remove.not_owned');

        self::assertSame(self::FUNCTION, $e->params['resource']);
        $this->assertNothingChanged();
    }

    public function test_an_untagged_mediator_function_stops_before_any_change(): void
    {
        $this->dispatch([]);

        $this->failsWith(fn () => $this->remove(), 'remove.tenant_untagged');

        $this->assertNothingChanged();
    }

    public function test_a_mediator_function_whose_tags_cannot_be_read_stops_before_any_change(): void
    {
        $this->overrides['ListTags'] = fn () => self::error(LambdaException::class, 'ListTags', 'AccessDeniedException', 403);
        $this->dispatch();

        $e = $this->failsWith(fn () => $this->remove(), 'remove.tags_unreadable');

        self::assertSame('lambda:ListTags', $e->params['action']);
        $this->assertNothingChanged();
    }

    public function test_a_mediator_role_of_another_environment_or_without_tags_stops_before_any_change(): void
    {
        // The function is gone: only the role is looked at.
        $this->account['function'] = false;
        $this->dispatch(['arco:app' => 'other', 'arco:environment' => 'production']);
        $e = $this->failsWith(fn () => $this->remove(), 'remove.not_owned');
        self::assertSame(self::ROLE, $e->params['resource']);
        $this->assertNothingChanged();

        $this->setUp();
        $this->account['function'] = false;
        $this->dispatch([]);
        $this->failsWith(fn () => $this->remove(), 'remove.tenant_untagged');
        $this->assertNothingChanged();
    }

    public function test_a_stack_without_the_outputs_arco_writes_stops_before_the_drain(): void
    {
        $this->dispatch(outputs: ['DistributionId' => '']);

        $this->failsWith(fn () => $this->remove(), 'domains.tenant_stack_unusable');

        $this->assertNothingChanged();
    }

    // ---- the drain ------------------------------------------------------------------------------------------------

    public function test_the_mediator_is_disabled_and_confirmed_before_anything_is_counted_and_waited_for_its_own_timeout_plus_the_margin(): void
    {
        $this->dispatch();

        self::assertTrue($this->remove());

        $calls = $this->mocks->calls;
        $put = array_search('PutFunctionConcurrency', $calls, true);
        $read = array_search('GetFunctionConcurrency', $calls, true);
        $count = array_search('ListDistributionTenants', $calls, true);
        self::assertIsInt($put);
        self::assertTrue($put < $read && $read < $count, 'confirmed before the first listing');
        self::assertSame(0, $this->mocks->params[$put]['ReservedConcurrentExecutions']);
        self::assertSame(self::FUNCTION, $this->mocks->params[$put]['FunctionName']);
        self::assertSame(45 + TenantRemoval::PROPAGATION_MARGIN, $this->sleeper->slept, 'the function\'s configured timeout (45), not the constant, plus the margin');
        self::assertSame(['remove.step_tenant_drain', ['function' => self::FUNCTION, 'environment' => 'production']], $this->steps[0]);
        self::assertSame(['remove.step_tenant_wait', ['function' => self::FUNCTION, 'seconds' => 105]], $this->steps[1]);
        self::assertNotContains('DeleteFunctionConcurrency', $calls);
    }

    public function test_a_read_back_that_is_not_zero_stops_before_any_count(): void
    {
        $this->account['readback'] = 5;
        $this->dispatch();

        $e = $this->failsWith(fn () => $this->remove(), 'remove.tenant_drain_unconfirmed');

        self::assertSame('5', $e->params['value']);
        self::assertNotContains('ListDistributionTenants', $this->mocks->calls);
        self::assertSame(0, $this->sleeper->slept);
        self::assertContains('remove.tenant_stopped', $this->keys());
        self::assertNotContains('DeleteFunctionConcurrency', $this->mocks->calls);
        self::assertNotContains('DeleteStack', $this->mocks->calls);
    }

    public function test_a_refused_concurrency_change_stops_before_any_count(): void
    {
        $this->overrides['PutFunctionConcurrency'] = fn () => self::error(LambdaException::class, 'PutFunctionConcurrency', 'AccessDeniedException', 403);
        $this->dispatch();

        $this->failsWith(fn () => $this->remove(), 'remove.tenant_drain_failed');

        self::assertNotContains('ListDistributionTenants', $this->mocks->calls);
        $this->assertNothingChangedButTheAttempt();
    }

    private function assertNothingChangedButTheAttempt(): void
    {
        foreach (['DeleteFunctionConcurrency', 'UpdateDistributionTenant', 'DeleteDistributionTenant', 'DeleteStack', 'DeleteFunction', 'DeleteRole'] as $write) {
            self::assertNotContains($write, $this->mocks->calls, $write);
        }
    }

    // ---- no tenants -----------------------------------------------------------------------------------------------

    public function test_without_tenants_the_infrastructure_goes_in_order_and_no_force_is_needed(): void
    {
        $this->dispatch();

        self::assertTrue($this->remove(force: false));

        self::assertSame([
            'remove.step_tenant_drain', 'remove.step_tenant_wait', 'remove.step_tenant_count', 'remove.step_tenant_dns', 'remove.step_tenant_access',
            'remove.step_tenant_stack', 'remove.step_tenant_function', 'remove.step_tenant_role',
        ], $this->keys());
        self::assertSame(['remove.step_tenant_count', ['count' => 0]], $this->steps[2]);
        self::assertSame([self::TARGET . ' ' . self::ENDPOINT], $this->dnsCalls);
        self::assertSame([self::WEB], $this->revokes);
        self::assertSame(['DeleteStack', 'DeleteFunction', 'DeleteRolePolicy', 'DeleteRole'], array_values(array_filter($this->mocks->calls, fn (string $c) => str_starts_with($c, 'Delete'))));
        self::assertSame([false, false, false], [$this->account['stack'], $this->account['function'], $this->account['role']]);
        self::assertNotContains('DeleteFunctionConcurrency', $this->mocks->calls);
    }

    public function test_the_dns_record_is_left_alone_unless_it_is_ours_and_a_manual_target_is_printed(): void
    {
        $this->dnsOutcome = TenantTarget::KEPT;
        $this->dispatch();
        $this->remove();
        self::assertContains('remove.tenant_dns_kept', $this->keys());
        self::assertSame(['target' => self::TARGET, 'endpoint' => self::ENDPOINT], $this->steps[array_search('remove.tenant_dns_kept', $this->keys(), true)][1]);
        self::assertContains('DeleteStack', $this->mocks->calls, 'a record that is not ours does not stop the remove');

        $this->setUp();
        $this->dnsOutcome = TenantTarget::MANUAL;
        $this->dispatch();
        $this->remove();
        self::assertContains('remove.tenant_dns_manual', $this->keys());
        self::assertContains('remove.tenant_dns_manual', \Multiapi\Arco\Cli\StepPrinter::WARNINGS);
        self::assertContains('remove.tenant_dns_kept', \Multiapi\Arco\Cli\StepPrinter::WARNINGS);
        self::assertContains('remove.tenant_stopped', \Multiapi\Arco\Cli\StepPrinter::WARNINGS);
    }

    public function test_without_a_web_function_nothing_is_revoked(): void
    {
        $this->dispatch();

        $this->remove(web: null);

        self::assertSame([], $this->revokes);
        self::assertNotContains('remove.step_tenant_access', $this->keys());
    }

    // ---- tenants, without and with force --------------------------------------------------------------------------

    public function test_tenants_without_force_stop_with_the_count_and_delete_nothing_and_never_restore_the_concurrency(): void
    {
        $this->tenant('dt_1');
        $this->tenant('dt_2', enabled: false);
        $this->dispatch();

        $e = $this->failsWith(fn () => $this->remove(force: false), 'remove.tenants_need_force');

        self::assertSame(['count' => 2, 'function' => self::FUNCTION, 'environment' => 'production'], $e->params, 'the params remove --json carries as error.params');
        $this->assertNothingChangedButTheAttempt();
        self::assertSame(0, $this->account['reserved'], 'left at 0');
        self::assertContains('remove.step_tenant_drain', $this->keys());
        self::assertContains('remove.tenant_stopped', $this->keys());
        self::assertNotContains('remove.step_tenant_count', $this->keys());
        self::assertSame([], $this->dnsCalls);
        self::assertSame([], $this->revokes);
        self::assertSame(['dt_1', 'dt_2'], array_keys($this->tenants));
    }

    public function test_force_disables_each_tenant_with_its_etag_and_deletes_it_with_the_fresh_one_once_cloudfront_accepts(): void
    {
        $this->tenant('dt_1');
        $this->tenant('dt_2', enabled: false);
        $this->dispatch();

        self::assertTrue($this->remove(force: true));

        self::assertSame([], $this->tenants);
        $updates = array_values(array_filter($this->writes, fn (array $w) => $w['op'] === 'update'));
        self::assertCount(1, $updates, 'the disabled tenant is not updated again');
        self::assertSame(['Id' => 'dt_1', 'IfMatch' => 'E1', 'Enabled' => false, 'DistributionId' => 'ETENANT', 'ConnectionGroupId' => 'cg_1', 'Domains' => [['Domain' => 'dt_1.customer.com']]], array_diff_key($updates[0], ['op' => 1, '@http' => 1, '@context' => 1]));
        $deletes = array_values(array_filter($this->writes, fn (array $w) => $w['op'] === 'delete'));
        $accepted = array_values(array_filter($deletes, fn (array $w) => $w['Id'] === 'dt_1' && $w['IfMatch'] === 'E2'));
        self::assertNotSame([], $accepted, 'deleted with the ETag the update produced');
        self::assertGreaterThan(1, count(array_filter($deletes, fn (array $w) => $w['Id'] === 'dt_1')), 'ResourceNotDisabled was retried');
        self::assertContains('remove.step_tenant_delete', $this->keys());
        self::assertSame(['remove.step_tenant_count', ['count' => 2]], $this->steps[array_search('remove.step_tenant_count', $this->keys(), true)]);
        // The recount is the last CloudFront read before the infrastructure goes.
        $lastList = max(array_keys(array_filter($this->mocks->calls, fn (string $c) => $c === 'ListDistributionTenants')));
        self::assertLessThan(array_search('DeleteStack', $this->mocks->calls, true), $lastList);
        self::assertContains('DeleteStack', $this->mocks->calls);
        self::assertNotContains('DeleteFunctionConcurrency', $this->mocks->calls);
    }

    public function test_the_deletes_all_come_before_the_stack_function_and_role(): void
    {
        $this->tenant('dt_1');
        $this->dispatch();

        $this->remove(force: true);

        $calls = $this->mocks->calls;
        $lastTenantDelete = max(array_keys(array_filter($calls, fn (string $c) => $c === 'DeleteDistributionTenant')));
        self::assertLessThan(array_search('DeleteStack', $calls, true), $lastTenantDelete);
        self::assertLessThan(array_search('DeleteFunction', $calls, true), array_search('DeleteStack', $calls, true));
        self::assertLessThan(array_search('DeleteRole', $calls, true), array_search('DeleteFunction', $calls, true));
    }

    public function test_a_tenant_that_is_not_the_environments_stops_even_with_force_and_nothing_is_deleted(): void
    {
        foreach ([
            'foreign tags' => ['arco:app' => 'other', 'arco:environment' => 'production'],
            'no tags' => [],
            'one tag' => ['arco:app' => 'shop'],
        ] as $label => $tags) {
            $this->setUp();
            $this->tenant('dt_1');
            $this->tenant('dt_9', $tags);
            $this->dispatch();

            $e = $this->failsWith(fn () => $this->remove(force: true), 'remove.tenant_foreign');

            self::assertSame('dt_9', $e->params['tenant'], $label);
            $this->assertNothingChangedButTheAttempt();
            self::assertSame(['dt_1', 'dt_9'], array_keys($this->tenants));
        }
    }

    public function test_a_tenant_of_another_connection_group_or_distribution_is_not_ours(): void
    {
        foreach ([['group' => 'cg_other'], ['distribution' => 'EOTHER']] as $other) {
            $this->setUp();
            $this->tenant('dt_9', group: $other['group'] ?? 'cg_1', distribution: $other['distribution'] ?? 'ETENANT');
            $this->dispatch();

            $this->failsWith(fn () => $this->remove(force: true), 'remove.tenant_foreign');

            $this->assertNothingChangedButTheAttempt();
        }
    }

    public function test_tags_that_cannot_be_read_stop_the_remove(): void
    {
        $this->tenant('dt_1');
        $this->overrides['GetDistributionTenant'] = fn () => self::error(CloudFrontException::class, 'GetDistributionTenant', 'AccessDenied', 403);
        $this->dispatch();

        $e = $this->failsWith(fn () => $this->remove(force: true), 'remove.tenant_unverified');

        self::assertSame('dt_1', $e->params['tenant']);
        self::assertStringContainsString('AccessDenied', (string) $e->params['error']);
        $this->assertNothingChangedButTheAttempt();
    }

    public function test_ownership_is_read_with_list_tags_for_resource_on_the_tenants_arn_since_cloudfront_returns_no_tags(): void
    {
        $this->tenant('dt_1', enabled: false);
        $this->dispatch();

        self::assertTrue($this->remove(force: true));

        $tagReads = array_keys(array_filter($this->mocks->calls, fn (string $c) => $c === 'ListTagsForResource'));
        self::assertNotSame([], $tagReads);
        self::assertSame('arn:aws:cloudfront::111111111111:distribution-tenant/dt_1', $this->mocks->params[$tagReads[0]]['Resource']);
        self::assertSame([], $this->tenants);
    }

    public function test_the_inline_tag_shape_is_still_supported(): void
    {
        $this->inlineTags = true;
        $this->tenant('dt_1', enabled: false);
        $this->dispatch();

        self::assertTrue($this->remove(force: true));

        self::assertNotContains('ListTagsForResource', $this->mocks->calls);
    }

    public function test_an_empty_tag_list_is_a_foreign_tenant(): void
    {
        $this->tenant('dt_1', []);
        $this->dispatch();

        $this->failsWith(fn () => $this->remove(force: true), 'remove.tenant_foreign');

        $this->assertNothingChangedButTheAttempt();
    }

    public function test_tags_that_cannot_be_read_delete_nothing_even_with_force(): void
    {
        $this->tenant('dt_1');
        $this->overrides['ListTagsForResource'] = fn () => self::error(CloudFrontException::class, 'ListTagsForResource', 'AccessDenied', 403);
        $this->dispatch();

        $e = $this->failsWith(fn () => $this->remove(force: true), 'remove.tenant_unverified');

        self::assertStringContainsString('ListTagsForResource: AccessDenied', (string) $e->params['error']);
        self::assertSame([], $this->writes);
    }

    public function test_an_answer_without_a_tag_list_is_unverified(): void
    {
        $this->tenant('dt_1');
        $this->overrides['ListTagsForResource'] = fn () => new Result([]);
        $this->dispatch();

        $this->failsWith(fn () => $this->remove(force: true), 'remove.tenant_unverified');

        self::assertSame([], $this->writes);
    }

    public function test_an_arn_that_is_missing_or_not_the_tenants_is_never_guessed_and_nothing_is_read_with_it(): void
    {
        foreach ([null, 'arn:aws:cloudfront::111111111111:distribution-tenant/dt_other', 'arn:aws:cloudfront::111111111111:distribution/dt_1'] as $arn) {
            $this->setUp();
            $this->tenant('dt_1');
            $this->overrides['GetDistributionTenant'] = function (CommandInterface $c) use ($arn): Result {
                $body = $this->tenantBody((string) $c['Identifier']);
                unset($body['Arn']);
                return new Result(['DistributionTenant' => $body + ($arn === null ? [] : ['Arn' => $arn]), 'ETag' => 'E1']);
            };
            $this->dispatch();

            $this->failsWith(fn () => $this->remove(force: true), 'remove.tenant_unverified');

            self::assertNotContains('ListTagsForResource', $this->mocks->calls);
            self::assertSame([], $this->writes);
        }
    }

    public function test_the_tags_are_read_fresh_before_every_write(): void
    {
        $this->tenant('dt_1');
        $this->dispatch();

        $this->remove(force: true);

        // Each CloudFront write is directly preceded by a tag read of that tenant.
        foreach ($this->mocks->calls as $i => $call) {
            if (in_array($call, ['UpdateDistributionTenant', 'DeleteDistributionTenant'], true)) {
                self::assertSame('ListTagsForResource', $this->mocks->calls[$i - 1], "$call follows a tag read");
            }
        }
    }

    public function test_a_tenant_without_an_etag_or_state_is_never_changed(): void
    {
        $this->tenant('dt_1');
        $this->overrides['GetDistributionTenant'] = fn (CommandInterface $c) => new Result(['DistributionTenant' => $this->tenantBody((string) $c['Identifier'])]);
        $this->dispatch();

        $this->failsWith(fn () => $this->remove(force: true), 'remove.tenant_unverified');

        self::assertSame([], $this->writes);
    }

    public function test_a_tenant_that_stops_being_ours_before_its_deletion_is_not_deleted(): void
    {
        $this->tenant('dt_1');
        $reads = 0;
        $this->overrides['GetDistributionTenant'] = function (CommandInterface $command) use (&$reads): Result {
            $reads++;
            if ($reads >= 3) {
                // After the count and the first read of the delete loop, once the tenant is disabled: someone retags it.
                $this->tenants['dt_1']['tags'] = ['arco:app' => 'other', 'arco:environment' => 'production'];
            }
            $id = (string) $command['Identifier'];
            return new Result(['DistributionTenant' => $this->tenantBody($id), 'ETag' => 'E' . $this->tenants[$id]['etag']]);
        };
        $this->dispatch();

        $this->failsWith(fn () => $this->remove(force: true), 'remove.tenant_foreign');

        self::assertSame([], array_values(array_filter($this->writes, fn (array $w) => $w['op'] === 'delete')));
    }

    public function test_a_tenant_with_an_operation_in_progress_is_waited_for(): void
    {
        $this->tenant('dt_1', status: 'InProgress');
        $this->tenants['dt_1']['inprogress'] = 2;
        $this->dispatch();

        self::assertTrue($this->remove(force: true));

        self::assertContains('remove.step_tenant_settle', $this->keys());
        self::assertGreaterThan(45 + TenantRemoval::PROPAGATION_MARGIN, $this->sleeper->slept);
        self::assertSame([], $this->tenants);
    }

    public function test_an_operation_that_never_ends_stops_the_remove(): void
    {
        $this->tenant('dt_1', status: 'InProgress');
        $this->overrides['GetDistributionTenant'] = function (CommandInterface $command): Result {
            $this->tenants['dt_1']['inprogress'] = 99;
            return new Result(['DistributionTenant' => $this->tenantBody('dt_1'), 'ETag' => 'E1']);
        };
        $this->dispatch();

        $e = $this->failsWith(fn () => $this->remove(force: true), 'remove.tenant_busy');

        self::assertSame(1, $e->params['count']);
        self::assertGreaterThanOrEqual(TenantRemoval::SETTLE_TIMEOUT, $this->sleeper->slept - 45 - TenantRemoval::PROPAGATION_MARGIN);
        $this->assertNothingChangedButTheAttempt();
    }

    public function test_a_tenant_that_never_becomes_deletable_times_out_and_stops(): void
    {
        $this->tenant('dt_1');
        $this->settleReads = 9999;
        $this->dispatch();

        $e = $this->failsWith(fn () => $this->remove(force: true), 'remove.tenant_delete_timeout');

        self::assertSame('dt_1', $e->params['tenant']);
        self::assertNotContains('DeleteStack', $this->mocks->calls);
        self::assertContains('remove.tenant_stopped', $this->keys());
    }

    public function test_a_tenant_added_after_the_deletions_stops_the_remove_before_the_stack(): void
    {
        $this->tenant('dt_1', enabled: false);
        $this->overrides['DeleteDistributionTenant'] = function (CommandInterface $command): Result {
            $this->writes[] = ['op' => 'delete'] + $command->toArray();
            unset($this->tenants['dt_1']);
            $this->tenant('dt_new');
            return new Result([]);
        };
        $this->dispatch();

        $e = $this->failsWith(fn () => $this->remove(force: true), 'remove.tenant_recount');

        self::assertSame(1, $e->params['count']);
        self::assertNotContains('DeleteStack', $this->mocks->calls);
        self::assertSame([], $this->dnsCalls);
        self::assertSame(['dt_new'], array_keys($this->tenants));
    }

    public function test_a_tenant_just_deleted_that_is_still_listed_for_a_moment_is_waited_for(): void
    {
        $this->tenant('dt_1', enabled: false);
        $lists = 0;
        $this->overrides['ListDistributionTenants'] = function () use (&$lists): Result {
            $lists++;
            $ids = array_keys($this->tenants);
            if ($lists === 2) {
                // The recount still shows the tenant that was just deleted, once.
                $this->tenant('dt_1', enabled: false);
                $ids = ['dt_1'];
            } elseif ($lists > 2) {
                unset($this->tenants['dt_1']);
                $ids = [];
            }
            return new Result(['DistributionTenantList' => array_map(fn (string $id) => ['Id' => $id], $ids)]);
        };
        $this->dispatch();

        self::assertTrue($this->remove(force: true));

        self::assertContains('DeleteStack', $this->mocks->calls);
        self::assertSame(3, $lists);
    }

    public function test_an_aws_error_while_deleting_is_wrapped_and_stops(): void
    {
        $this->tenant('dt_1');
        $this->overrides['DeleteDistributionTenant'] = fn () => self::error(CloudFrontException::class, 'DeleteDistributionTenant', 'AccessDenied', 403);
        $this->dispatch();

        $e = $this->failsWith(fn () => $this->remove(force: true), 'remove.tenant_failed');

        self::assertSame(self::FUNCTION, $e->params['function']);
        self::assertStringContainsString('DeleteDistributionTenant: AccessDenied', (string) $e->params['error']);
        self::assertNotContains('DeleteStack', $this->mocks->calls);
        self::assertContains('remove.tenant_stopped', $this->keys());
    }

    // ---- the listing ----------------------------------------------------------------------------------------------

    public function test_every_page_is_counted(): void
    {
        foreach (range(1, 5) as $n) {
            $this->tenant("dt_$n", enabled: false);
        }
        $this->pageSize = 2;
        $this->dispatch();

        $this->remove(force: true);

        self::assertSame(['remove.step_tenant_count', ['count' => 5]], $this->steps[array_search('remove.step_tenant_count', $this->keys(), true)]);
        self::assertSame([], $this->tenants);
    }

    public function test_a_repeated_marker_a_malformed_list_or_a_malformed_marker_stop_the_remove(): void
    {
        $cases = [
            'repeated marker' => fn () => new Result(['DistributionTenantList' => [['Id' => 'dt_1']], 'NextMarker' => 'same']),
            'missing list' => fn () => new Result([]),
            'list is not a list' => fn () => new Result(['DistributionTenantList' => 'x']),
            'summary without an id' => fn () => new Result(['DistributionTenantList' => [['Name' => 'x']]]),
            'marker is not a string' => fn () => new Result(['DistributionTenantList' => [], 'NextMarker' => 7]),
        ];
        foreach ($cases as $label => $answer) {
            $this->setUp();
            $this->tenant('dt_1', enabled: false);
            $this->overrides['ListDistributionTenants'] = $answer;
            $this->dispatch();

            $this->failsWith(fn () => $this->remove(force: true), 'remove.tenant_listing_incomplete');

            $this->assertNothingChangedButTheAttempt();
        }
    }

    public function test_a_refused_listing_stops_the_remove(): void
    {
        $this->overrides['ListDistributionTenants'] = fn () => self::error(CloudFrontException::class, 'ListDistributionTenants', 'AccessDenied', 403);
        $this->dispatch();

        $e = $this->failsWith(fn () => $this->remove(force: true), 'remove.tenant_listing_incomplete');

        self::assertStringContainsString('AccessDenied', (string) $e->params['error']);
        $this->assertNothingChangedButTheAttempt();
    }

    public function test_the_listing_asks_for_the_environments_distribution(): void
    {
        $this->dispatch();

        $this->remove();

        $list = array_search('ListDistributionTenants', $this->mocks->calls, true);
        self::assertSame(['DistributionId' => 'ETENANT'], $this->mocks->params[$list]['AssociationFilter']);
    }

    // ---- resuming -------------------------------------------------------------------------------------------------

    public function test_a_remove_resumed_after_the_function_is_gone_skips_the_drain(): void
    {
        $this->account['function'] = false;
        $this->dispatch();

        self::assertTrue($this->remove());

        self::assertNotContains('PutFunctionConcurrency', $this->mocks->calls);
        self::assertSame(0, $this->sleeper->slept);
        self::assertNotContains('remove.step_tenant_function', $this->keys());
        self::assertContains('remove.step_tenant_stack', $this->keys());
        self::assertContains('remove.step_tenant_role', $this->keys());
    }

    public function test_a_remove_resumed_with_only_the_role_left_deletes_only_the_role(): void
    {
        $this->account['stack'] = $this->account['function'] = false;
        $this->dispatch();

        self::assertTrue($this->remove());

        self::assertSame(['remove.step_tenant_role'], $this->keys());
        self::assertSame(['DeleteRolePolicy', 'DeleteRole'], array_values(array_filter($this->mocks->calls, fn (string $c) => str_starts_with($c, 'Delete'))));
        self::assertSame([], $this->dnsCalls);
        self::assertSame([], $this->revokes);
    }

    public function test_a_stack_that_rolled_back_its_creation_holds_no_tenants(): void
    {
        $this->account['stackStatus'] = 'ROLLBACK_COMPLETE';
        $this->dispatch(outputs: ['DistributionId' => '']);

        self::assertTrue($this->remove());

        self::assertNotContains('ListDistributionTenants', $this->mocks->calls);
        self::assertSame([], $this->dnsCalls);
        self::assertContains('DeleteStack', $this->mocks->calls);
    }

    public function test_a_failed_infrastructure_step_names_how_to_resume_and_keeps_the_concurrency(): void
    {
        $this->overrides['DeleteStack'] = fn () => self::error(CloudFormationException::class, 'DeleteStack', 'ValidationError');
        $this->dispatch();

        $this->failsWith(fn () => $this->remove(), 'remove.tenant_failed');

        self::assertContains('remove.tenant_stopped', $this->keys());
        self::assertSame(0, $this->account['reserved']);
        self::assertNotContains('DeleteFunction', $this->mocks->calls);
        self::assertNotContains('DeleteFunctionConcurrency', $this->mocks->calls);
    }
}
