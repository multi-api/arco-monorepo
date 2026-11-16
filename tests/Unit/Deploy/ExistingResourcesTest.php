<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy;

use Aws\CloudFormation\Exception\CloudFormationException;
use Aws\Ec2\Exception\Ec2Exception;
use Aws\EventBridge\Exception\EventBridgeException;
use Aws\Lambda\Exception\LambdaException;
use Aws\LambdaCore\Exception\LambdaCoreException;
use Aws\LambdaWeb\Exception\LambdaWebException;
use Aws\ResourceGroups\Exception\ResourceGroupsException;
use Aws\Result;
use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Deploy\ExistingResources;
use PHPUnit\Framework\TestCase;

final class ExistingResourcesTest extends TestCase
{
    private function config(): AppConfig
    {
        return ConfigResolver::resolve(App::make('shop')->region('us-east-1')->environment('production')->toArray(), 'production');
    }

    /** @return array{type: string, name: string, arn: string, retained: bool} */
    private static function resource(string $type, string $name, string $arn, bool $retained = false): array
    {
        return ['type' => $type, 'name' => $name, 'arn' => $arn, 'retained' => $retained];
    }

    /** @param list<mixed> $answers */
    private function gone(array $resource, array $answers, ?AwsMocks &$mocks = null): bool
    {
        $mocks = new AwsMocks();
        foreach ($answers as $answer) {
            $mocks->handler->append($answer);
        }
        return (new ExistingResources($mocks->clients()))->gone($this->config(), $resource);
    }

    /** The real case: the Tagging API still lists a connector GetNetworkConnector no longer finds. */
    public function test_a_connector_not_found_is_gone(): void
    {
        $connector = self::resource('lambda:network-connector', 'nc-87ed', 'arn:aws:lambda:us-east-1:111111111111:network-connector:nc-87ed');

        self::assertTrue($this->gone($connector, [AwsMocks::error(LambdaCoreException::class, 'GetNetworkConnector', 'ResourceNotFoundException', 404)], $mocks));
        self::assertSame(['GetNetworkConnector'], $mocks->calls);
        self::assertSame('nc-87ed', $mocks->params[0]['Identifier']);

        self::assertFalse($this->gone($connector, [new Result(['Id' => 'nc-87ed', 'State' => 'ACTIVE'])]), 'still there');
    }

    public function test_the_web_function_is_gone_only_when_neither_lambda_finds_it(): void
    {
        $web = self::resource('lambda:function', 'shop-production', 'arn:aws:lambda:us-east-1:111111111111:function:shop-production');
        $noWeb = AwsMocks::error(LambdaWebException::class, 'GetWebFunction', 'ResourceNotFoundException', 404);
        $noFunction = AwsMocks::error(LambdaException::class, 'GetFunctionConfiguration', 'ResourceNotFoundException', 404);

        self::assertTrue($this->gone($web, [$noWeb, $noFunction], $mocks));
        self::assertSame(['GetWebFunction', 'GetFunctionConfiguration'], $mocks->calls);
        self::assertFalse($this->gone($web, [new Result(['functionName' => 'shop-production'])]));
        self::assertFalse($this->gone($web, [$noWeb, new Result(['FunctionName' => 'shop-production'])]));

        $webArn = self::resource('lambda:web-function', 'shop-production', 'arn:aws:lambda:us-east-1:111111111111:web-function:shop-production');
        self::assertTrue($this->gone($webArn, [$noWeb]));

        $worker = self::resource('lambda:function', 'shop-production-queue-jobs', 'arn:aws:lambda:us-east-1:111111111111:function:shop-production-queue-jobs');
        self::assertTrue($this->gone($worker, [$noFunction], $mocks));
        self::assertSame(['GetFunctionConfiguration'], $mocks->calls);
    }

