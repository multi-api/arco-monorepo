<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy\Domains;

use Aws\CloudFormation\Exception\CloudFormationException;
use Aws\Result;
use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Config\Environment;
use Multiapi\Arco\Deploy\DeployException;
use Multiapi\Arco\Deploy\Domains\CloudflareDns;
use Multiapi\Arco\Deploy\Domains\ManualTenantTargetDns;
use Multiapi\Arco\Deploy\Domains\Stacks;
use Multiapi\Arco\Deploy\Domains\TenantDomains;
use Multiapi\Arco\Deploy\Domains\TenantTarget;
use Multiapi\Arco\Tests\Unit\Deploy\AwsMocks;
use PHPUnit\Framework\TestCase;

final class TenantDomainsTest extends TestCase
{
    private const STACK = 'arco-shop-production-tenants';
    private const ORIGIN = 'abc.xpt.lambda-web.us-east-1.on.aws';
    private const TARGET = 'routing.acmeapp.io';
    private const ENDPOINT = 'd111.cloudfront.net';
    private const TAGS = ['arco:app' => 'shop', 'arco:environment' => 'production'];
    private const INFRA = ['target' => self::TARGET, 'endpoint' => self::ENDPOINT, 'distributionId' => 'E123', 'connectionGroupId' => 'cg_1'];

    private AwsMocks $aws;
    /** @var list<string> everything that happened, in order: DNS operations, steps and the distribution callback */
    private array $log = [];
    /** @var array<string, DeployException> thrown by the fake DNS, by operation */
    private array $dnsFails = [];

    protected function setUp(): void
    {
        $this->aws = new AwsMocks();
    }

    protected function assertPostConditions(): void
    {
        self::assertCount(0, $this->aws->handler, 'every queued response is used');
    }

    /** @param callable(Environment): mixed|null $configure */
    private static function config(?callable $configure = null, string $target = self::TARGET): AppConfig
    {
        return ConfigResolver::resolve(App::make('shop')->region('us-east-1')->environment('production', function (Environment $e) use ($configure, $target): void {
            $e->domain('arco.acmeapp.io')->tenantDomains($target);
            if ($configure !== null) {
                $configure($e);
            }
        })->toArray(), 'production');
    }

    private function dns(): TenantTarget
    {
        return new class($this->log, $this->dnsFails) implements TenantTarget {
            /**
             * @param list<string> $log
             * @param array<string, DeployException> $fails
             */
            public function __construct(private array &$log, private array &$fails)
            {
            }

            public function check(string $target, ?string $ownedEndpoint): void
            {
                $this->log[] = "check $target " . ($ownedEndpoint ?? '-');
                if (isset($this->fails['check'])) {
                    throw $this->fails['check'];
                }
            }

            public function publish(string $target, string $endpoint, ?string $ownedEndpoint): void
            {
                $this->log[] = "publish $target $endpoint " . ($ownedEndpoint ?? '-');
                if (isset($this->fails['publish'])) {
                    throw $this->fails['publish'];
                }
            }

            public function release(string $target, string $endpoint, AppConfig $owner): string
            {
                $this->log[] = "release $target $endpoint";
                return self::DELETED;
            }
        };
    }

    private function tenants(bool $manual = false): TenantDomains
    {
        return new TenantDomains(new Stacks($this->aws->clients()->cloudFormation(), AwsMocks::noSleep(), 'domains.stack_'), $this->dns(), $manual);
    }

    /** @return array{target: string, endpoint: string, distributionId: string, connectionGroupId: string} */
    private function deploy(TenantDomains $tenants, ?AppConfig $config = null): array
    {
        return $tenants->deploy($config ?? self::config(), self::ORIGIN, ['/build/*'], self::TAGS,
            function (string $key, array $params): void { $this->log[] = $key; },
            function (string $distributionId): void { $this->log[] = "distribution $distributionId"; });
    }

    private static function missing(): CloudFormationException
    {
        return AwsMocks::error(CloudFormationException::class, 'DescribeStacks', 'ValidationError', 400, 'Stack with id ' . self::STACK . ' does not exist');
    }

    /**
     * @param array<string, string> $outputs replace or (with '') drop outputs
     * @param array<string, string> $tags
     */
    private static function stack(string $status = 'UPDATE_COMPLETE', array $outputs = [], array $tags = self::TAGS): Result
    {
        $outputs += ['DistributionId' => 'E123', 'ConnectionGroupId' => 'cg_1', 'RoutingEndpoint' => self::ENDPOINT, 'Target' => self::TARGET];
        return new Result(['Stacks' => [[
            'StackName' => self::STACK,
            'StackStatus' => $status,
            'Tags' => array_map(fn (string $k, string $v) => ['Key' => $k, 'Value' => $v], array_keys($tags), $tags),
            'Outputs' => array_values(array_filter(array_map(fn (string $k, string $v) => $v === '' ? null : ['OutputKey' => $k, 'OutputValue' => $v], array_keys($outputs), $outputs))),
        ]]]);
    }

