<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy;

use Aws\Iam\Exception\IamException;
use Aws\LambdaCore\Exception\LambdaCoreException;
use Aws\Result;
use Multiapi\Arco\Cli\Translator;
use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Deploy\DeployException;
use Multiapi\Arco\Deploy\ExecutionRole;
use Multiapi\Arco\Deploy\NetworkConnectors;
use PHPUnit\Framework\TestCase;

final class NetworkConnectorsTest extends TestCase
{
    private const ROLE = 'arn:aws:iam::111111111111:role/arco-shop-production-network';
    private const CONNECTOR = 'arn:aws:lambda:us-east-1:111111111111:network-connector:shop-production';
    private const BOUNDARY = 'arn:aws:iam::111111111111:policy/arco-boundary';

    private function config(?string $boundary = null): AppConfig
    {
        $app = App::make('shop')->region('us-east-1');
        if ($boundary !== null) {
            $app = $app->permissionsBoundary($boundary);
        }
        return ConfigResolver::resolve($app->environment('production', fn ($e) => $e->vpc(subnets: ['subnet-a', 'subnet-b'], securityGroups: ['sg-1']))->toArray(), 'production');
    }

    /** @return array<string, mixed> */
    private static function connector(array $extra = []): array
    {
        return [...[
            'Arn' => self::CONNECTOR,
            'Name' => 'shop-production',
            'Id' => 'nc-1',
            'OperatorRole' => self::ROLE,
            'State' => 'ACTIVE',
            'LatestVersionArn' => self::CONNECTOR . ':3',
            'LatestVersion' => 3,
            'Configuration' => ['VpcEgressConfiguration' => [
                'SubnetIds' => ['subnet-b', 'subnet-a'],
                'SecurityGroupIds' => ['sg-1'],
                'NetworkProtocol' => 'IPv4',
                'AssociatedComputeResourceTypes' => ['WebFunction'],
            ]],
        ], ...$extra];
    }

    private function subject(AwsMocks $mocks, ?\Multiapi\Arco\Deploy\Sleeper $sleeper = null): NetworkConnectors
    {
        $aws = $mocks->clients();
        return new NetworkConnectors($aws->lambdaCore(), new ExecutionRole($aws->iam()), '111111111111', $sleeper ?? AwsMocks::noSleep());
    }

    private static function notFound(string $operation): LambdaCoreException
    {
        return AwsMocks::error(LambdaCoreException::class, $operation, 'ResourceNotFoundException', 404);
    }

    /** @return \ArrayObject<int, array{string, array<string, string|int>}> the steps the code under test reports */
    private static function steps(?callable &$step): \ArrayObject
    {
        /** @var \ArrayObject<int, array{string, array<string, string|int>}> $steps */
        $steps = new \ArrayObject();
        $step = function (string $key, array $params = []) use ($steps): void {
            $steps[] = [$key, $params];
        };
        return $steps;
    }

    public function test_operator_role_name_is_derived_and_shortened(): void
    {
        self::assertSame('arco-shop-production-network', NetworkConnectors::operatorRoleName($this->config()));
        $long = ConfigResolver::resolve(App::make(str_repeat('a', 40))->region('us-east-1')->environment(str_repeat('b', 23))->toArray(), str_repeat('b', 23));
        $name = NetworkConnectors::operatorRoleName($long);
        self::assertSame(64, strlen($name));
        self::assertStringEndsWith('-network', $name, 'the suffix survives, so it never equals the execution role');
        self::assertNotSame(ExecutionRole::roleName($long), $name);
    }

