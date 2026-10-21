<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy\Domains;

use Aws\CloudFormation\Exception\CloudFormationException;
use Aws\Result;
use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Config\Environment;
use Multiapi\Arco\Deploy\DeployException;
use Multiapi\Arco\Deploy\Domains\Certificates;
use Multiapi\Arco\Deploy\Domains\CustomDomains;
use Multiapi\Arco\Deploy\Domains\DnsProvider;
use Multiapi\Arco\Deploy\Domains\DnsRecord;
use Multiapi\Arco\Deploy\Domains\HostedZone;
use Multiapi\Arco\Deploy\Domains\Hostname;
use Multiapi\Arco\Deploy\Domains\Stacks;
use Multiapi\Arco\Tests\Unit\Deploy\AwsMocks;
use PHPUnit\Framework\TestCase;

/** CustomDomains drives any DnsProvider: what it reads, guards, writes and removes goes through the interface. */
final class DnsProviderSeamTest extends TestCase
{
    private const DIST = 'd111.cloudfront.net';

    private AwsMocks $aws;

    protected function setUp(): void
    {
        $this->aws = new AwsMocks();
    }

    private function config(): AppConfig
    {
        return ConfigResolver::resolve(App::make('shop')->region('us-east-1')->environment('production', function (Environment $e): void {
            $e->domain(['arco.acmeapp.io', 'api-arco.acmeapp.io']);
        })->toArray(), 'production');
    }

    private function domains(DnsProvider $dns, bool $force = false): CustomDomains
    {
        $clients = $this->aws->clients();
        return new CustomDomains(new Certificates($clients->acm(), AwsMocks::noSleep()), new Stacks($clients->cloudFormation(), AwsMocks::noSleep(), 'domains.stack_'), $dns, $force);
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

    /** @return list<Result> a wildcard certificate in ACM that covers both domains */
    private static function wildcard(): array
    {
        return [
            new Result(['CertificateSummaryList' => [
                ['CertificateArn' => 'arn:wildcard', 'DomainName' => '*.acmeapp.io', 'SubjectAlternativeNameSummaries' => ['*.acmeapp.io'], 'HasAdditionalSubjectAlternativeNames' => false, 'Status' => 'ISSUED', 'Type' => 'AMAZON_ISSUED', 'KeyAlgorithm' => 'RSA-2048'],
            ]]),
            new Result(['Certificate' => ['CertificateArn' => 'arn:wildcard', 'DomainName' => '*.acmeapp.io', 'SubjectAlternativeNames' => ['*.acmeapp.io'], 'Status' => 'ISSUED', 'Type' => 'AMAZON_ISSUED', 'KeyAlgorithm' => 'RSA-2048']]),
            new Result(['Tags' => []]),
        ];
    }

    public function test_deploy_writes_the_providers_serving_records_marked_for_the_environment(): void
    {
        $dns = new FakeMarkedDns(['arco.acmeapp.io' => [['name' => 'arco.acmeapp.io', 'type' => 'CNAME', 'content' => 'old.example.com', 'comment' => 'managed by arco: shop/production']]]);
        $this->aws->handler->append(self::stackMissing(), ...self::wildcard());
        $this->aws->handler->append(self::stackMissing(), new Result([]), self::stack('CREATE_COMPLETE'));

        $domains = $this->domains($dns)->deploy($this->config(), 'abc.on.aws', [], [], fn () => null);

        self::assertSame(['arco.acmeapp.io', 'api-arco.acmeapp.io'], $domains);
        self::assertCount(1, $dns->applied, 'one change per zone');
        [$zone, $deletes, $upserts, $owner] = $dns->applied[0];
        self::assertSame('Z1', $zone);
        self::assertSame([['name' => 'arco.acmeapp.io', 'type' => 'CNAME', 'content' => 'old.example.com', 'comment' => 'managed by arco: shop/production']], $deletes, 'our own record is replaced');
        self::assertSame(['arco.acmeapp.io CNAME d111.cloudfront.net', 'api-arco.acmeapp.io CNAME d111.cloudfront.net'], array_map(fn (DnsRecord $r) => "$r->name $r->type $r->value", $upserts));
        self::assertSame('shop/production', $owner);
    }

    public function test_a_record_without_the_marker_is_a_conflict_described_by_the_provider(): void
    {
        $dns = new FakeMarkedDns(['api-arco.acmeapp.io' => [['name' => 'api-arco.acmeapp.io', 'type' => 'A', 'content' => '1.2.3.4']]]);
        $this->aws->handler->append(self::stackMissing());

        try {
            $this->domains($dns)->deploy($this->config(), 'abc.on.aws', [], [], fn () => null);
            self::fail('Expected a conflict.');
        } catch (DeployException $e) {
            self::assertSame('domains.record_conflict', $e->key);
            self::assertSame('  api-arco.acmeapp.io A → 1.2.3.4', $e->params['records']);
        }
        self::assertSame([], $dns->applied);
    }

    public function test_remove_deletes_only_what_the_provider_says_is_removable(): void
    {
        $ours = ['name' => 'arco.acmeapp.io', 'type' => 'CNAME', 'content' => self::DIST, 'comment' => 'managed by arco: shop/production'];
        $dns = new FakeMarkedDns(['arco.acmeapp.io' => [$ours, ['name' => 'arco.acmeapp.io', 'type' => 'TXT', 'content' => 'x']]]);
        $this->aws->handler->append(self::stack('CREATE_COMPLETE'), self::stack('CREATE_COMPLETE'), new Result([]), self::stackMissing(), new Result(['CertificateSummaryList' => []]));

        $this->domains($dns)->remove($this->config(), fn () => null);

        self::assertSame([['Z1', [$ours], [], 'shop/production']], $dns->applied);
    }
}

/** A provider with Cloudflare's shape: CNAMEs to the distribution, ownership by a marker on the record. */
final class FakeMarkedDns implements DnsProvider
{
    /** @var list<array{0: string, 1: list<array<string, mixed>>, 2: list<DnsRecord>, 3: string}> zone id, deletes, upserts, owner */
    public array $applied = [];

