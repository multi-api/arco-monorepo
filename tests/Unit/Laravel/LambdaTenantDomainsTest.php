<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Laravel;

use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\Lambda\LambdaClient;
use Aws\MockHandler;
use Aws\Result;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Foundation\Application;
use Multiapi\Arco\Laravel\ArcoServiceProvider;
use Multiapi\Arco\Laravel\Contracts\TenantDomains;
use Multiapi\Arco\Laravel\TenantDomains\Exceptions\InvalidTenantDomain;
use Multiapi\Arco\Laravel\TenantDomains\Exceptions\TenantDomainDnsIncorrect;
use Multiapi\Arco\Laravel\TenantDomains\Exceptions\TenantDomainException;
use Multiapi\Arco\Laravel\TenantDomains\Exceptions\TenantDomainNotFound;
use Multiapi\Arco\Laravel\TenantDomains\Exceptions\TenantDomainQuotaExceeded;
use Multiapi\Arco\Laravel\TenantDomains\Exceptions\TenantDomainRemovalPending;
use Multiapi\Arco\Laravel\TenantDomains\Exceptions\TenantDomainsUnavailable;
use Multiapi\Arco\Laravel\TenantDomains\Exceptions\TenantDomainTaken;
use Multiapi\Arco\Laravel\TenantDomains\LambdaTenantDomains;
use Multiapi\Arco\Laravel\TenantDomains\TenantDomainStatus;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class LambdaTenantDomainsTest extends TestCase
{
    private MockHandler $handler;
    /** @var list<array<string, mixed>> */
    private array $sent = [];
    private int $built = 0;
    private string|false $savedRegion = false;
    private string|false $savedDefaultRegion = false;

    protected function setUp(): void
    {
        $this->handler = new MockHandler();
        $this->sent = [];
        $this->built = 0;
        $this->savedRegion = getenv('AWS_REGION');
        $this->savedDefaultRegion = getenv('AWS_DEFAULT_REGION');
    }

    protected function tearDown(): void
    {
        unset($_SERVER['ARCO'], $_SERVER['ARCO_TENANT_DOMAINS_FUNCTION'], $_SERVER['AWS_REGION'], $_SERVER['AWS_DEFAULT_REGION']);
        foreach (['AWS_REGION' => $this->savedRegion, 'AWS_DEFAULT_REGION' => $this->savedDefaultRegion] as $name => $value) {
            $value === false ? putenv($name) : putenv("$name=$value");
        }
    }

    private function service(?string $function = 'shop-prod-tenant-domains'): LambdaTenantDomains
    {
        return new LambdaTenantDomains(function (): LambdaClient {
            $this->built++;
            return new LambdaClient([
                'version' => 'latest',
                'region' => 'us-east-1',
                'credentials' => false,
                'validate' => false,
                'retries' => 0,
                'handler' => function (CommandInterface $command, RequestInterface $request) {
                    $this->sent[] = $command->toArray();
                    return ($this->handler)($command, $request);
                },
            ]);
        }, $function);
    }

    private function onArco(): void
    {
        $_SERVER['ARCO'] = '1';
    }

    /** @param array<mixed>|string $document */
    private function answer(array|string $document, ?string $functionError = null): void
    {
        $this->handler->append(new Result([
            'StatusCode' => 200,
            'Payload' => Utils::streamFor(is_string($document) ? $document : json_encode($document, JSON_THROW_ON_ERROR)),
            ...($functionError !== null ? ['FunctionError' => $functionError] : []),
        ]));
    }

    private function answerError(string $code, string $message = 'm', ?string $expected = null): void
    {
        $this->answer(['ok' => false, 'error' => ['code' => $code, 'message' => $message, ...($expected !== null ? ['expected' => $expected] : [])]]);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        self::assertCount(1, $this->sent);
        $payload = $this->sent[0]['Payload'];
        self::assertIsString($payload);
        $decoded = json_decode($payload, true);
        self::assertIsArray($decoded);
        return $decoded;
    }

    public function test_the_interface_is_bound_outside_arco_and_every_method_is_unavailable_without_building_a_client(): void
    {
        unset($_SERVER['ARCO']);
        $app = new Application(sys_get_temp_dir());
        (new ArcoServiceProvider($app))->register();
        $service = $app->make(TenantDomains::class);
        self::assertInstanceOf(LambdaTenantDomains::class, $service);

        foreach ([
            fn () => $service->add('a.example.com'),
            fn () => $service->status('a.example.com'),
            fn () => $service->list(),
            fn () => $service->remove('a.example.com'),
        ] as $call) {
            try {
                $call();
                self::fail('Expected TenantDomainsUnavailable');
            } catch (TenantDomainsUnavailable $e) {
                self::assertSame('unavailable', $e->errorCode);
                self::assertNotInstanceOf(TenantDomainNotFound::class, $e);
            }
        }
    }

    public function test_the_binding_is_a_lazy_singleton(): void
    {
        $app = new Application(sys_get_temp_dir());
        (new ArcoServiceProvider($app))->register();
        self::assertSame($app->make(TenantDomains::class), $app->make(TenantDomains::class));
    }

    public function test_outside_arco_the_factory_is_never_called(): void
    {
        unset($_SERVER['ARCO']);
        $service = $this->service();
        $this->expectException(TenantDomainsUnavailable::class);
        try {
            $service->add('a.example.com');
        } finally {
            self::assertSame(0, $this->built);
        }
    }

    public function test_on_arco_without_a_function_the_factory_is_never_called(): void
    {
        $this->onArco();
        foreach ([null, ''] as $function) {
            $service = $this->service($function);
            try {
                $service->status('a.example.com');
                self::fail('Expected TenantDomainsUnavailable');
            } catch (TenantDomainsUnavailable $e) {
                self::assertStringContainsString('tenantDomains()', $e->getMessage());
            }
        }
        self::assertSame(0, $this->built);
    }

    public function test_the_provider_reads_the_function_from_the_environment(): void
    {
        $this->onArco();
        $_SERVER['ARCO_TENANT_DOMAINS_FUNCTION'] = 'fn-from-env';
        $app = new Application(sys_get_temp_dir());
        (new ArcoServiceProvider($app))->register();
        $service = $app->make(TenantDomains::class);
        // The real client would reach the network, so only the binding is checked here.
        self::assertInstanceOf(LambdaTenantDomains::class, $service);
    }

    public function test_without_a_region_the_provider_service_is_unavailable_and_invokes_nothing(): void
    {
        $this->onArco();
        $_SERVER['ARCO_TENANT_DOMAINS_FUNCTION'] = 'fn-from-env';
        unset($_SERVER['AWS_REGION'], $_SERVER['AWS_DEFAULT_REGION'], $_ENV['AWS_REGION'], $_ENV['AWS_DEFAULT_REGION']);
        putenv('AWS_REGION');
        putenv('AWS_DEFAULT_REGION');
        $app = new Application(sys_get_temp_dir());
        (new ArcoServiceProvider($app))->register();

        try {
            $app->make(TenantDomains::class)->add('a.example.com');
            self::fail('Expected TenantDomainsUnavailable');
        } catch (TenantDomainsUnavailable $e) {
            self::assertStringContainsString('AWS_REGION', $e->getMessage());
        }
    }

    public function test_add_sends_the_payload_and_maps_the_status(): void
    {
        $this->onArco();
        $this->answer(['ok' => true, 'result' => ['domain' => 'shop.customer.com', 'state' => 'pending', 'reason' => null]]);

        $status = $this->service()->add('shop.customer.com');

        self::assertSame('shop-prod-tenant-domains', $this->sent[0]['FunctionName']);
        self::assertSame('RequestResponse', $this->sent[0]['InvocationType']);
        self::assertSame(['operation' => 'add', 'domain' => 'shop.customer.com'], $this->payload());
        self::assertEquals(new TenantDomainStatus('shop.customer.com', 'pending', null), $status);
        self::assertTrue($status->isPending());
    }

    public function test_status_maps_active_and_failed(): void
    {
        $this->onArco();
        $this->answer(['ok' => true, 'result' => ['domain' => 'a.example.com', 'state' => 'active', 'reason' => null]]);
        $this->answer(['ok' => true, 'result' => ['domain' => 'b.example.com', 'state' => 'failed', 'reason' => 'no cert']]);
        $service = $this->service();

        self::assertTrue($service->status('a.example.com')->isActive());
        self::assertSame(['operation' => 'status', 'domain' => 'a.example.com'], json_decode((string) $this->sent[0]['Payload'], true));
        $failed = $service->status('b.example.com');
        self::assertTrue($failed->isFailed());
        self::assertSame('no cert', $failed->reason);
    }

    public function test_list_sends_cursor_and_limit_and_maps_pages(): void
    {
        $this->onArco();
        $this->answer(['ok' => true, 'result' => ['domains' => [
            ['domain' => 'a.example.com', 'state' => 'active', 'reason' => null],
            ['domain' => 'b.example.com', 'state' => 'pending', 'reason' => null],
        ], 'cursor' => 'next-1']]);
        $this->answer(['ok' => true, 'result' => ['domains' => [], 'cursor' => 'next-2']]);
        $this->answer(['ok' => true, 'result' => ['domains' => [['domain' => 'c.example.com', 'state' => 'failed', 'reason' => 'x']], 'cursor' => null]]);
        $service = $this->service();

        $first = $service->list(null, 2);
        self::assertSame(['operation' => 'list', 'cursor' => null, 'limit' => 2], json_decode((string) $this->sent[0]['Payload'], true));
        self::assertCount(2, $first->domains);
        self::assertSame('next-1', $first->cursor);

        $empty = $service->list('next-1', 2);
        self::assertSame(['operation' => 'list', 'cursor' => 'next-1', 'limit' => 2], json_decode((string) $this->sent[1]['Payload'], true));
        self::assertSame([], $empty->domains);
        self::assertSame('next-2', $empty->cursor);

        $last = $service->list('next-2');
        self::assertSame(50, json_decode((string) $this->sent[2]['Payload'], true)['limit']);
        self::assertCount(1, $last->domains);
        self::assertNull($last->cursor);
    }

    public function test_remove_returns_nothing(): void
    {
        $this->onArco();
        $this->answer(['ok' => true, 'result' => ['removed' => true]]);

        $this->service()->remove('a.example.com');

        self::assertSame(['operation' => 'remove', 'domain' => 'a.example.com'], $this->payload());
    }

    /** @return iterable<string, array{string, class-string<TenantDomainException>}> */
    public static function errorCodes(): iterable
    {
        foreach (['invalid_domain', 'apex_not_supported', 'reserved_domain', 'invalid_request'] as $code) {
            yield $code => [$code, InvalidTenantDomain::class];
        }
        yield 'dns_incorrect' => ['dns_incorrect', TenantDomainDnsIncorrect::class];
        yield 'domain_taken' => ['domain_taken', TenantDomainTaken::class];
        yield 'quota' => ['quota', TenantDomainQuotaExceeded::class];
        yield 'not_found' => ['not_found', TenantDomainNotFound::class];
        yield 'removal_pending' => ['removal_pending', TenantDomainRemovalPending::class];
        yield 'unavailable' => ['unavailable', TenantDomainsUnavailable::class];
        foreach (['ownership_unverified', 'conflict', 'failed', 'something_new'] as $code) {
            yield $code => [$code, TenantDomainException::class];
        }
    }

    /** @param class-string<TenantDomainException> $class */
    #[\PHPUnit\Framework\Attributes\DataProvider('errorCodes')]
    public function test_each_error_code_maps_to_its_exception(string $code, string $class): void
    {
        $this->onArco();
        $this->answerError($code, 'explained', 'target.arco.example');

        try {
            $this->service()->add('a.example.com');
            self::fail('Expected an exception');
        } catch (TenantDomainException $e) {
            self::assertSame($class, $e::class);
            self::assertSame($code, $e->errorCode);
            self::assertSame('explained', $e->getMessage());
            if ($e instanceof TenantDomainDnsIncorrect) {
                self::assertSame('target.arco.example', $e->expected);
            }
        }
    }

    public function test_dns_incorrect_without_expected(): void
    {
        $this->onArco();
        $this->answerError('dns_incorrect');
        try {
            $this->service()->add('a.example.com');
            self::fail('Expected an exception');
        } catch (TenantDomainDnsIncorrect $e) {
            self::assertNull($e->expected);
        }
    }

    /** @return iterable<string, array{array<mixed>|string, 3?: ?string}> */
    public static function malformedAnswers(): iterable
    {
        yield 'invalid json' => ['not json'];
        yield 'json scalar' => ['"ok"'];
        yield 'missing ok' => [['result' => []]];
        yield 'ok wrong type' => [['ok' => 'yes', 'result' => []]];
        yield 'missing result' => [['ok' => true]];
        yield 'result wrong type' => [['ok' => true, 'result' => 'x']];
        yield 'unknown state' => [['ok' => true, 'result' => ['domain' => 'a.example.com', 'state' => 'weird', 'reason' => null]]];
        yield 'missing domain' => [['ok' => true, 'result' => ['state' => 'active', 'reason' => null]]];
        yield 'reason wrong type' => [['ok' => true, 'result' => ['domain' => 'a.example.com', 'state' => 'active', 'reason' => 3]]];
        yield 'error without code' => [['ok' => false, 'error' => ['message' => 'x']]];
        yield 'error missing' => [['ok' => false]];
    }

    /** @param array<mixed>|string $document */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedAnswers')]
    public function test_a_malformed_answer_is_failed_never_success(array|string $document): void
    {
        $this->onArco();
        $this->answer($document);
        try {
            $this->service()->add('a.example.com');
            self::fail('Expected an exception');
        } catch (TenantDomainException $e) {
            self::assertSame(TenantDomainException::class, $e::class);
            self::assertSame('failed', $e->errorCode);
        }
    }

    public function test_malformed_list_and_remove_answers_are_failed(): void
    {
        $this->onArco();
        $this->answer(['ok' => true, 'result' => ['domains' => 'x', 'cursor' => null]]);
        $this->answer(['ok' => true, 'result' => ['domains' => []]]);
        $this->answer(['ok' => true, 'result' => ['domains' => [['domain' => 'a']], 'cursor' => null]]);
        $this->answer(['ok' => true, 'result' => ['removed' => false]]);
        $service = $this->service();
        foreach ([fn () => $service->list(), fn () => $service->list(), fn () => $service->list(), fn () => $service->remove('a.example.com')] as $call) {
            try {
                $call();
                self::fail('Expected an exception');
            } catch (TenantDomainException $e) {
                self::assertSame('failed', $e->errorCode);
            }
        }
    }

    public function test_a_function_error_is_failed(): void
    {
        $this->onArco();
        $this->answer(['ok' => true, 'result' => ['domain' => 'a.example.com', 'state' => 'active', 'reason' => null]], 'Unhandled');
        try {
            $this->service()->add('a.example.com');
            self::fail('Expected an exception');
        } catch (TenantDomainException $e) {
            self::assertSame(TenantDomainException::class, $e::class);
            self::assertSame('failed', $e->errorCode);
        }
    }

    private static function awsError(string $code): AwsException
    {
        return new AwsException('boom', new \Aws\Command('Invoke'), ['code' => $code, 'message' => 'boom']);
    }

    public function test_access_denied_names_the_missing_permission(): void
    {
        $this->onArco();
        $this->handler->append(self::awsError('AccessDeniedException'));
        try {
            $this->service()->add('a.example.com');
            self::fail('Expected an exception');
        } catch (TenantDomainsUnavailable $e) {
            self::assertStringContainsString('lambda:InvokeFunction', $e->getMessage());
            self::assertStringContainsString('shop-prod-tenant-domains', $e->getMessage());
            self::assertInstanceOf(AwsException::class, $e->getPrevious());
        }
    }

    public function test_another_aws_error_is_failed_with_the_previous_attached(): void
    {
        $this->onArco();
        $this->handler->append(self::awsError('TooManyRequestsException'));
        try {
            $this->service()->status('a.example.com');
            self::fail('Expected an exception');
        } catch (TenantDomainException $e) {
            self::assertSame(TenantDomainException::class, $e::class);
            self::assertSame('failed', $e->errorCode);
            self::assertInstanceOf(AwsException::class, $e->getPrevious());
        }
    }

    public function test_a_limit_out_of_range_is_rejected_before_invoking(): void
    {
        $this->onArco();
        $service = $this->service();
        foreach ([0, -1, 101] as $limit) {
            try {
                $service->list(null, $limit);
                self::fail('Expected an exception');
            } catch (InvalidTenantDomain $e) {
                self::assertSame('invalid_request', $e->errorCode);
            }
        }
        self::assertSame([], $this->sent);
        self::assertSame(0, $this->built);
    }
}