    public function test_rules_groups_stacks_and_endpoints(): void
    {
        $rule = self::resource('events:rule', 'arco-bus/shop-production-schedule', 'arn:aws:events:us-east-1:111111111111:rule/arco-bus/shop-production-schedule');
        self::assertTrue($this->gone($rule, [AwsMocks::error(EventBridgeException::class, 'DescribeRule', 'ResourceNotFoundException', 404)], $mocks));
        self::assertSame(['EventBusName' => 'arco-bus', 'Name' => 'shop-production-schedule'], array_intersect_key($mocks->params[0], ['EventBusName' => 1, 'Name' => 1]));

        $group = self::resource('resource-groups:group', 'arco-shop-production', 'arn:aws:resource-groups:us-east-1:111111111111:group/arco-shop-production');
        self::assertTrue($this->gone($group, [AwsMocks::error(ResourceGroupsException::class, 'GetGroup', 'NotFoundException', 404)]));
        self::assertFalse($this->gone($group, [new Result(['Group' => ['Name' => 'arco-shop-production']])]));

        $stack = self::resource('cloudformation:stack', 'arco-shop-production', 'arn:aws:cloudformation:us-east-1:111111111111:stack/arco-shop-production/abc');
        self::assertTrue($this->gone($stack, [new Result(['Stacks' => [['StackStatus' => 'DELETE_COMPLETE']]])]), 'a deleted stack answers by id');
        self::assertTrue($this->gone($stack, [AwsMocks::error(CloudFormationException::class, 'DescribeStacks', 'ValidationError', 400, 'Stack with id arco-shop-production does not exist')]));
        self::assertFalse($this->gone($stack, [new Result(['Stacks' => [['StackStatus' => 'DELETE_IN_PROGRESS']]])]));

        $endpoint = self::resource('ec2:vpc-endpoint', 'vpce-1', 'arn:aws:ec2:us-east-1:111111111111:vpc-endpoint/vpce-1');
        self::assertTrue($this->gone($endpoint, [new Result(['VpcEndpoints' => [['VpcEndpointId' => 'vpce-1', 'State' => 'deleted']]])]));
        self::assertTrue($this->gone($endpoint, [AwsMocks::error(Ec2Exception::class, 'DescribeVpcEndpoints', 'InvalidVpcEndpointId.NotFound', 400)]));
        self::assertFalse($this->gone($endpoint, [new Result(['VpcEndpoints' => [['VpcEndpointId' => 'vpce-1', 'State' => 'available']]])]));
    }

    /** Conservative: a denied check, or a type Arco cannot check, keeps the resource as a leftover. */
    public function test_a_denied_check_or_an_unknown_type_is_not_gone(): void
    {
        $connector = self::resource('lambda:network-connector', 'nc-1', 'arn:aws:lambda:us-east-1:111111111111:network-connector:nc-1');
        self::assertFalse($this->gone($connector, [AwsMocks::error(LambdaCoreException::class, 'GetNetworkConnector', 'AccessDeniedException', 403)]));

        $distribution = self::resource('cloudfront:distribution', 'E1', 'arn:aws:cloudfront::111111111111:distribution/E1');
        self::assertFalse($this->gone($distribution, [], $mocks));
        self::assertSame([], $mocks->calls, 'no call for a type without a check');
    }

    public function test_filter_drops_only_what_is_gone_and_never_checks_retained_resources(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(AwsMocks::error(LambdaCoreException::class, 'GetNetworkConnector', 'ResourceNotFoundException', 404));
        $queue = self::resource('sqs', 'shop-production-jobs', 'arn:aws:sqs:us-east-1:111111111111:shop-production-jobs', retained: true);
        $connector = self::resource('lambda:network-connector', 'nc-1', 'arn:aws:lambda:us-east-1:111111111111:network-connector:nc-1');
        $distribution = self::resource('cloudfront:distribution', 'E1', 'arn:aws:cloudfront::111111111111:distribution/E1');

        $left = (new ExistingResources($mocks->clients()))->filter($this->config(), [$queue, $connector, $distribution]);

        self::assertSame([$queue, $distribution], $left);
        self::assertSame(['GetNetworkConnector'], $mocks->calls);
    }
}
