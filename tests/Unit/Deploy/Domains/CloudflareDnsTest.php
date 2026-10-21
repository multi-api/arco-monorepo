<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy\Domains;

use Aws\CloudFormation\Exception\CloudFormationException;
use Aws\Result;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Config\Environment;
use Multiapi\Arco\Deploy\DeployException;
use Multiapi\Arco\Deploy\Domains\CloudflareApi;
use Multiapi\Arco\Deploy\Domains\CloudflareDns;
use Multiapi\Arco\Deploy\Domains\CustomDomains;
use Multiapi\Arco\Tests\Unit\Deploy\AwsMocks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class CloudflareDnsTest extends TestCase
{
    private const ENDPOINT = 'abc.xpt.lambda-web.us-east-1.on.aws';
    private const DIST = 'd111.cloudfront.net';
    private const TAGS = ['arco:app' => 'shop', 'arco:environment' => 'production'];
    private const MARKER = 'managed by arco: shop/production';
    private const ZONE = 'zone-acme';

    private AwsMocks $aws;
    /** Route 53 of the account the environment used before switching to Cloudflare. */
    private AwsMocks $route53;
    private FakeCloudflare $cf;

    protected function setUp(): void
    {
        $this->aws = new AwsMocks();
        $this->route53 = new AwsMocks();
        $this->cf = (new FakeCloudflare())->zone('acmeapp.io', self::ZONE);
    }

    protected function assertPostConditions(): void
    {
        self::assertCount(0, $this->aws->handler, 'every queued deploy-account response is used');
        self::assertSame([], $this->route53->calls, 'a Cloudflare environment never calls Route 53');
    }

    private function config(): AppConfig
    {
        return ConfigResolver::resolve(App::make('shop')->region('us-east-1')->environment('production', function (Environment $e): void {
            $e->domain(['arco.acmeapp.io', 'api-arco.acmeapp.io'])->dns('cloudflare');
        })->toArray(), 'production');
    }

    private function domains(bool $force = false, bool $withProvider = true): CustomDomains
    {
        return CustomDomains::create($this->config(), $this->aws->clients(), $this->route53->clients(), AwsMocks::noSleep(), $force, $withProvider ? new CloudflareDns($this->cf->api(), $force) : null);
    }

    private function deploy(bool $force = false): void
    {
        $this->domains($force)->deploy($this->config(), self::ENDPOINT, [], self::TAGS, fn () => null);
    }

    private static function stackMissing(): CloudFormationException
    {
        return AwsMocks::error(CloudFormationException::class, 'DescribeStacks', 'ValidationError', 400, 'Stack with id arco-shop-production does not exist');
    }

    private static function stack(string $status): Result
    {
        return new Result(['Stacks' => [[
            'StackName' => 'arco-shop-production',
            'StackStatus' => $status,
            'Tags' => [['Key' => 'arco:app', 'Value' => 'shop'], ['Key' => 'arco:environment', 'Value' => 'production']],
            'Outputs' => [
                ['OutputKey' => 'DistributionDomain', 'OutputValue' => self::DIST],
                ['OutputKey' => 'Aliases', 'OutputValue' => 'arco.acmeapp.io,api-arco.acmeapp.io'],
            ],
        ]]]);
    }

    /**
     * A deploy that requests a certificate, validates it through DNS and creates the distribution; with
     * $complete false, only up to the validation records.
     */
    private function queueCertificateRequestAndStack(bool $complete = true): void
    {
        $pending = fn (bool $records) => new Result(['Certificate' => [
            'CertificateArn' => 'arn:new', 'DomainName' => 'arco.acmeapp.io', 'SubjectAlternativeNames' => ['arco.acmeapp.io', 'api-arco.acmeapp.io'], 'Status' => 'PENDING_VALIDATION',
            'DomainValidationOptions' => [
                ['DomainName' => 'arco.acmeapp.io', 'ValidationStatus' => 'PENDING_VALIDATION'] + ($records ? ['ResourceRecord' => ['Name' => '_a.arco.acmeapp.io.', 'Type' => 'CNAME', 'Value' => '_x.acm-validations.aws.']] : []),
                ['DomainName' => 'api-arco.acmeapp.io', 'ValidationStatus' => 'PENDING_VALIDATION'] + ($records ? ['ResourceRecord' => ['Name' => '_b.api-arco.acmeapp.io.', 'Type' => 'CNAME', 'Value' => '_y.acm-validations.aws.']] : []),
            ],
        ]]);
        $this->aws->handler->append(
            self::stackMissing(),
            new Result(['CertificateSummaryList' => []]),
            new Result(['CertificateArn' => 'arn:new']),
            $pending(false),
            $pending(true),
        );
        if (! $complete) {
            return;
        }
        $this->aws->handler->append(
            $pending(true),
            new Result(['Certificate' => ['CertificateArn' => 'arn:new', 'Status' => 'ISSUED', 'Type' => 'AMAZON_ISSUED', 'KeyAlgorithm' => 'RSA-2048']]),
            self::stackMissing(), new Result([]), self::stack('CREATE_COMPLETE'),
        );
    }

    /** An issued wildcard certificate (no validation records) and an existing distribution. */
    private function queueWildcardAndStack(): void
    {
        $this->aws->handler->append(
            self::stack('CREATE_COMPLETE'),
            new Result(['CertificateSummaryList' => [
                ['CertificateArn' => 'arn:wildcard', 'DomainName' => '*.acmeapp.io', 'SubjectAlternativeNameSummaries' => ['*.acmeapp.io'], 'HasAdditionalSubjectAlternativeNames' => false, 'Status' => 'ISSUED', 'Type' => 'AMAZON_ISSUED', 'KeyAlgorithm' => 'RSA-2048'],
            ]]),
            new Result(['Certificate' => ['CertificateArn' => 'arn:wildcard', 'DomainName' => '*.acmeapp.io', 'SubjectAlternativeNames' => ['*.acmeapp.io'], 'Status' => 'ISSUED', 'Type' => 'AMAZON_ISSUED', 'KeyAlgorithm' => 'RSA-2048']]),
            new Result(['Tags' => []]),
            self::stack('CREATE_COMPLETE'),
            new Result([]),
            self::stack('UPDATE_COMPLETE'),
        );
    }

    /** @return array<string, mixed> the record's fields Arco writes */
    private static function written(array $record): array
    {
        $fields = [];
        foreach (['name', 'type', 'content', 'proxied', 'comment'] as $key) {
            $fields[$key] = $record[$key] ?? null;
        }
        return $fields;
    }

    public function test_the_zone_is_the_longest_active_suffix_and_lookups_are_cached(): void
    {
        $this->cf->zone('shop.acmeapp.io', 'zone-shop');
        $dns = new CloudflareDns($this->cf->api());

        self::assertSame('zone-shop', $dns->zoneFor('www.shop.acmeapp.io')?->id);
        self::assertSame('shop.acmeapp.io', $dns->zoneFor('Shop.AcmeApp.io.')?->name);
        self::assertSame(self::ZONE, $dns->zoneFor('api.acmeapp.io')?->id);
        self::assertNull($dns->zoneFor('example.org'));
        self::assertFalse($dns->zoneFor('arco.acmeapp.io')?->private);

        self::assertSame([
            'GET zones?name=www.shop.acmeapp.io&status=active&per_page=5',
            'GET zones?name=shop.acmeapp.io&status=active&per_page=5',
            'GET zones?name=api.acmeapp.io&status=active&per_page=5',
            'GET zones?name=acmeapp.io&status=active&per_page=5',
            'GET zones?name=example.org&status=active&per_page=5',
            'GET zones?name=arco.acmeapp.io&status=active&per_page=5',
        ], $this->cf->log, 'one lookup per candidate name, longest first; the TLD alone is never asked');
        self::assertSame(['Bearer ' . FakeCloudflare::TOKEN], array_values(array_unique($this->cf->auth)));
    }

    public function test_deploy_writes_validation_cnames_then_the_serving_cnames_dns_only_and_marked(): void
    {
        $this->queueCertificateRequestAndStack();
        // The environment's zone is still in the Route 53 account it used before: untouched.
        $this->cf->record(self::ZONE, 'arco.acmeapp.io', 'TXT', 'v=spf1 -all');

        $this->deploy();

        self::assertSame([
            ['name' => '_a.arco.acmeapp.io', 'type' => 'CNAME', 'content' => '_x.acm-validations.aws', 'proxied' => false, 'comment' => 'managed by arco'],
        ], array_map(self::written(...), $this->cf->named('_a.arco.acmeapp.io')));
        self::assertSame([
            ['name' => '_b.api-arco.acmeapp.io', 'type' => 'CNAME', 'content' => '_y.acm-validations.aws', 'proxied' => false, 'comment' => 'managed by arco'],
        ], array_map(self::written(...), $this->cf->named('_b.api-arco.acmeapp.io')));
        self::assertSame([
            ['name' => 'arco.acmeapp.io', 'type' => 'TXT', 'content' => 'v=spf1 -all', 'proxied' => false, 'comment' => null],
            ['name' => 'arco.acmeapp.io', 'type' => 'CNAME', 'content' => self::DIST, 'proxied' => false, 'comment' => self::MARKER],
        ], array_map(self::written(...), $this->cf->named('arco.acmeapp.io')), 'the TXT record is not an address record: it stays');
        self::assertSame([
            ['name' => 'api-arco.acmeapp.io', 'type' => 'CNAME', 'content' => self::DIST, 'proxied' => false, 'comment' => self::MARKER],
        ], array_map(self::written(...), $this->cf->named('api-arco.acmeapp.io')));
        self::assertSame([
            'POST zones/zone-acme/dns_records',
            'POST zones/zone-acme/dns_records',
            'POST zones/zone-acme/dns_records',
            'POST zones/zone-acme/dns_records',
        ], $this->cf->writes());
        self::assertSame(1, $this->cf->bodies[array_search('POST zones/zone-acme/dns_records', $this->cf->log, true)]['ttl'] ?? null);
        self::assertNotContains('ListHostedZonesByName', $this->aws->calls);
    }

    public function test_a_redeploy_with_the_records_in_place_writes_nothing(): void
    {
        $this->queueWildcardAndStack();
        $this->cf->record(self::ZONE, 'arco.acmeapp.io', 'CNAME', self::DIST, self::MARKER);
        $this->cf->record(self::ZONE, 'api-arco.acmeapp.io', 'CNAME', self::DIST, self::MARKER);

        $this->deploy();

        self::assertSame([], $this->cf->writes());
    }

    public function test_an_owned_cname_pointing_elsewhere_is_updated_in_place_by_id(): void
    {
        $this->queueWildcardAndStack();
        $id = $this->cf->record(self::ZONE, 'arco.acmeapp.io', 'CNAME', 'd000.cloudfront.net', self::MARKER);
        $this->cf->record(self::ZONE, 'api-arco.acmeapp.io', 'CNAME', self::DIST, self::MARKER);

        $this->deploy();

        self::assertSame(["PATCH zones/zone-acme/dns_records/$id"], $this->cf->writes());
        self::assertSame(self::DIST, $this->cf->records[$id]['content']);
    }

    public function test_a_live_record_without_the_marker_stops_the_deploy_before_anything_is_created(): void
    {
        $this->aws->handler->append(self::stackMissing());
        $this->cf->record(self::ZONE, 'arco.acmeapp.io', 'A', '192.0.2.10');
        $this->cf->record(self::ZONE, 'api-arco.acmeapp.io', 'CNAME', self::DIST, 'managed by arco: shop/staging');

        try {
            $this->deploy();
            self::fail('expected a conflict');
        } catch (DeployException $e) {
            self::assertSame('domains.record_conflict', $e->key);
            self::assertSame("  arco.acmeapp.io A → 192.0.2.10\n  api-arco.acmeapp.io CNAME → " . self::DIST, $e->params['records']);
        }
        self::assertSame(['DescribeStacks'], $this->aws->calls, 'no certificate, no distribution');
        self::assertSame([], $this->cf->writes());
    }

    public function test_force_dns_replaces_foreign_records_and_takes_over_a_cname_by_id(): void
    {
        $this->queueWildcardAndStack();
        $a = $this->cf->record(self::ZONE, 'arco.acmeapp.io', 'A', '192.0.2.10');
        $aaaa = $this->cf->record(self::ZONE, 'arco.acmeapp.io', 'AAAA', '2001:db8::1');
        $cname = $this->cf->record(self::ZONE, 'api-arco.acmeapp.io', 'CNAME', 'old.example.net', null, ['proxied' => true]);

        $this->deploy(force: true);

        self::assertSame([
            "DELETE zones/zone-acme/dns_records/$a",
            "DELETE zones/zone-acme/dns_records/$aaaa",
            'POST zones/zone-acme/dns_records',
            "PATCH zones/zone-acme/dns_records/$cname",
        ], $this->cf->writes());
        self::assertSame(['name' => 'api-arco.acmeapp.io', 'type' => 'CNAME', 'content' => self::DIST, 'proxied' => false, 'comment' => self::MARKER], self::written($this->cf->records[$cname]));
    }

    public function test_an_identical_validation_cname_is_left_alone_whatever_its_comment(): void
    {
        $this->queueCertificateRequestAndStack();
        $this->cf->record(self::ZONE, '_a.arco.acmeapp.io', 'CNAME', '_x.acm-validations.aws', 'managed by arco: shop/staging');
        $this->cf->record(self::ZONE, '_b.api-arco.acmeapp.io', 'CNAME', '_y.acm-validations.aws');

        $this->deploy();

        self::assertSame(['POST zones/zone-acme/dns_records', 'POST zones/zone-acme/dns_records'], $this->cf->writes(), 'only the two serving CNAMEs');
        self::assertSame('managed by arco: shop/staging', $this->cf->named('_a.arco.acmeapp.io')[0]['comment']);
    }

    public function test_a_different_record_at_a_validation_name_is_refused(): void
    {
        $this->queueCertificateRequestAndStack(complete: false);
        $this->cf->record(self::ZONE, '_a.arco.acmeapp.io', 'CNAME', '_old.acm-validations.aws');

        try {
            $this->deploy();
            self::fail('expected a validation conflict');
        } catch (DeployException $e) {
            self::assertSame('cloudflare.validation_conflict', $e->key);
            self::assertSame('  _a.arco.acmeapp.io CNAME → _old.acm-validations.aws', $e->params['records']);
        }
        self::assertSame([], $this->cf->writes());
    }

    public function test_force_dns_replaces_a_different_validation_cname_by_id(): void
    {
        $this->queueCertificateRequestAndStack();
        $id = $this->cf->record(self::ZONE, '_a.arco.acmeapp.io', 'CNAME', '_old.acm-validations.aws');

        $this->deploy(force: true);
        self::assertSame("PATCH zones/zone-acme/dns_records/$id", $this->cf->writes()[0]);
        self::assertSame(['content' => '_x.acm-validations.aws', 'comment' => 'managed by arco'], array_intersect_key($this->cf->records[$id], ['content' => 1, 'comment' => 1]));
    }

    public function test_remove_deletes_only_this_environments_marked_records_and_keeps_validation_cnames(): void
    {
        $this->aws->handler->append(
            self::stack('CREATE_COMPLETE'),
            self::stack('CREATE_COMPLETE'), new Result([]), self::stackMissing(),
            new Result(['CertificateSummaryList' => []]),
        );
        $ours = $this->cf->record(self::ZONE, 'arco.acmeapp.io', 'CNAME', self::DIST, self::MARKER);
        $this->cf->record(self::ZONE, 'arco.acmeapp.io', 'TXT', 'keep', self::MARKER);
        $this->cf->record(self::ZONE, 'api-arco.acmeapp.io', 'CNAME', self::DIST, 'managed by arco: shop/staging');
        $this->cf->record(self::ZONE, '_a.arco.acmeapp.io', 'CNAME', '_x.acm-validations.aws', 'managed by arco');

        $this->domains()->remove($this->config(), fn () => null);

        self::assertSame(["DELETE zones/zone-acme/dns_records/$ours"], $this->cf->writes());
        self::assertCount(3, $this->cf->records);
    }

    public function test_without_a_provider_a_cloudflare_environment_fails_and_never_falls_back_to_route53(): void
    {
        $this->aws->handler->append(self::stackMissing());

        try {
            $this->domains(withProvider: false)->deploy($this->config(), self::ENDPOINT, [], self::TAGS, fn () => null);
            self::fail('expected the missing token');
        } catch (DeployException $e) {
            self::assertSame('cloudflare.token_missing', $e->key);
            self::assertSame(['env' => 'CLOUDFLARE_API_TOKEN', 'permissions' => 'Zone:DNS:Edit + Zone:Read'], $e->params);
        }
        self::assertSame(['DescribeStacks'], $this->aws->calls);
    }

    public function test_verify_refuses_a_domain_without_a_zone_naming_the_token_and_permissions(): void
    {
        try {
            (new CloudflareDns($this->cf->api()))->verify(['arco.acmeapp.io', 'shop.example.org']);
            self::fail('expected a missing zone');
        } catch (DeployException $e) {
            self::assertSame('cloudflare.zone_missing', $e->key);
            self::assertSame(['domains' => 'shop.example.org', 'env' => 'CLOUDFLARE_API_TOKEN', 'permissions' => 'Zone:DNS:Edit + Zone:Read'], $e->params);
        }
        self::assertSame(['acmeapp.io'], (new CloudflareDns($this->cf->api()))->verify(['arco.acmeapp.io', 'api-arco.acmeapp.io']));
    }

    /** @return iterable<string, array{0: int, 1: array<string, mixed>}> */
    public static function rejections(): iterable
    {
        yield '401' => [401, ['success' => false, 'errors' => [['code' => 10000, 'message' => 'Authentication error']]]];
        yield '403' => [403, ['success' => false, 'errors' => [['code' => 10000, 'message' => 'Authentication error']]]];
        yield '400 invalid token' => [400, ['success' => false, 'errors' => [['code' => 9109, 'message' => 'Invalid access token']]]];
    }

    #[DataProvider('rejections')]
    public function test_a_refused_token_names_the_env_var_and_the_permissions(int $status, array $body): void
    {
        $this->cf->queue(FakeCloudflare::json($status, $body));

        try {
            (new CloudflareDns($this->cf->api()))->verify(['arco.acmeapp.io']);
            self::fail('expected a refused token');
        } catch (DeployException $e) {
            self::assertSame('cloudflare.token_rejected', $e->key);
            self::assertSame('CLOUDFLARE_API_TOKEN', $e->params['env']);
            self::assertSame('Zone:DNS:Edit + Zone:Read', $e->params['permissions']);
            self::assertStringContainsString("HTTP $status", (string) $e->params['detail']);
        }
        self::assertCount(1, $this->cf->log, 'never retried');
    }

    public function test_429_and_5xx_are_retried_with_backoff(): void
    {
        $this->cf->queue(
            FakeCloudflare::json(429, ['success' => false, 'errors' => [['code' => 971, 'message' => 'Please wait']]], ['Retry-After' => '7']),
            FakeCloudflare::json(502, ['success' => false]),
            FakeCloudflare::json(503, ['success' => false]),
        );

        self::assertSame(self::ZONE, (new CloudflareDns($this->cf->api()))->zoneFor('acmeapp.io')?->id);

        self::assertCount(4, $this->cf->log);
        self::assertSame(7 + 2 + 4, $this->sleptSeconds(), 'Retry-After, then 2 s and 4 s');
    }

    public function test_it_gives_up_after_a_bounded_number_of_attempts(): void
    {
        $this->cf->queue(...array_fill(0, CloudflareApi::ATTEMPTS, FakeCloudflare::json(500, ['success' => false])));

        try {
            (new CloudflareDns($this->cf->api()))->zoneFor('acmeapp.io');
            self::fail('expected to give up');
        } catch (DeployException $e) {
            self::assertSame('cloudflare.gave_up', $e->key);
            self::assertSame(['call' => 'GET /zones', 'status' => 500, 'attempts' => 4], array_diff_key($e->params, ['detail' => 1]));
        }
        self::assertCount(CloudflareApi::ATTEMPTS, $this->cf->log);
        self::assertSame(1 + 2 + 4, $this->sleptSeconds());
    }

    public function test_a_missing_zone_on_a_record_call_is_a_request_failure(): void
    {
        $dns = new CloudflareDns($this->cf->api());
        $zone = $dns->zoneFor('arco.acmeapp.io');
        self::assertNotNull($zone);
        $this->cf->zones = [];

        try {
            $dns->records($zone, 'arco.acmeapp.io');
            self::fail('expected a 404');
        } catch (DeployException $e) {
            self::assertSame('cloudflare.request_failed', $e->key);
            self::assertSame('GET /zones/zone-acme/dns_records: HTTP 404 7003: Could not route to zone', $e->params['detail']);
        }
    }

    public function test_a_failure_part_way_names_what_was_already_changed(): void
    {
        $this->queueWildcardAndStack();
        $a = $this->cf->record(self::ZONE, 'arco.acmeapp.io', 'A', '192.0.2.10', self::MARKER);
        // Deleting the A record works; creating the first CNAME is refused.
        $fake = $this->cf;
        $api = CloudflareApi::create(FakeCloudflare::TOKEN, AwsMocks::noSleep(), function (RequestInterface $request, array $options) use ($fake) {
            if ($request->getMethod() === 'POST') {
                return Create::promiseFor(FakeCloudflare::json(400, ['success' => false, 'errors' => [['code' => 81053, 'message' => 'An A, AAAA, or CNAME record with that host already exists.']]]));
            }
            return $fake->handler($request, $options);
        });
        $domains = CustomDomains::create($this->config(), $this->aws->clients(), null, AwsMocks::noSleep(), false, new CloudflareDns($api));

        try {
            $domains->deploy($this->config(), self::ENDPOINT, [], self::TAGS, fn () => null);
            self::fail('expected a partial failure');
        } catch (DeployException $e) {
            self::assertSame('cloudflare.partial', $e->key);
            self::assertSame('  deleted arco.acmeapp.io A → 192.0.2.10', $e->params['done']);
            self::assertStringContainsString('81053', (string) $e->params['detail']);
        }
        self::assertArrayNotHasKey($a, $fake->records);
    }

    public function test_the_token_never_appears_in_an_error_even_from_a_guzzle_exception_carrying_it(): void
    {
        $request = new Request('GET', CloudflareApi::BASE_URI . 'zones', ['Authorization' => 'Bearer ' . FakeCloudflare::TOKEN]);
        $this->cf->queue(new RequestException('cURL error 35: TLS failed for request with Authorization: Bearer ' . FakeCloudflare::TOKEN, $request));

        try {
            (new CloudflareDns($this->cf->api()))->verify(['arco.acmeapp.io']);
            self::fail('expected a request failure');
        } catch (DeployException $e) {
            self::assertSame('cloudflare.request_failed', $e->key);
            self::assertStringNotContainsString(FakeCloudflare::TOKEN, $e->getMessage());
            self::assertStringNotContainsString(FakeCloudflare::TOKEN, (string) json_encode($e->params));
            self::assertStringContainsString('[redacted]', (string) $e->params['detail']);
            self::assertNull($e->getPrevious(), 'the Guzzle exception (and its request headers) is not chained');
        }

        $this->cf->queue(new ConnectException('Could not resolve host for Bearer ' . FakeCloudflare::TOKEN, $request));
        try {
            (new CloudflareDns($this->cf->api()))->zoneFor('acmeapp.io');
            self::fail('expected a request failure');
        } catch (DeployException $e) {
            self::assertStringNotContainsString(FakeCloudflare::TOKEN, $e->getMessage());
        }
    }

    private function sleptSeconds(): int
    {
        /** @var object{slept: int} $sleeper */
        $sleeper = $this->cf->sleeper;
        return $sleeper->slept;
    }
}
