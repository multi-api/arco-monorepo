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

    public function test_long_environments_with_a_common_prefix_get_different_operator_roles(): void
    {
        $prefix = str_repeat('a', 40);
        $names = [];
        foreach (['production-eu', 'production-us'] as $environment) {
            $config = ConfigResolver::resolve(App::make($prefix)->region('us-east-1')->environment($environment)->toArray(), $environment);
            self::assertGreaterThan(51, strlen($config->functionName()), 'long enough to be shortened');
            $names[] = $name = NetworkConnectors::operatorRoleName($config);
            self::assertLessThanOrEqual(64, strlen($name));
            self::assertStringEndsWith('-network', $name);
            self::assertStringStartsWith('arco-' . $prefix, $name);
        }
        self::assertNotSame($names[0], $names[1], 'a shortened name keeps a hash of the full one');
        $exact = ConfigResolver::resolve(App::make(str_repeat('a', 40))->region('us-east-1')->environment(str_repeat('b', 10))->toArray(), str_repeat('b', 10));
        self::assertSame('arco-' . $exact->functionName() . '-network', NetworkConnectors::operatorRoleName($exact), 'a name that fits is not shortened');
    }

    public function test_operator_policy_matches_the_documented_operator_policy(): void
    {
        // The AWS-documented policy: CreateNetworkInterface carries no condition (the request on the subnet and
        // the security group has no ec2:ManagedResourceOperator key, so a condition there denies it); only the
        // create-time tagging is scoped to the service.
        $policy = NetworkConnectors::operatorPolicy();
        [$create, $tag] = $policy['Statement'];
        self::assertSame('ec2:CreateNetworkInterface', $create['Action']);
        self::assertSame(['arn:aws:ec2:*:*:network-interface/*', 'arn:aws:ec2:*:*:subnet/*', 'arn:aws:ec2:*:*:security-group/*'], $create['Resource']);
        self::assertArrayNotHasKey('Condition', $create);
        self::assertSame('ec2:CreateTags', $tag['Action']);
        self::assertSame('arn:aws:ec2:*:*:network-interface/*', $tag['Resource']);
        self::assertSame(['StringEquals' => ['ec2:ManagedResourceOperator' => 'network-connectors.lambda.amazonaws.com']], $tag['Condition']);
        $actions = array_merge(...array_map(fn (array $s) => (array) $s['Action'], $policy['Statement']));
        foreach (['ec2:DeleteNetworkInterface', 'ec2:DescribeNetworkInterfaces', 'ec2:DescribeSubnets', 'ec2:DescribeSecurityGroups'] as $action) {
            self::assertContains($action, $actions);
        }
    }

    public function test_creates_role_then_connector_and_waits_for_active(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::notFound('GetNetworkConnector'),
            AwsMocks::error(IamException::class, 'GetRole', 'NoSuchEntity', 404),
            new Result(['Role' => ['Arn' => self::ROLE]]),
            new Result([]),
            new Result(['Arn' => self::CONNECTOR, 'Name' => 'shop-production', 'Id' => 'nc-1', 'VersionArn' => self::CONNECTOR . ':1', 'State' => 'PENDING']),
            new Result(self::connector(['State' => 'PENDING'])),
            new Result(self::connector(['LatestVersionArn' => self::CONNECTOR . ':1'])),
        );
        $sleeper = AwsMocks::noSleep();
        $steps = self::steps($step);

        $version = $this->subject($mocks, $sleeper)->ensure($this->config(), ['arco:app' => 'shop'], $step);

        self::assertSame(self::CONNECTOR . ':1', $version);
        self::assertSame(['GetNetworkConnector', 'GetRole', 'CreateRole', 'PutRolePolicy', 'CreateNetworkConnector', 'GetNetworkConnector', 'GetNetworkConnector'], $mocks->calls);
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
        self::assertSame('shop-production', $mocks->params[0]['Identifier']);
        self::assertGreaterThan(0, $sleeper->slept);
        $keys = array_column($steps->getArrayCopy(), 0);
        self::assertSame(['deploy.step_network', 'deploy.network_waiting'], $keys);
        self::assertSame(['connector' => 'shop-production', 'subnets' => 'subnet-a, subnet-b', 'groups' => 'sg-1'], $steps[0][1]);
    }

    public function test_the_operator_role_gets_the_boundary(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(self::connector()),
            AwsMocks::error(IamException::class, 'GetRole', 'NoSuchEntity', 404),
            new Result(['Role' => ['Arn' => self::ROLE]]),
            new Result([]),
        );
        self::steps($step);

        $this->subject($mocks)->ensure($this->config(self::BOUNDARY), [], $step);

        self::assertSame(['GetNetworkConnector', 'GetRole', 'CreateRole', 'PutRolePolicy'], $mocks->calls);
        self::assertSame(self::BOUNDARY, $mocks->params[2]['PermissionsBoundary']);
    }

    public function test_unchanged_connector_makes_no_update(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(self::connector()),
            new Result(['Role' => ['Arn' => self::ROLE]]),
            new Result([]),
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
            new Result($changed),
            new Result(['Role' => ['Arn' => self::ROLE]]),
            new Result([]),
            new Result(['Arn' => self::CONNECTOR, 'Name' => 'shop-production', 'Id' => 'nc-1', 'VersionArn' => self::CONNECTOR . ':4', 'LastUpdateStatus' => 'InProgress']),
            new Result(self::connector(['LastUpdateStatus' => 'InProgress'])),
            new Result(self::connector(['LastUpdateStatus' => 'Successful', 'LatestVersionArn' => self::CONNECTOR . ':4', 'LatestVersion' => 4])),
        );
        $steps = self::steps($step);

        $version = $this->subject($mocks)->ensure($this->config(), [], $step);

        self::assertSame(self::CONNECTOR . ':4', $version);
        self::assertSame(['GetNetworkConnector', 'GetRole', 'PutRolePolicy', 'UpdateNetworkConnector', 'GetNetworkConnector', 'GetNetworkConnector'], $mocks->calls);
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
            new Result($other),
            new Result(['Role' => ['Arn' => self::ROLE]]),
            new Result([]),
            new Result(['Arn' => self::CONNECTOR, 'Name' => 'shop-production', 'Id' => 'nc-1', 'VersionArn' => self::CONNECTOR . ':4']),
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
            new Result(self::connector($state)),
            new Result(['Role' => ['Arn' => self::ROLE]]),
            new Result([]),
        );
        self::steps($step);

        try {
            // The live revision pins it: replacing it would take the app off the network.
            $this->subject($mocks)->ensure($this->config(), [], $step, 'arn:aws:lambda:us-east-1:111111111111:network-connector:nc-1:3');
            self::fail('expected a DeployException');
        } catch (DeployException $e) {
            self::assertSame('deploy.network_failed', $e->key);
            self::assertSame('shop-production', $e->params['connector']);
            self::assertSame($code, $e->params['code']);
            self::assertSame('no IPs', $e->params['reason'], 'AWS text; the CLI swaps in the translation of the code');
            self::assertSame('us-east-1', $e->params['region']);
        }
        self::assertNotContains('UpdateNetworkConnector', $mocks->calls);
        self::assertNotContains('DeleteNetworkConnector', $mocks->calls);
    }

    public function test_an_owned_failed_connector_no_live_revision_uses_is_replaced(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(self::connector(['State' => 'FAILED', 'StateReasonCode' => 'InvalidSubnet', 'StateReason' => 'bad subnet'])),
            new Result(['Role' => ['Arn' => self::ROLE]]),
            new Result([]),
            new Result(['State' => 'DELETING']),
            new Result(self::connector(['State' => 'DELETING'])),
            self::notFound('GetNetworkConnector'),
            new Result(['Arn' => self::CONNECTOR, 'Name' => 'shop-production', 'Id' => 'nc-2', 'VersionArn' => self::CONNECTOR . ':1', 'State' => 'PENDING']),
            new Result(self::connector(['Id' => 'nc-2', 'LatestVersionArn' => self::CONNECTOR . ':1', 'LatestVersion' => 1])),
        );
        $steps = self::steps($step);

        $version = $this->subject($mocks)->ensure($this->config(), [], $step, 'arn:aws:lambda:us-east-1:111111111111:network-connector:nc-0ld:2');

        self::assertSame(self::CONNECTOR . ':1', $version);
        self::assertSame(['GetNetworkConnector', 'GetRole', 'PutRolePolicy', 'DeleteNetworkConnector', 'GetNetworkConnector', 'GetNetworkConnector', 'CreateNetworkConnector', 'GetNetworkConnector'], $mocks->calls);
        self::assertSame('shop-production', $mocks->params[3]['Identifier'], 'the whole connector, by name');
        self::assertContains(['deploy.network_replacing', ['connector' => 'shop-production', 'reason' => 'bad subnet', 'code' => 'InvalidSubnet']], $steps->getArrayCopy());
        self::assertContains('deploy.network_replacing', \Multiapi\Arco\Cli\StepPrinter::WARNINGS);
    }

    public function test_a_failed_connector_message_names_the_manual_delete_command(): void
    {
        foreach (['en', 'es'] as $lang) {
            $text = (new Translator($lang, dirname(__DIR__, 3) . '/packages/arco/lang'))->trans('deploy.network_failed', ['connector' => 'shop-production', 'reason' => 'x', 'code' => 'y', 'region' => 'us-east-1']);
            self::assertStringContainsString('aws lambda-core delete-network-connector --identifier shop-production --region us-east-1', $text);
        }
    }

    public function test_create_is_retried_while_a_new_operator_role_propagates(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::notFound('GetNetworkConnector'),
            AwsMocks::error(IamException::class, 'GetRole', 'NoSuchEntity', 404),
            new Result(['Role' => ['Arn' => self::ROLE]]),
            new Result([]),
            AwsMocks::error(LambdaCoreException::class, 'CreateNetworkConnector', 'ValidationException', 400, 'The operator role cannot be assumed by the service'),
            new Result(['Arn' => self::CONNECTOR, 'Name' => 'shop-production', 'Id' => 'nc-1', 'VersionArn' => self::CONNECTOR . ':1', 'State' => 'PENDING']),
            new Result(self::connector(['LatestVersionArn' => self::CONNECTOR . ':1'])),
        );
        self::steps($step);

        $version = $this->subject($mocks)->ensure($this->config(), [], $step);

        self::assertSame(self::CONNECTOR . ':1', $version);
        self::assertSame(['GetNetworkConnector', 'GetRole', 'CreateRole', 'PutRolePolicy', 'CreateNetworkConnector', 'CreateNetworkConnector', 'GetNetworkConnector'], $mocks->calls);
    }

    public function test_create_is_retried_when_the_existing_operator_role_just_got_its_policy(): void
    {
        // Seen on real AWS: the rewritten inline policy is not visible yet when lambda-core checks it.
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::notFound('GetNetworkConnector'),
            new Result(['Role' => ['Arn' => self::ROLE]]),
            new Result([]),
            AwsMocks::error(LambdaCoreException::class, 'CreateNetworkConnector', 'InvalidParameterValueException', 400, 'Encountered unauthorized operation while calling EC2 due to invalid ConnectorOperatorRole permissions'),
            new Result(['Arn' => self::CONNECTOR, 'Name' => 'shop-production', 'State' => 'PENDING', 'VersionArn' => self::CONNECTOR . ':1']),
            new Result(self::connector()),
        );
        self::steps($step);

        $this->subject($mocks)->ensure($this->config(), [], $step);

        self::assertSame(['GetNetworkConnector', 'GetRole', 'PutRolePolicy', 'CreateNetworkConnector', 'CreateNetworkConnector', 'GetNetworkConnector'], $mocks->calls);
    }

    public function test_create_is_not_retried_on_an_error_unrelated_to_the_role(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::notFound('GetNetworkConnector'),
            new Result(['Role' => ['Arn' => self::ROLE]]),
            new Result([]),
            AwsMocks::error(LambdaCoreException::class, 'CreateNetworkConnector', 'ValidationException', 400, 'Subnet subnet-1 does not exist'),
        );
        self::steps($step);

        try {
            $this->subject($mocks)->ensure($this->config(), [], $step);
            self::fail('expected the AWS error');
        } catch (LambdaCoreException $e) {
            self::assertSame('ValidationException', $e->getAwsErrorCode());
        }
        self::assertSame(['GetNetworkConnector', 'GetRole', 'PutRolePolicy', 'CreateNetworkConnector'], $mocks->calls);
    }

    public function test_a_create_without_a_version_arn_uses_the_one_of_the_active_connector(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::notFound('GetNetworkConnector'),
            new Result(['Role' => ['Arn' => self::ROLE]]),
            new Result([]),
            new Result(['Arn' => self::CONNECTOR, 'Name' => 'shop-production', 'State' => 'PENDING']),
            new Result(self::connector(['LatestVersionArn' => self::CONNECTOR . ':1'])),
        );
        self::steps($step);

        self::assertSame(self::CONNECTOR . ':1', $this->subject($mocks)->ensure($this->config(), [], $step));
    }

    /** @return iterable<string, array{list<mixed>}> answers after the operator role's GetRole and PutRolePolicy */
    public static function noVersion(): iterable
    {
        yield 'created' => [[new Result(['State' => 'PENDING']), new Result(self::connector(['LatestVersionArn' => null]))]];
        yield 'unchanged' => [[]];
    }

    /** @param list<mixed> $after */
    #[\PHPUnit\Framework\Attributes\DataProvider('noVersion')]
    public function test_ensure_never_returns_an_empty_version_arn(array $after): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            $after === [] ? new Result(self::connector(['LatestVersionArn' => null])) : self::notFound('GetNetworkConnector'),
            new Result(['Role' => ['Arn' => self::ROLE]]),
            new Result([]),
            ...$after,
        );
        self::steps($step);

        $this->expectException(\UnexpectedValueException::class);
        $this->subject($mocks)->ensure($this->config(), [], $step);
    }

    private function changedConnector(): Result
    {
        return new Result(self::connector(['Configuration' => ['VpcEgressConfiguration' => ['SubnetIds' => ['subnet-z'], 'SecurityGroupIds' => [], 'NetworkProtocol' => 'IPv4', 'AssociatedComputeResourceTypes' => ['WebFunction']]]]));
    }

    public function test_an_update_that_fails_while_waiting_stops_with_reason(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            $this->changedConnector(),
            new Result(['Role' => ['Arn' => self::ROLE]]),
            new Result([]),
            new Result(['Arn' => self::CONNECTOR, 'Name' => 'shop-production', 'Id' => 'nc-1', 'VersionArn' => self::CONNECTOR . ':4']),
            new Result(self::connector(['LatestVersionArn' => self::CONNECTOR . ':4', 'LastUpdateStatus' => 'Failed', 'LastUpdateStatusReasonCode' => 'InvalidSubnet', 'LastUpdateStatusReason' => 'bad subnet'])),
        );
        self::steps($step);

        try {
            $this->subject($mocks)->ensure($this->config(), [], $step);
            self::fail('expected a DeployException');
        } catch (DeployException $e) {
            self::assertSame('deploy.network_failed', $e->key);
            self::assertSame('shop-production', $e->params['connector']);
            self::assertSame('InvalidSubnet', $e->params['code']);
            self::assertSame('bad subnet', $e->params['reason']);
        }
    }

    public function test_a_stale_failed_status_from_a_previous_update_is_ignored_until_the_read_reflects_this_update(): void
    {
        $mocks = new AwsMocks();
        $stale = ['LastUpdateStatus' => 'Failed', 'LastUpdateStatusReasonCode' => 'InvalidSubnet', 'LastModified' => '2026-10-27T10:00:00Z'];
        $mocks->handler->append(
            new Result([...self::connector($stale), 'Configuration' => ['VpcEgressConfiguration' => ['SubnetIds' => ['subnet-z'], 'SecurityGroupIds' => [], 'NetworkProtocol' => 'IPv4', 'AssociatedComputeResourceTypes' => ['WebFunction']]]]),
            new Result(['Role' => ['Arn' => self::ROLE]]),
            new Result([]),
            new Result(['Arn' => self::CONNECTOR, 'Name' => 'shop-production', 'Id' => 'nc-1', 'VersionArn' => self::CONNECTOR . ':4']),
            new Result(self::connector($stale)),
            new Result(self::connector(['LastUpdateStatus' => 'Successful', 'LatestVersionArn' => self::CONNECTOR . ':4', 'LatestVersion' => 4])),
        );
        self::steps($step);

        self::assertSame(self::CONNECTOR . ':4', $this->subject($mocks)->ensure($this->config(), [], $step));
        self::assertSame(['GetNetworkConnector', 'GetRole', 'PutRolePolicy', 'UpdateNetworkConnector', 'GetNetworkConnector', 'GetNetworkConnector'], $mocks->calls);
    }

    public function test_a_stale_failed_status_does_not_block_a_connector_that_already_matches(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(self::connector(['LastUpdateStatus' => 'Failed', 'LastUpdateStatusReasonCode' => 'InvalidSubnet'])),
            new Result(['Role' => ['Arn' => self::ROLE]]),
            new Result([]),
        );
        self::steps($step);

        self::assertSame(self::CONNECTOR . ':3', $this->subject($mocks)->ensure($this->config(), [], $step));
    }

    public function test_a_connector_that_never_settles_times_out(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::notFound('GetNetworkConnector'),
            AwsMocks::error(IamException::class, 'GetRole', 'NoSuchEntity', 404),
            new Result(['Role' => ['Arn' => self::ROLE]]),
            new Result([]),
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
        self::assertSame(['GetNetworkConnector'], $mocks->calls, 'no update, no delete and no IAM write (no role created or rewritten)');
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

    /** @return iterable<string, array{list<array<string, mixed>>}> */
    public static function unsafeVersionLists(): iterable
    {
        yield 'version number missing' => [[['VersionArn' => self::CONNECTOR . ':1'], ['VersionArn' => self::CONNECTOR . ':2', 'Version' => 2]]];
        yield 'unqualified ARN' => [[['VersionArn' => self::CONNECTOR, 'Version' => 1], ['VersionArn' => self::CONNECTOR . ':2', 'Version' => 2]]];
        yield 'ARN does not end in its version' => [[['VersionArn' => self::CONNECTOR . ':7', 'Version' => 1], ['VersionArn' => self::CONNECTOR . ':2', 'Version' => 2]]];
    }

    /** @param list<array<string, mixed>> $versions */
    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeVersionLists')]
    public function test_prune_deletes_nothing_when_the_version_list_cannot_be_trusted(array $versions): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(new Result(['NetworkConnectorVersions' => $versions]));
        $steps = self::steps($step);

        $this->subject($mocks)->pruneVersions($this->config(), [], $step);

        self::assertSame(['ListNetworkConnectorVersions'], $mocks->calls);
        self::assertCount(0, $steps, 'a quiet no-op');
    }

    public function test_prune_matches_kept_versions_by_number_whichever_way_the_arn_names_the_connector(): void
    {
        $versions = array_map(fn (int $n) => ['Arn' => self::CONNECTOR, 'VersionArn' => self::CONNECTOR . ":$n", 'Name' => 'shop-production', 'Id' => 'nc-1', 'Version' => $n, 'State' => 'ACTIVE'], [1, 2, 3]);
        $mocks = new AwsMocks();
        $mocks->handler->append(new Result(['NetworkConnectorVersions' => $versions]), new Result([]));
        self::steps($step);

        // The live revision names the connector by its id; the list names it by its name.
        $this->subject($mocks)->pruneVersions($this->config(), ['arn:aws:lambda:us-east-1:111111111111:network-connector:nc-1:2'], $step);

        self::assertSame([self::CONNECTOR . ':1'], array_column(array_slice($mocks->params, 1), 'Identifier'));
    }

    public function test_prune_deletes_nothing_when_a_kept_arn_cannot_be_read(): void
    {
        $versions = array_map(fn (int $n) => ['Arn' => self::CONNECTOR, 'VersionArn' => self::CONNECTOR . ":$n", 'Name' => 'shop-production', 'Id' => 'nc-1', 'Version' => $n], [1, 2, 3]);
        $mocks = new AwsMocks();
        $mocks->handler->append(new Result(['NetworkConnectorVersions' => $versions]));
        self::steps($step);

        $this->subject($mocks)->pruneVersions($this->config(), ['arn:aws:lambda:us-east-1:111111111111:network-connector:nc-1'], $step);

        self::assertSame(['ListNetworkConnectorVersions'], $mocks->calls);
    }

    public function test_prune_never_deletes_the_highest_version_even_when_it_is_listed_first(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(new Result(['NetworkConnectorVersions' => [
            ['VersionArn' => self::CONNECTOR . ':9', 'Version' => 9],
            ['VersionArn' => self::CONNECTOR . ':10', 'Version' => 10],
            ['VersionArn' => self::CONNECTOR . ':8', 'Version' => 8],
        ]]), new Result([]), new Result([]));
        self::steps($step);

        $this->subject($mocks)->pruneVersions($this->config(), [], $step);

        self::assertSame([self::CONNECTOR . ':9', self::CONNECTOR . ':8'], array_column(array_slice($mocks->params, 1), 'Identifier'));
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