    public function test_operator_policy_scopes_interface_creation_to_the_service(): void
    {
        $policy = NetworkConnectors::operatorPolicy();
        $conditioned = $policy['Statement'][0];
        self::assertSame(['ec2:CreateNetworkInterface', 'ec2:CreateTags'], $conditioned['Action']);
        self::assertSame(['StringEquals' => ['ec2:ManagedResourceOperator' => 'network-connectors.lambda.amazonaws.com']], $conditioned['Condition']);
        $actions = array_merge(...array_map(fn (array $s) => (array) $s['Action'], $policy['Statement']));
        foreach (['ec2:DeleteNetworkInterface', 'ec2:DescribeNetworkInterfaces', 'ec2:DescribeSubnets', 'ec2:DescribeSecurityGroups'] as $action) {
            self::assertContains($action, $actions);
        }
    }

    public function test_creates_role_then_connector_and_waits_for_active(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            AwsMocks::error(IamException::class, 'GetRole', 'NoSuchEntity', 404),
            new Result(['Role' => ['Arn' => self::ROLE]]),
            new Result([]),
            self::notFound('GetNetworkConnector'),
            new Result(['Arn' => self::CONNECTOR, 'Name' => 'shop-production', 'Id' => 'nc-1', 'VersionArn' => self::CONNECTOR . ':1', 'State' => 'PENDING']),
            new Result(self::connector(['State' => 'PENDING'])),
            new Result(self::connector(['LatestVersionArn' => self::CONNECTOR . ':1'])),
        );
        $sleeper = AwsMocks::noSleep();
        $steps = self::steps($step);

        $version = $this->subject($mocks, $sleeper)->ensure($this->config(), ['arco:app' => 'shop'], $step);