    /** @param array<string, list<array<string, mixed>>> $records by name */
    public function __construct(private readonly array $records)
    {
    }

    public function zoneFor(string $name): ?HostedZone
    {
        return new HostedZone('Z1', 'acmeapp.io', false);
    }

    public function records(HostedZone $zone, string $name): array
    {
        return $this->records[Hostname::normalize($name)] ?? [];
    }

    public function servingRecords(string $domain, string $distributionDomain): array
    {
        return [DnsRecord::cname($domain, $distributionDomain)];
    }

    public function plan(array $records, string $domain, ?string $distributionDomain, bool $force, AppConfig $owner): array
    {
        // Only address records can stand in the way of the serving CNAME: TXT, MX and the like stay.
        $address = array_values(array_filter($records, fn (array $r) => in_array($r['type'] ?? null, ['A', 'AAAA', 'CNAME'], true)));
        $conflicts = array_values(array_filter($address, fn (array $r) => ! self::owned($r, $owner)));
        $deletes = array_values(array_filter($address, fn (array $r) => $force || self::owned($r, $owner)));
        return ['conflicts' => $conflicts, 'deletes' => $deletes];
    }

    public function removable(array $records, string $domain, string $distributionDomain, AppConfig $owner): array
    {
        return array_values(array_filter($records, fn (array $r) => self::owned($r, $owner) && ($r['content'] ?? null) === $distributionDomain));
    }

    public function isSatisfied(DnsRecord $record, array $existing, AppConfig $owner): bool
    {
        return self::owned($existing, $owner) && ($existing['content'] ?? null) === $record->value;
    }

    public function describe(array $record): string
    {
        return self::string($record['name'] ?? null) . ' ' . self::string($record['type'] ?? null) . ' → ' . self::string($record['content'] ?? null);
    }

    public function apply(HostedZone $zone, array $deletes, array $upserts, AppConfig $owner): void
    {
        $this->applied[] = [$zone->id, $deletes, $upserts, "$owner->app/$owner->environment"];
    }

    /** @param array<string, mixed> $record */
    private static function owned(array $record, AppConfig $owner): bool
    {
        return ($record['comment'] ?? null) === "managed by arco: $owner->app/$owner->environment" && in_array($record['type'] ?? null, ['A', 'AAAA', 'CNAME'], true);
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