    /** @return list<Result> the calls of an update that changes nothing */
    private static function unchanged(): array
    {
        return [self::stack(), new Result(['Id' => 'cs']), new Result(['Status' => 'FAILED', 'StatusReason' => "The submitted information didn't contain changes."]), new Result([])];
    }

    public function test_first_deploy_checks_the_target_creates_the_stack_protects_it_and_then_publishes_dns(): void
    {
        $this->aws->handler->append(self::missing(), self::missing(), self::missing(), new Result([]), self::stack('CREATE_COMPLETE'));

        $infra = $this->deploy($this->tenants());

        self::assertSame(self::INFRA, $infra);
        self::assertSame([
            'check routing.acmeapp.io -',
            'domains.step_tenant_distribution',
            'distribution E123',
            'domains.step_tenant_dns',
            'publish routing.acmeapp.io d111.cloudfront.net -',
        ], $this->log);
        self::assertSame(['DescribeStacks', 'DescribeStacks', 'DescribeStacks', 'CreateStack', 'DescribeStacks'], $this->aws->calls);
        $create = $this->aws->params[3];
        self::assertSame(self::STACK, $create['StackName']);
        self::assertSame([['Key' => 'arco:app', 'Value' => 'shop'], ['Key' => 'arco:environment', 'Value' => 'production']], $create['Tags']);
        $template = json_decode($create['TemplateBody'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(self::ORIGIN, $template['Resources']['Distribution']['Properties']['DistributionConfig']['Origins'][0]['DomainName']);
        self::assertSame(self::TARGET, $template['Outputs']['Target']['Value']);
        self::assertArrayHasKey('OriginAccess', $template['Resources']);
    }

    public function test_redeploy_updates_through_a_protected_change_set_and_only_trusts_its_own_endpoint(): void
    {
        $this->aws->handler->append(self::stack(), ...self::unchanged());

        self::assertSame(self::INFRA, $this->deploy($this->tenants()));

        self::assertSame(['DescribeStacks', 'DescribeStacks', 'CreateChangeSet', 'DescribeChangeSet', 'DeleteChangeSet'], $this->aws->calls);
        self::assertSame('UPDATE', $this->aws->params[2]['ChangeSetType']);
        self::assertContains('check routing.acmeapp.io d111.cloudfront.net', $this->log);
        self::assertContains('publish routing.acmeapp.io d111.cloudfront.net d111.cloudfront.net', $this->log);
    }

    public function test_an_update_that_would_replace_the_distribution_or_the_connection_group_never_runs(): void
    {
        $replace = ['Type' => 'Resource', 'ResourceChange' => ['LogicalResourceId' => 'ConnectionGroup', 'Action' => 'Modify', 'Replacement' => 'True']];
        $this->aws->handler->append(self::stack(), self::stack(), new Result(['Id' => 'cs']), new Result(['Status' => 'CREATE_COMPLETE', 'Changes' => [$replace]]), new Result([]));

        try {
            $this->deploy($this->tenants());
            self::fail('tenants hang off the connection group');
        } catch (DeployException $e) {
            self::assertSame('domains.stack_replacement', $e->key);
        }
        self::assertNotContains('ExecuteChangeSet', $this->aws->calls);
        self::assertSame(['check routing.acmeapp.io d111.cloudfront.net', 'domains.step_tenant_distribution'], $this->log, 'no access or DNS change after the refusal');
    }

    public function test_a_stack_of_another_environment_is_never_read_further_or_changed(): void
    {
        $this->aws->handler->append(self::stack(tags: ['arco:app' => 'shop', 'arco:environment' => 'staging']));

        try {
            $this->deploy($this->tenants());
            self::fail('the stack is not ours');
        } catch (DeployException $e) {
            self::assertSame('domains.stack_not_ours', $e->key);
        }
        self::assertSame([], $this->log, 'its endpoint is not trusted for DNS either');
        self::assertSame(['DescribeStacks'], $this->aws->calls);
    }

    public function test_changing_the_target_of_a_deployed_environment_is_refused_before_anything_changes(): void
    {
        $this->aws->handler->append(self::stack());

        try {
            $this->deploy($this->tenants(), self::config(target: 'shops.acmeapp.io'));
            self::fail('tenants point at the deployed target');
        } catch (DeployException $e) {
            self::assertSame('domains.tenant_target_changed', $e->key);
            self::assertSame(['target' => 'shops.acmeapp.io', 'current' => self::TARGET], $e->params);
        }
        self::assertSame([], $this->log);
        self::assertSame(['DescribeStacks'], $this->aws->calls);
    }

    public function test_a_stack_without_its_outputs_is_not_trusted_or_updated(): void
    {
        foreach ([
            [self::stack('CREATE_IN_PROGRESS', ['DistributionId' => '', 'ConnectionGroupId' => '', 'RoutingEndpoint' => '', 'Target' => '']), 'CREATE_IN_PROGRESS'],
            [self::stack('UPDATE_COMPLETE', ['RoutingEndpoint' => '']), 'UPDATE_COMPLETE'],
            [self::stack('DELETE_FAILED', ['Target' => '']), 'DELETE_FAILED'],
        ] as [$stack, $status]) {
            $this->aws->handler->append($stack);
            try {
                $this->deploy($this->tenants());
                self::fail('an incomplete stack must stop the deploy');
            } catch (DeployException $e) {
                self::assertSame('domains.tenant_stack_unusable', $e->key);
                self::assertSame(['stack' => self::STACK, 'status' => $status], $e->params);
            }
        }
        self::assertSame([], $this->log);
        self::assertSame(['DescribeStacks', 'DescribeStacks', 'DescribeStacks'], $this->aws->calls);
    }

    public function test_a_rolled_back_first_creation_holds_nothing_and_is_created_again(): void
    {
        $rolledBack = self::stack('ROLLBACK_COMPLETE', ['DistributionId' => '', 'ConnectionGroupId' => '', 'RoutingEndpoint' => '', 'Target' => '']);
        $this->aws->handler->append(
            $rolledBack, $rolledBack,
            $rolledBack, $rolledBack, new Result([]), self::missing(),   // deploy(): deletes the empty stack
            new Result([]), self::stack('CREATE_COMPLETE'),
        );

        self::assertSame(self::INFRA, $this->deploy($this->tenants()));
        self::assertSame('check routing.acmeapp.io -', $this->log[0], 'no endpoint is owned before the stack is complete');
        self::assertContains('DeleteStack', $this->aws->calls);
        self::assertContains('CreateStack', $this->aws->calls);
    }

    public function test_an_occupied_target_stops_the_deploy_before_the_stack_is_created(): void
    {
        $this->aws->handler->append(self::missing());
        $this->dnsFails['check'] = DeployException::make('domains.tenant_target_occupied', ['target' => self::TARGET]);

        try {
            $this->deploy($this->tenants());
            self::fail('the target is taken');
        } catch (DeployException $e) {
            self::assertSame('domains.tenant_target_occupied', $e->key);
        }
        self::assertSame(['DescribeStacks'], $this->aws->calls);
        self::assertSame(['check routing.acmeapp.io -'], $this->log);
    }

    public function test_an_update_that_moves_the_routing_endpoint_or_the_ids_stops_before_access_and_dns(): void
    {
        foreach ([['RoutingEndpoint' => 'd999.cloudfront.net'], ['DistributionId' => 'E999'], ['ConnectionGroupId' => 'cg_9']] as $moved) {
            $this->log = [];
            $this->aws->handler->append(
                self::stack(), self::stack(), new Result(['Id' => 'cs']),
                new Result(['Status' => 'CREATE_COMPLETE', 'Changes' => []]), new Result([]),
                new Result(['ExecutionStatus' => 'EXECUTE_COMPLETE']), self::stack('UPDATE_COMPLETE', $moved),
            );
            try {
                $this->deploy($this->tenants());
                self::fail('existing tenants depend on the previous values');
            } catch (DeployException $e) {
                self::assertSame('domains.tenant_target_change', $e->key);
            }
            self::assertSame(['check routing.acmeapp.io d111.cloudfront.net', 'domains.step_tenant_distribution'], $this->log);
        }
    }

    public function test_a_stack_without_an_output_after_the_deploy_is_reported(): void
    {
        $this->aws->handler->append(self::missing(), self::missing(), self::missing(), new Result([]), self::stack('CREATE_COMPLETE', ['ConnectionGroupId' => '']));

        try {
            $this->deploy($this->tenants());
            self::fail('the connection group id is needed');
        } catch (DeployException $e) {
            self::assertSame('domains.distribution_missing', $e->key);
            self::assertSame(['stack' => self::STACK, 'output' => 'ConnectionGroupId'], $e->params);
        }
    }

    public function test_manual_dns_prints_the_exact_record_and_a_missing_one_can_be_resumed(): void
    {
        $this->aws->handler->append(self::missing(), self::missing(), self::missing(), new Result([]), self::stack('CREATE_COMPLETE'));
        $this->dnsFails['publish'] = DeployException::make('domains.tenant_manual_cname_missing', ['target' => self::TARGET, 'endpoint' => self::ENDPOINT]);
        $steps = [];
        $step = function (string $key, array $params) use (&$steps): void { $steps[] = [$key, $params]; };

        try {
            $this->tenants(true)->deploy(self::config(), self::ORIGIN, [], self::TAGS, $step);
            self::fail('the record does not exist yet');
        } catch (DeployException $e) {
            self::assertSame('domains.tenant_manual_cname_missing', $e->key);
        }
        self::assertSame([
            ['domains.step_tenant_distribution', ['stack' => self::STACK]],
            ['domains.step_tenant_dns', ['target' => self::TARGET, 'endpoint' => self::ENDPOINT]],
            ['domains.manual_records', []],
            ['domains.manual_record', ['type' => 'CNAME', 'name' => self::TARGET, 'value' => self::ENDPOINT]],
        ], $steps);

        // The user creates the record and deploys again: the stack is kept, its endpoint is now the owned one.
        $this->dnsFails = [];
        $this->log = [];
        $this->aws->handler->append(self::stack('CREATE_COMPLETE'), ...self::unchanged());
        self::assertSame(self::INFRA, $this->tenants(true)->deploy(self::config(), self::ORIGIN, [], self::TAGS, $step));
        self::assertSame(['check routing.acmeapp.io d111.cloudfront.net', 'publish routing.acmeapp.io d111.cloudfront.net d111.cloudfront.net'], $this->log);
        self::assertNotContains('CreateStack', array_slice($this->aws->calls, 5));
    }

    public function test_preflight_only_reads(): void
    {
        $this->aws->handler->append(self::stack());

        $this->tenants()->preflight(self::config());

        self::assertSame(['DescribeStacks'], $this->aws->calls);
        self::assertSame(['check routing.acmeapp.io d111.cloudfront.net'], $this->log);
    }

    public function test_preflight_refuses_what_the_deploy_would_refuse(): void
    {
        $this->aws->handler->append(self::stack(tags: []), self::stack());

        foreach (['domains.stack_not_ours' => self::config(), 'domains.tenant_target_changed' => self::config(target: 'shops.acmeapp.io')] as $key => $config) {
            try {
                $this->tenants()->preflight($config);
                self::fail("$key expected");
            } catch (DeployException $e) {
                self::assertSame($key, $e->key);
            }
        }
        self::assertSame([], $this->log);
    }

    public function test_an_emulator_stack_has_no_origin_access_control(): void
    {
        $this->aws->handler->append(self::missing(), self::missing(), self::missing(), new Result([]), self::stack('CREATE_COMPLETE'));

        $this->tenants()->deploy(self::config(), self::ORIGIN, [], self::TAGS, fn () => null, null, false);

        $template = json_decode($this->aws->params[3]['TemplateBody'], true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('OriginAccess', $template['Resources']);
        self::assertArrayNotHasKey('OriginAccessControlId', $template['Resources']['Distribution']['Properties']['DistributionConfig']['Origins'][0]);
    }

    public function test_the_dns_mode_follows_the_configuration_and_never_falls_back(): void
    {
        $clients = $this->aws->clients();
        $dns = fn (AppConfig $config, ?CloudflareDns $provider = null) => (new \ReflectionProperty(TenantDomains::class, 'dns'))
            ->getValue(TenantDomains::create($config, $clients, null, AwsMocks::noSleep(), $provider));

        self::assertInstanceOf(\Multiapi\Arco\Deploy\Domains\TenantTargetDns::class, $dns(self::config()));
        self::assertInstanceOf(\Multiapi\Arco\Deploy\Domains\TenantTargetDns::class, $dns(self::config(fn (Environment $e) => $e->dns('common'))));
        self::assertInstanceOf(ManualTenantTargetDns::class, $dns(self::config(fn (Environment $e) => $e->dns(false))));
        $cloudflare = self::config(fn (Environment $e) => $e->dns('cloudflare'));
        self::assertInstanceOf(\Multiapi\Arco\Deploy\Domains\CloudflareTenantTargetDns::class, $dns($cloudflare, new CloudflareDns((new FakeCloudflare())->api())));

        // Cloudflare without its provider (no token): every operation fails, Route 53 is never asked.
        $unavailable = $dns($cloudflare);
        foreach ([fn () => $unavailable->check(self::TARGET, null), fn () => $unavailable->publish(self::TARGET, self::ENDPOINT, null)] as $operation) {
            try {
                $operation();
                self::fail('no token, no DNS');
            } catch (DeployException $e) {
                self::assertSame('cloudflare.token_missing', $e->key);
            }
        }
        self::assertSame([], $this->aws->calls);
    }
}
