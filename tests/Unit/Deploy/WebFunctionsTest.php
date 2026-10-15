<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy;

use Aws\LambdaWeb\Exception\LambdaWebException;
use Aws\Result;
use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Deploy\DeployException;
use Multiapi\Arco\Deploy\WebFunctions;
use PHPUnit\Framework\TestCase;

final class WebFunctionsTest extends TestCase
{
    public function test_get_returns_null_when_missing(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(AwsMocks::error(LambdaWebException::class, 'GetWebFunction', 'ResourceNotFoundException', 404));

        self::assertNull((new WebFunctions($mocks->clients()->lambdaWeb(), AwsMocks::noSleep()))->get('shop-production'));
    }

    public function test_create_retries_while_a_new_role_propagates(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            AwsMocks::error(LambdaWebException::class, 'CreateWebFunction', 'ValidationException', 400, 'The role defined for the function cannot be assumed by Lambda.'),
            new Result(['revision' => ['revisionId' => 'rev-1'], 'endpoint' => ['domainName' => 'x.on.aws']]),
        );
        $sleeper = AwsMocks::noSleep();

        $revision = (new WebFunctions($mocks->clients()->lambdaWeb(), $sleeper))->create('shop-production', [], [], [], roleJustCreated: true);

        self::assertSame('rev-1', $revision);
        self::assertSame(['CreateWebFunction', 'CreateWebFunction'], $mocks->calls);
    }

    public function test_role_errors_are_not_retried_when_the_role_already_existed(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            AwsMocks::error(LambdaWebException::class, 'CreateWebFunctionRevision', 'ValidationException', 400, 'The role defined for the function cannot be assumed by Lambda.'),
        );

        $this->expectException(LambdaWebException::class);
        try {
            (new WebFunctions($mocks->clients()->lambdaWeb(), AwsMocks::noSleep()))->createRevision('shop-production', []);
        } finally {
            self::assertSame(['CreateWebFunctionRevision'], $mocks->calls);
        }
    }

    public function test_unrelated_validation_error_is_not_retried_even_if_the_function_name_contains_role(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            AwsMocks::error(
                LambdaWebException::class, 'CreateWebFunction', 'ValidationException', 400,
                'Memory size must be a multiple of 64.',
                'Error executing "CreateWebFunction" for roles-admin-production; AWS HTTP error: Memory size must be a multiple of 64.',
            ),
        );

        $this->expectException(LambdaWebException::class);
        try {
            (new WebFunctions($mocks->clients()->lambdaWeb(), AwsMocks::noSleep()))->create('roles-admin-production', [], [], [], roleJustCreated: true);
        } finally {
            self::assertSame(['CreateWebFunction'], $mocks->calls);
        }
    }

    public function test_wait_revision_reports_failure_details(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['state' => 'Pending', 'stateReason' => '']),
            new Result(['state' => 'Failed', 'stateReason' => 'Build failed', 'errors' => [['attribute' => 'buildConfig', 'errorCode' => 'S3AccessDenied', 'errorMessage' => 'Access denied to object']]]),
        );

        try {
            (new WebFunctions($mocks->clients()->lambdaWeb(), AwsMocks::noSleep()))->waitRevision('fn', 'rev-1');
            self::fail('Expected DeployException');
        } catch (DeployException $e) {
            self::assertSame('deploy.revision_failed', $e->key);
            self::assertStringContainsString('S3AccessDenied', (string) $e->params['reason']);
        }
    }

    public function test_route_retries_conflicts_then_succeeds(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            AwsMocks::error(LambdaWebException::class, 'UpdateWebFunctionEndpoint', 'ConflictException', 409),
            AwsMocks::error(LambdaWebException::class, 'UpdateWebFunctionEndpoint', 'ConflictException', 409),
            new Result([]),
        );
        $sleeper = AwsMocks::noSleep();
        $config = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->environment('production')->toArray(), 'production');

        (new WebFunctions($mocks->clients()->lambdaWeb(), $sleeper))->route('shop-production', 'rev-1', $config);

        self::assertSame(['UpdateWebFunctionEndpoint', 'UpdateWebFunctionEndpoint', 'UpdateWebFunctionEndpoint'], $mocks->calls);
        self::assertSame(6, $sleeper->slept);
    }

    public function test_wait_endpoint_polls_until_the_expected_revision_has_the_traffic(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['state' => 'Active', 'updateStatus' => 'Successful', 'domainName' => 'x', 'revisionWeights' => [['revisionId' => 'rev-1', 'weight' => 100]]]),
            new Result(['state' => 'Active', 'updateStatus' => 'Successful', 'domainName' => 'x', 'revisionWeights' => [['revisionId' => 'rev-2', 'weight' => 100]]]),
        );
        $sleeper = AwsMocks::noSleep();

        $endpoint = (new WebFunctions($mocks->clients()->lambdaWeb(), $sleeper))->waitEndpoint('fn', 600, 'rev-2');

        self::assertSame(['GetWebFunctionEndpoint', 'GetWebFunctionEndpoint'], $mocks->calls);
        self::assertSame('rev-2', WebFunctions::activeRevision($endpoint));
        self::assertSame(2, $sleeper->slept);
    }

    public function test_active_revision_is_the_heaviest_weight(): void
    {
        self::assertSame('rev-2', WebFunctions::activeRevision(['revisionWeights' => [
            ['revisionId' => 'rev-1', 'weight' => 10],
            ['revisionId' => 'rev-2', 'weight' => 90],
        ]]));
        self::assertNull(WebFunctions::activeRevision(['revisionWeights' => []]));
    }
}
