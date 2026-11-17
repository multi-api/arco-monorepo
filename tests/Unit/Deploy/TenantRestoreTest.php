<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy;

use Aws\Lambda\Exception\LambdaException;
use Aws\Result;
use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Config\Environment;
use Multiapi\Arco\Deploy\DeployException;
use Multiapi\Arco\Deploy\TenantMediator;
use Multiapi\Arco\Deploy\TenantRestore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TenantRestoreTest extends TestCase
{
    private const TAGS = ['arco:app' => 'shop', 'arco:environment' => 'production'];
    private const FUNCTION = 'shop-production-tenants';

    private function config(): AppConfig
    {
        return ConfigResolver::resolve(App::make('shop')->region('us-east-1')->environment('production', fn (Environment $e) => $e->domain('shop.example.com')->tenantDomains('routing.example.com'))->toArray(), 'production');
    }

    /** @return list<Result|\Throwable> the reads of the ownership check: the function and its tags */
    private static function owned(string $description = TenantMediator::MARKER . ' (Arco 1.0.0)', array $tags = self::TAGS): array
    {
        return [
            new Result(['FunctionName' => self::FUNCTION, 'FunctionArn' => 'arn:aws:lambda:us-east-1:111111111111:function:' . self::FUNCTION, 'Description' => $description]),
            new Result(['Tags' => $tags]),
        ];
    }

    private static function concurrency(?int $reserved): Result
    {
        return new Result($reserved === null ? [] : ['ReservedConcurrentExecutions' => $reserved]);
    }

    /** @param list<mixed> $answers */
    private function mocks(array $answers): AwsMocks
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(...$answers);
        return $mocks;
    }

    private function restore(AwsMocks $mocks): TenantRestore
    {
        return new TenantRestore($mocks->clients());
    }

    public function test_a_drained_mediator_gets_its_concurrency_deleted_and_read_back(): void
    {
        $mocks = $this->mocks([...self::owned(), self::concurrency(0), new Result([]), self::concurrency(null)]);

        $result = $this->restore($mocks)->restore($this->config());

        self::assertSame(['outcome' => 'restored', 'reserved' => null], $result);
        self::assertSame(['GetFunctionConfiguration', 'ListTags', 'GetFunctionConcurrency', 'DeleteFunctionConcurrency', 'GetFunctionConcurrency'], $mocks->calls);
        self::assertSame(self::FUNCTION, $mocks->params[3]['FunctionName']);
        self::assertCount(0, $mocks->handler);
    }

    public function test_an_enabled_mediator_is_left_alone(): void
    {
        $mocks = $this->mocks([...self::owned(), self::concurrency(null)]);

        self::assertSame(['outcome' => 'already_enabled', 'reserved' => null], $this->restore($mocks)->restore($this->config()));
        self::assertNotContains('DeleteFunctionConcurrency', $mocks->calls);
    }

    public function test_a_limit_somebody_set_on_purpose_is_kept(): void
    {
        $mocks = $this->mocks([...self::owned(), self::concurrency(5)]);

        self::assertSame(['outcome' => 'limit_kept', 'reserved' => 5], $this->restore($mocks)->restore($this->config()));
        self::assertNotContains('DeleteFunctionConcurrency', $mocks->calls);
    }

    public function test_a_concurrency_that_is_still_there_after_the_delete_is_an_error(): void
    {
        $mocks = $this->mocks([...self::owned(), self::concurrency(0), new Result([]), self::concurrency(0)]);

        try {
            $this->restore($mocks)->restore($this->config());
            self::fail('expected tenants.restore_unconfirmed');
        } catch (DeployException $e) {
            self::assertSame('tenants.restore_unconfirmed', $e->key);
            self::assertSame(['function' => self::FUNCTION, 'environment' => 'production', 'value' => '0'], $e->params);
        }
    }

    public function test_a_function_that_does_not_exist_is_an_error(): void
    {
        $mocks = $this->mocks([AwsMocks::error(LambdaException::class, 'GetFunctionConfiguration', 'ResourceNotFoundException', 404)]);

        try {
            $this->restore($mocks)->restore($this->config());
            self::fail('expected tenants.restore_missing');
        } catch (DeployException $e) {
            self::assertSame('tenants.restore_missing', $e->key);
            self::assertSame(['function' => self::FUNCTION, 'environment' => 'production'], $e->params);
        }
        self::assertSame(['GetFunctionConfiguration'], $mocks->calls);
    }

    /** @return array<string, array{list<mixed>, string}> */
    public static function refusals(): array
    {
        return [
            'another environment' => [self::owned(tags: ['arco:app' => 'shop', 'arco:environment' => 'staging']), 'deploy.tenant_mediator_not_owned'],
            'another app' => [self::owned(tags: ['arco:app' => 'other', 'arco:environment' => 'production']), 'deploy.tenant_mediator_not_owned'],
            'untagged' => [self::owned(tags: []), 'deploy.tenant_mediator_untagged'],
            'unreadable tags' => [[self::owned()[0], AwsMocks::error(LambdaException::class, 'ListTags', 'AccessDeniedException', 403, 'no')], 'deploy.tenant_mediator_tags_unreadable'],
            'tagged for this environment but not the mediator' => [self::owned(description: 'Something else'), 'tenants.restore_not_mediator'],
        ];
    }

    /** @param list<mixed> $answers */
    #[DataProvider('refusals')]
    public function test_a_function_that_is_not_this_environments_mediator_is_never_changed(array $answers, string $key): void
    {
        $mocks = $this->mocks($answers);

        try {
            $this->restore($mocks)->restore($this->config());
            self::fail("expected $key");
        } catch (DeployException $e) {
            self::assertSame($key, $e->key);
        }
        self::assertNotContains('GetFunctionConcurrency', $mocks->calls, 'nothing but the ownership reads');
        self::assertNotContains('DeleteFunctionConcurrency', $mocks->calls);
        self::assertCount(0, $mocks->handler);
    }

    public function test_a_refused_read_of_the_concurrency_stops_before_any_change(): void
    {
        $mocks = $this->mocks([...self::owned(), AwsMocks::error(LambdaException::class, 'GetFunctionConcurrency', 'AccessDeniedException', 403, 'no')]);

        $this->expectException(LambdaException::class);
        try {
            $this->restore($mocks)->restore($this->config());
        } finally {
            self::assertNotContains('DeleteFunctionConcurrency', $mocks->calls);
        }
    }
}