        self::assertSame(self::CONNECTOR . ':1', $version);
        self::assertSame(['GetRole', 'CreateRole', 'PutRolePolicy', 'GetNetworkConnector', 'CreateNetworkConnector', 'GetNetworkConnector', 'GetNetworkConnector'], $mocks->calls);
        $create = $mocks->params[4];
        self::assertSame('shop-production', $create['Name']);
        self::assertSame(self::ROLE, $create['OperatorRole']);
        self::assertSame(['arco:app' => 'shop'], $create['Tags']);
        self::assertSame([
            'SubnetIds' => ['subnet-a', 'subnet-b'],
            'SecurityGroupIds' => ['sg-1'],
            'NetworkProtocol' => 'IPv4',
            'AssociatedComputeResourceTypes' => ['WebFunction'],
        ], $create['Configuration']['VpcEgressConfiguration']);
        self::assertSame('shop-production', $mocks->params[3]['Identifier']);
        self::assertGreaterThan(0, $sleeper->slept);
        $keys = array_column($steps->getArrayCopy(), 0);
        self::assertSame(['deploy.step_network', 'deploy.network_waiting'], $keys);
        self::assertSame(['connector' => 'shop-production', 'subnets' => 'subnet-a, subnet-b', 'groups' => 'sg-1'], $steps[0][1]);
    }

    public function test_the_operator_role_gets_the_boundary(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            AwsMocks::error(IamException::class, 'GetRole', 'NoSuchEntity', 404),
            new Result(['Role' => ['Arn' => self::ROLE]]),
            new Result([]),
            new Result(self::connector()),
        );
        self::steps($step);

        $this->subject($mocks)->ensure($this->config(self::BOUNDARY), [], $step);

        self::assertSame(self::BOUNDARY, $mocks->params[1]['PermissionsBoundary']);
    }

    public function test_unchanged_connector_makes_no_update(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['Role' => ['Arn' => self::ROLE]]),
            new Result([]),
            new Result(self::connector()),
        );
        self::steps($step);

        $version = $this->subject($mocks)->ensure($this->config(), [], $step);

        self::assertSame(self::CONNECTOR . ':3', $version);
        self::assertNotContains('UpdateNetworkConnector', $mocks->calls);
        self::assertNotContains('CreateNetworkConnector', $mocks->calls);
    }

    public function test_changed_config_updates_and_returns_new_version(): void
    {
        $changed = self::connector(['Configuration' => ['VpcEgressConfiguration' => [
            'SubnetIds' => ['subnet-a'], 'SecurityGroupIds' => ['sg-1'], 'NetworkProtocol' => 'IPv4', 'AssociatedComputeResourceTypes' => ['WebFunction'],
        ]]]);
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['Role' => ['Arn' => self::ROLE]]),
            new Result([]),
            new Result($changed),
            new Result(['Arn' => self::CONNECTOR, 'Name' => 'shop-production', 'Id' => 'nc-1', 'VersionArn' => self::CONNECTOR . ':4', 'LastUpdateStatus' => 'InProgress']),
            new Result(self::connector(['LastUpdateStatus' => 'InProgress'])),
            new Result(self::connector(['LastUpdateStatus' => 'Successful', 'LatestVersionArn' => self::CONNECTOR . ':4', 'LatestVersion' => 4])),
        );
        $steps = self::steps($step);

        $version = $this->subject($mocks)->ensure($this->config(), [], $step);

        self::assertSame(self::CONNECTOR . ':4', $version);
        self::assertSame(['GetRole', 'PutRolePolicy', 'GetNetworkConnector', 'UpdateNetworkConnector', 'GetNetworkConnector', 'GetNetworkConnector'], $mocks->calls);
        self::assertSame('shop-production', $mocks->params[3]['Identifier']);
        self::assertSame(['subnet-a', 'subnet-b'], $mocks->params[3]['Configuration']['VpcEgressConfiguration']['SubnetIds']);
        self::assertContains('deploy.network_updated', array_column($steps->getArrayCopy(), 0));
        $updated = array_values(array_filter($steps->getArrayCopy(), fn (array $s) => $s[0] === 'deploy.network_updated'))[0];
        self::assertSame(4, $updated[1]['version']);
    }

    public function test_a_different_security_group_set_is_a_change(): void
    {
        $other = self::connector(['Configuration' => ['VpcEgressConfiguration' => [
            'SubnetIds' => ['subnet-a', 'subnet-b'], 'SecurityGroupIds' => ['sg-1', 'sg-2'], 'NetworkProtocol' => 'IPv4', 'AssociatedComputeResourceTypes' => ['WebFunction'],
        ]]]);
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['Role' => ['Arn' => self::ROLE]]),
            new Result([]),
            new Result($other),
            new Result(['Arn' => self::CONNECTOR, 'Name' => 'shop-production', 'Id' => 'nc-1']),
            new Result(self::connector(['LastUpdateStatus' => 'Successful', 'LatestVersionArn' => self::CONNECTOR . ':4'])),
        );
        self::steps($step);

        $this->subject($mocks)->ensure($this->config(), [], $step);

        self::assertContains('UpdateNetworkConnector', $mocks->calls);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function failures(): iterable
    {
        yield 'creation' => [['State' => 'FAILED', 'StateReasonCode' => 'SubnetOutOfIPAddresses', 'StateReason' => 'no IPs'], 'SubnetOutOfIPAddresses'];
    }

    /** @param array<string, mixed> $state */
    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function test_failed_connector_stops_with_reason(array $state, string $code): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['Role' => ['Arn' => self::ROLE]]),
            new Result([]),
            new Result(self::connector($state)),
        );
        self::steps($step);

        try {
            $this->subject($mocks)->ensure($this->config(), [], $step);
            self::fail('expected a DeployException');
        } catch (DeployException $e) {
            self::assertSame('deploy.network_failed', $e->key);
            self::assertSame('shop-production', $e->params['connector']);
            self::assertSame($code, $e->params['code']);
            self::assertSame('no IPs', $e->params['reason'], 'AWS text; the CLI swaps in the translation of the code');
        }
        self::assertNotContains('UpdateNetworkConnector', $mocks->calls);
    }

    public function test_an_update_that_fails_while_waiting_stops_with_reason(): void
    {
        $changed = self::connector(['Configuration' => ['VpcEgressConfiguration' => ['SubnetIds' => ['subnet-z'], 'SecurityGroupIds' => [], 'NetworkProtocol' => 'IPv4', 'AssociatedComputeResourceTypes' => ['WebFunction']]]]);
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['Role' => ['Arn' => self::ROLE]]),
            new Result([]),
            new Result($changed),
            new Result(['Arn' => self::CONNECTOR, 'Name' => 'shop-production', 'Id' => 'nc-1']),
            new Result(self::connector(['LastUpdateStatus' => 'Failed', 'LastUpdateStatusReasonCode' => 'InvalidSubnet', 'LastUpdateStatusReason' => 'bad subnet'])),
        );
        self::steps($step);

        $this->expectException(DeployException::class);
        $this->subject($mocks)->ensure($this->config(), [], $step);
    }

    public function test_a_connector_that_never_settles_times_out(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            AwsMocks::error(IamException::class, 'GetRole', 'NoSuchEntity', 404),
            new Result(['Role' => ['Arn' => self::ROLE]]),
            new Result([]),
            self::notFound('GetNetworkConnector'),
            new Result(['Arn' => self::CONNECTOR, 'Name' => 'shop-production', 'Id' => 'nc-1', 'VersionArn' => self::CONNECTOR . ':1']),
        );
        for ($i = 0; $i < 70; $i++) {
            $mocks->handler->append(new Result(self::connector(['State' => 'PENDING'])));
        }
        self::steps($step);

        try {
            $this->subject($mocks)->ensure($this->config(), [], $step);
            self::fail('expected a DeployException');
        } catch (DeployException $e) {
            self::assertSame('deploy.network_timeout', $e->key);
        }
    }

    public function test_foreign_connector_is_refused(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['Role' => ['Arn' => self::ROLE]]),
            new Result([]),
            new Result(self::connector(['OperatorRole' => 'arn:aws:iam::111111111111:role/someone-else'])),
        );
        self::steps($step);

        try {
            $this->subject($mocks)->ensure($this->config(), [], $step);
            self::fail('expected a DeployException');
        } catch (DeployException $e) {
            self::assertSame('deploy.network_foreign', $e->key);
            self::assertSame('shop-production', $e->params['connector']);
        }
        self::assertNotContains('UpdateNetworkConnector', $mocks->calls);
        self::assertNotContains('DeleteNetworkConnector', $mocks->calls);
    }

    public function test_find_returns_null_when_missing_and_the_connector_when_owned(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(self::notFound('GetNetworkConnector'), new Result(self::connector()));
        $subject = $this->subject($mocks);

        self::assertNull($subject->find($this->config()));
        $found = $subject->find($this->config());
        self::assertIsArray($found);
        self::assertSame(self::CONNECTOR . ':3', $found['LatestVersionArn']);
    }

    public function test_prune_keeps_latest_and_listed_versions(): void
    {
        $versions = array_map(fn (int $n) => ['Arn' => self::CONNECTOR, 'VersionArn' => self::CONNECTOR . ":$n", 'Name' => 'shop-production', 'Id' => 'nc-1', 'Version' => $n, 'State' => 'ACTIVE'], [1, 2, 3, 4, 5]);
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['NetworkConnectorVersions' => $versions]),
            new Result([]), new Result([]), new Result([]),
        );
        $steps = self::steps($step);

        $this->subject($mocks)->pruneVersions($this->config(), [self::CONNECTOR . ':2'], $step);

        self::assertSame(['ListNetworkConnectorVersions', 'DeleteNetworkConnector', 'DeleteNetworkConnector', 'DeleteNetworkConnector'], $mocks->calls);
        self::assertSame('shop-production', $mocks->params[0]['Identifier']);
        self::assertSame([self::CONNECTOR . ':1', self::CONNECTOR . ':3', self::CONNECTOR . ':4'], array_column(array_slice($mocks->params, 1), 'Identifier'));
        self::assertCount(0, $steps);
    }

    public function test_prune_follows_pages_and_only_warns_when_a_delete_fails(): void
    {
        $page = fn (array $numbers, ?string $next) => new Result(['NetworkConnectorVersions' => array_map(fn (int $n) => ['VersionArn' => self::CONNECTOR . ":$n", 'Version' => $n, 'Arn' => self::CONNECTOR, 'Name' => 'x', 'Id' => 'x', 'State' => 'ACTIVE'], $numbers)] + ($next === null ? [] : ['NextMarker' => $next]));
        $mocks = new AwsMocks();
        $mocks->handler->append(
            $page([1, 2], 'm1'),
            $page([3], null),
            AwsMocks::error(LambdaCoreException::class, 'DeleteNetworkConnector', 'ConflictException', 409, 'in use'),
            new Result([]),
        );
        $steps = self::steps($step);

        $this->subject($mocks)->pruneVersions($this->config(), [], $step);

        self::assertSame('m1', $mocks->params[1]['Marker']);
        self::assertSame(['ListNetworkConnectorVersions', 'ListNetworkConnectorVersions', 'DeleteNetworkConnector', 'DeleteNetworkConnector'], $mocks->calls);
        self::assertSame(['deploy.network_prune_failed'], array_column($steps->getArrayCopy(), 0));
    }

    public function test_a_failing_list_is_a_warning_too(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(AwsMocks::error(LambdaCoreException::class, 'ListNetworkConnectorVersions', 'AccessDeniedException', 403));
        $steps = self::steps($step);

        $this->subject($mocks)->pruneVersions($this->config(), [], $step);

        self::assertSame(['deploy.network_prune_failed'], array_column($steps->getArrayCopy(), 0));
    }

    public function test_delete_waits_until_gone_then_deletes_role(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['Arn' => self::CONNECTOR, 'Name' => 'shop-production', 'Id' => 'nc-1', 'State' => 'DELETING']),
            new Result(self::connector(['State' => 'DELETING'])),
            self::notFound('GetNetworkConnector'),
            new Result([]),
            new Result([]),
        );
        $steps = self::steps($step);

        $this->subject($mocks)->delete($this->config(), $step);

        self::assertSame(['DeleteNetworkConnector', 'GetNetworkConnector', 'GetNetworkConnector', 'DeleteRolePolicy', 'DeleteRole'], $mocks->calls);
        self::assertSame('shop-production', $mocks->params[0]['Identifier']);
        self::assertSame('arco-shop-production-network', $mocks->params[4]['RoleName']);
        self::assertSame(['deploy.network_removed'], array_column($steps->getArrayCopy(), 0));
    }

    public function test_delete_of_a_connector_already_gone_is_success(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(self::notFound('DeleteNetworkConnector'), new Result([]), new Result([]));
        self::steps($step);

        $this->subject($mocks)->delete($this->config(), $step);

        self::assertSame(['DeleteNetworkConnector', 'DeleteRolePolicy', 'DeleteRole'], $mocks->calls);
    }

    public function test_messages_exist_in_both_languages(): void
    {
        $keys = ['deploy.step_network', 'deploy.network_waiting', 'deploy.network_updated', 'deploy.network_failed', 'deploy.network_removed', 'deploy.network_prune_failed', 'deploy.network_foreign', 'deploy.network_timeout',
            ...array_map(fn (string $c) => "deploy.network_reason.$c", ['InvalidSubnet', 'InvalidSecurityGroup', 'SubnetOutOfIPAddresses', 'InsufficientRolePermissions'])];
        $dir = dirname(__DIR__, 3) . '/packages/arco/lang';
        foreach (['en', 'es'] as $locale) {
            $messages = require "$dir/$locale.php";
            foreach ($keys as $key) {
                self::assertArrayHasKey($key, $messages, "$locale: $key");
            }
        }
        self::assertNotSame('deploy.network_waiting', (new Translator('es', $dir))->trans('deploy.network_waiting'));
    }
}
