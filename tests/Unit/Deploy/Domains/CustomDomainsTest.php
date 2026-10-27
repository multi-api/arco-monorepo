<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy\Domains;

use Aws\Acm\Exception\AcmException;
use Aws\CloudFormation\Exception\CloudFormationException;
use Aws\Result;
use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Config\Environment;
use Multiapi\Arco\Deploy\DeployException;
use Multiapi\Arco\Deploy\Domains\CustomDomains;
use Multiapi\Arco\Tests\Unit\Deploy\AwsMocks;
use PHPUnit\Framework\TestCase;

final class CustomDomainsTest extends TestCase
{
    private const ENDPOINT = 'abc.xpt.lambda-web.us-east-1.on.aws';
    private const DIST = 'd111.cloudfront.net';
    private const TAGS = ['arco:app' => 'shop', 'arco:environment' => 'production'];

    private AwsMocks $aws;
    private AwsMocks $dnsAws;
    /** @var list<array{0: string, 1: array<string, string|int>}> */
    private array $steps = [];

    protected function setUp(): void
    {
        $this->aws = new AwsMocks();
        $this->dnsAws = new AwsMocks();
    }

    protected function assertPostConditions(): void
    {
        self::assertCount(0, $this->aws->handler, 'every queued deploy-account response is used');
        self::assertCount(0, $this->dnsAws->handler, 'every queued DNS-account response is used');
    }

    /** @param callable(Environment): mixed|null $configure */
    private function config(?callable $configure = null): AppConfig
    {
        return ConfigResolver::resolve(App::make('shop')->region('us-east-1')->environment('production', function (Environment $e) use ($configure): void {
            $e->domain(['arco.acmeapp.io', 'api-arco.acmeapp.io']);
            if ($configure !== null) {
                $configure($e);
            }
        })->toArray(), 'production');
    }

    private function domains(AppConfig $config, bool $withDnsAccount = true, bool $force = false): CustomDomains
    {
        return CustomDomains::create($config, $this->aws->clients(), $withDnsAccount ? $this->dnsAws->clients() : null, AwsMocks::noSleep(), $force);
    }

    private function step(): \Closure
    {
        return function (string $key, array $params) { $this->steps[] = [$key, $params]; };
    }

    /** @return list<string> */
    private function stepKeys(): array
    {
        return array_map(fn (array $s) => $s[0], $this->steps);
    }

    private static function stackMissing(): CloudFormationException
    {
        return AwsMocks::error(CloudFormationException::class, 'DescribeStacks', 'ValidationError', 400, 'Stack with id arco-shop-production does not exist');
    }

    private static function stack(string $status, bool $outputs = true): Result
    {
        return new Result(['Stacks' => [[
            'StackName' => 'arco-shop-production',
            'StackStatus' => $status,
            'Tags' => [['Key' => 'arco:app', 'Value' => 'shop'], ['Key' => 'arco:environment', 'Value' => 'production'], ['Key' => 'arco:version', 'Value' => '0.1.0']],
            'Outputs' => $outputs ? [
                ['OutputKey' => 'DistributionId', 'OutputValue' => 'E123'],
                ['OutputKey' => 'DistributionDomain', 'OutputValue' => self::DIST],
                ['OutputKey' => 'Aliases', 'OutputValue' => 'arco.acmeapp.io,api-arco.acmeapp.io'],
            ] : [],
        ]]]);
    }

    /** Appends responses; arrays are flattened. */
    private static function queue(AwsMocks $mocks, mixed ...$responses): void
    {
        foreach ($responses as $response) {
            is_array($response) ? $mocks->handler->append(...$response) : $mocks->handler->append($response);
        }
    }

    private static function zones(): Result
    {
        return new Result(['HostedZones' => [
            ['Id' => '/hostedzone/ZPRIVATE', 'Name' => 'acmeapp.io.', 'Config' => ['PrivateZone' => true]],
            ['Id' => '/hostedzone/ZPUBLIC', 'Name' => 'acmeapp.io.', 'Config' => ['PrivateZone' => false]],
        ], 'IsTruncated' => false]);
    }

    private static function wildcard(): array
    {
        return [
            new Result(['CertificateSummaryList' => [
                ['CertificateArn' => 'arn:other', 'DomainName' => 'other.example.com', 'SubjectAlternativeNameSummaries' => ['other.example.com'], 'HasAdditionalSubjectAlternativeNames' => false, 'Status' => 'ISSUED', 'Type' => 'AMAZON_ISSUED', 'KeyAlgorithm' => 'RSA-2048'],
                ['CertificateArn' => 'arn:wildcard', 'DomainName' => '*.acmeapp.io', 'SubjectAlternativeNameSummaries' => ['*.acmeapp.io', 'acmeapp.io'], 'HasAdditionalSubjectAlternativeNames' => false, 'Status' => 'ISSUED', 'Type' => 'AMAZON_ISSUED', 'KeyAlgorithm' => 'RSA-2048'],
            ]]),
            new Result(['Certificate' => ['CertificateArn' => 'arn:wildcard', 'DomainName' => '*.acmeapp.io', 'SubjectAlternativeNames' => ['*.acmeapp.io', 'acmeapp.io'], 'Status' => 'ISSUED', 'Type' => 'AMAZON_ISSUED', 'KeyAlgorithm' => 'RSA-2048']]),
            new Result(['Tags' => []]),
        ];
    }

    public function test_reuses_a_wildcard_certificate_and_writes_aliases_in_the_dns_account(): void
    {
        self::queue($this->aws, 
            self::stackMissing(),                       // describe before the guard
            self::wildcard(),
            self::stackMissing(),                       // Stacks::deploy
            new Result([]),                             // CreateStack
            self::stack('CREATE_IN_PROGRESS', false),
            self::stack('CREATE_COMPLETE'),
        );
        $this->dnsAws->handler->append(
            self::zones(),
            new Result(['ResourceRecordSets' => [['Name' => 'arco.acmeapp.io.', 'Type' => 'TXT', 'TTL' => 300, 'ResourceRecords' => [['Value' => '"x"']]]]]),
            new Result(['ResourceRecordSets' => [['Name' => 'b.acmeapp.io.', 'Type' => 'A', 'TTL' => 300, 'ResourceRecords' => [['Value' => '1.2.3.4']]]]]),
            new Result(['ResourceRecordSets' => []]),           // fresh guard read before the write
            new Result(['ResourceRecordSets' => []]),
            new Result(['ChangeInfo' => ['Id' => '/change/C1', 'Status' => 'PENDING']]),
            new Result(['ChangeInfo' => ['Id' => '/change/C1', 'Status' => 'PENDING']]),
            new Result(['ChangeInfo' => ['Id' => '/change/C1', 'Status' => 'INSYNC']]),
        );

        $domains = $this->domains($this->config())->deploy($this->config(), self::ENDPOINT, ['/build/*'], self::TAGS, $this->step());

        self::assertSame(['arco.acmeapp.io', 'api-arco.acmeapp.io'], $domains);
        self::assertSame(['DescribeStacks', 'ListCertificates', 'DescribeCertificate', 'ListTagsForCertificate', 'DescribeStacks', 'CreateStack', 'DescribeStacks', 'DescribeStacks'], $this->aws->calls);
        self::assertSame(['ListHostedZonesByName', 'ListResourceRecordSets', 'ListResourceRecordSets', 'ListResourceRecordSets', 'ListResourceRecordSets', 'ChangeResourceRecordSets', 'GetChange', 'GetChange'], $this->dnsAws->calls);
        self::assertSame(['domains.step_certificate', 'domains.certificate_using', 'domains.step_distribution', 'domains.step_dns'], $this->stepKeys());

        $create = $this->aws->params[5];
        self::assertSame('arco-shop-production', $create['StackName']);
        self::assertSame([['Key' => 'arco:app', 'Value' => 'shop'], ['Key' => 'arco:environment', 'Value' => 'production']], $create['Tags']);
        $template = json_decode($create['TemplateBody'], true);
        $distribution = $template['Resources']['Distribution']['Properties']['DistributionConfig'];
        self::assertSame('arn:wildcard', $distribution['ViewerCertificate']['AcmCertificateArn']);
        self::assertSame(self::ENDPOINT, $distribution['Origins'][0]['DomainName']);
        self::assertSame('/build/*', $distribution['CacheBehaviors'][0]['PathPattern']);

        $listRecords = $this->dnsAws->params[1];
        self::assertSame('ZPUBLIC', $listRecords['HostedZoneId'], 'the private zone with the same name is ignored');
        $change = $this->dnsAws->params[5];
        self::assertSame('ZPUBLIC', $change['HostedZoneId']);
        self::assertSame([
            ['UPSERT', 'arco.acmeapp.io', 'A'], ['UPSERT', 'arco.acmeapp.io', 'AAAA'],
            ['UPSERT', 'api-arco.acmeapp.io', 'A'], ['UPSERT', 'api-arco.acmeapp.io', 'AAAA'],
        ], array_map(fn (array $c) => [$c['Action'], $c['ResourceRecordSet']['Name'], $c['ResourceRecordSet']['Type']], $change['ChangeBatch']['Changes']));
        self::assertSame(['HostedZoneId' => 'Z2FDTNDATAQYW2', 'DNSName' => self::DIST, 'EvaluateTargetHealth' => false], $change['ChangeBatch']['Changes'][0]['ResourceRecordSet']['AliasTarget']);
    }

    public function test_redeploy_skips_dns_when_the_aliases_already_point_at_the_distribution(): void
    {
        self::queue($this->aws,
            self::stackMissing(),
            self::wildcard(),
            self::stackMissing(),
            new Result([]),
            self::stack('CREATE_COMPLETE'),
        );
        $aliases = fn (string $name): Result => new Result(['ResourceRecordSets' => [
            ['Name' => "$name.", 'Type' => 'A', 'AliasTarget' => ['HostedZoneId' => 'Z2FDTNDATAQYW2', 'DNSName' => self::DIST . '.', 'EvaluateTargetHealth' => false]],
            ['Name' => "$name.", 'Type' => 'AAAA', 'AliasTarget' => ['HostedZoneId' => 'Z2FDTNDATAQYW2', 'DNSName' => self::DIST . '.', 'EvaluateTargetHealth' => false]],
        ]]);
        $this->dnsAws->handler->append(
            self::zones(),
            new Result(['ResourceRecordSets' => []]),
            new Result(['ResourceRecordSets' => []]),
            $aliases('arco.acmeapp.io'),               // fresh guard read before the write
            $aliases('api-arco.acmeapp.io'),
        );

        $this->domains($this->config())->deploy($this->config(), self::ENDPOINT, [], self::TAGS, $this->step());

        self::assertNotContains('ChangeResourceRecordSets', $this->dnsAws->calls);
        self::assertNotContains('GetChange', $this->dnsAws->calls);
    }

    public function test_the_distribution_callback_runs_once_the_stack_is_complete_and_before_any_dns_write(): void
    {
        self::queue($this->aws, self::stackMissing(), self::wildcard(), self::stackMissing(), new Result([]), self::stack('CREATE_COMPLETE'));
        $this->dnsAws->handler->append(
            self::zones(),
            new Result(['ResourceRecordSets' => []]), new Result(['ResourceRecordSets' => []]),
            new Result(['ResourceRecordSets' => []]), new Result(['ResourceRecordSets' => []]),
            new Result(['ChangeInfo' => ['Id' => '/change/C1', 'Status' => 'PENDING']]),
            new Result(['ChangeInfo' => ['Id' => '/change/C1', 'Status' => 'INSYNC']]),
        );
        $seen = null;

        $this->domains($this->config())->deploy($this->config(), self::ENDPOINT, [], self::TAGS, $this->step(), function (string $id) use (&$seen): void {
            $seen = [$id, $this->aws->calls, $this->dnsAws->calls, $this->stepKeys()];
        });

        self::assertSame('E123', $seen[0] ?? null);
        self::assertSame(['DescribeStacks', 'ListCertificates', 'DescribeCertificate', 'ListTagsForCertificate', 'DescribeStacks', 'CreateStack', 'DescribeStacks'], $seen[1]);
        self::assertNotContains('ChangeResourceRecordSets', $seen[2]);
        self::assertSame(['domains.step_certificate', 'domains.certificate_using', 'domains.step_distribution'], $seen[3]);
    }

    public function test_a_live_record_stops_the_deploy_before_anything_is_created(): void
    {
        self::queue($this->aws, self::stackMissing());
        $this->dnsAws->handler->append(
            self::zones(),
            new Result(['ResourceRecordSets' => [['Name' => 'arco.acmeapp.io.', 'Type' => 'CNAME', 'TTL' => 300, 'ResourceRecords' => [['Value' => 'old.example.com']]]]]),
            new Result(['ResourceRecordSets' => []]),
        );

        try {
            $this->domains($this->config())->deploy($this->config(), self::ENDPOINT, [], self::TAGS, $this->step());
            self::fail('Expected a conflict.');
        } catch (DeployException $e) {
            self::assertSame('domains.record_conflict', $e->key);
            self::assertStringContainsString('arco.acmeapp.io CNAME → old.example.com', (string) $e->params['records']);
        }
        self::assertSame(['DescribeStacks'], $this->aws->calls);
        self::assertNotContains('ChangeResourceRecordSets', $this->dnsAws->calls);
    }

    public function test_a_record_created_during_the_waits_stops_the_write(): void
    {
        self::queue($this->aws, self::stackMissing(), self::wildcard(), self::stackMissing(), new Result([]), self::stack('CREATE_COMPLETE'));
        $this->dnsAws->handler->append(
            self::zones(),
            new Result(['ResourceRecordSets' => []]),
            new Result(['ResourceRecordSets' => []]),
            // Someone pointed the name elsewhere while CloudFront was being created.
            new Result(['ResourceRecordSets' => [['Name' => 'arco.acmeapp.io.', 'Type' => 'A', 'TTL' => 60, 'ResourceRecords' => [['Value' => '9.9.9.9']]]]]),
            new Result(['ResourceRecordSets' => []]),
        );

        try {
            $this->domains($this->config())->deploy($this->config(), self::ENDPOINT, [], self::TAGS, $this->step());
            self::fail('Expected a conflict.');
        } catch (DeployException $e) {
            self::assertSame('domains.record_conflict', $e->key);
            self::assertStringContainsString('arco.acmeapp.io A → 9.9.9.9', (string) $e->params['records']);
        }
        self::assertNotContains('ChangeResourceRecordSets', $this->dnsAws->calls, 'nothing is overwritten');
    }

    public function test_force_dns_replaces_a_live_cname_in_the_same_batch(): void
    {
        $cname = ['Name' => 'arco.acmeapp.io.', 'Type' => 'CNAME', 'TTL' => 300, 'ResourceRecords' => [['Value' => 'old.example.com']]];
        self::queue($this->aws, self::stack('UPDATE_COMPLETE'), self::wildcard(), self::stack('UPDATE_COMPLETE'));
        self::queue($this->aws, AwsMocks::error(CloudFormationException::class, 'UpdateStack', 'ValidationError', 400, 'No updates are to be performed.'));
        $this->dnsAws->handler->append(
            self::zones(),
            new Result(['ResourceRecordSets' => [$cname]]),
            new Result(['ResourceRecordSets' => []]),
            new Result(['ResourceRecordSets' => [$cname]]),
            new Result(['ResourceRecordSets' => []]),
            new Result(['ChangeInfo' => ['Id' => '/change/C1', 'Status' => 'INSYNC']]),
            new Result(['ChangeInfo' => ['Id' => '/change/C1', 'Status' => 'INSYNC']]),
        );

        $this->domains($this->config(), force: true)->deploy($this->config(), self::ENDPOINT, [], self::TAGS, $this->step());

        self::assertSame(['DescribeStacks', 'ListCertificates', 'DescribeCertificate', 'ListTagsForCertificate', 'DescribeStacks', 'UpdateStack'], $this->aws->calls, 'no updates is success');
        $changes = $this->dnsAws->params[5]['ChangeBatch']['Changes'];
        self::assertSame(['Action' => 'DELETE', 'ResourceRecordSet' => $cname], $changes[0]);
        self::assertSame(['UPSERT', 'UPSERT', 'UPSERT', 'UPSERT'], array_map(fn (array $c) => $c['Action'], array_slice($changes, 1)));
    }

    public function test_requests_a_certificate_and_validates_it_through_route53(): void
    {
        $pending = fn (bool $records) => new Result(['Certificate' => [
            'CertificateArn' => 'arn:new', 'DomainName' => 'arco.acmeapp.io', 'SubjectAlternativeNames' => ['arco.acmeapp.io', 'api-arco.acmeapp.io'], 'Status' => 'PENDING_VALIDATION',
            'DomainValidationOptions' => [
                ['DomainName' => 'arco.acmeapp.io', 'ValidationStatus' => 'PENDING_VALIDATION'] + ($records ? ['ResourceRecord' => ['Name' => '_a.arco.acmeapp.io.', 'Type' => 'CNAME', 'Value' => '_x.acm-validations.aws.']] : []),
                ['DomainName' => 'api-arco.acmeapp.io', 'ValidationStatus' => 'PENDING_VALIDATION'] + ($records ? ['ResourceRecord' => ['Name' => '_b.api-arco.acmeapp.io.', 'Type' => 'CNAME', 'Value' => '_y.acm-validations.aws.']] : []),
            ],
        ]]);
        self::queue($this->aws, 
            self::stackMissing(),
            new Result(['CertificateSummaryList' => []]),
            new Result(['CertificateArn' => 'arn:new']),
            $pending(false),
            $pending(true),
            $pending(true),
            new Result(['Certificate' => ['CertificateArn' => 'arn:new', 'Status' => 'ISSUED', 'Type' => 'AMAZON_ISSUED', 'KeyAlgorithm' => 'RSA-2048']]),
            self::stackMissing(), new Result([]), self::stack('CREATE_COMPLETE'),
        );
        $this->dnsAws->handler->append(
            self::zones(),
            new Result(['ResourceRecordSets' => []]),
            new Result(['ResourceRecordSets' => []]),
            new Result(['ChangeInfo' => ['Id' => '/change/V', 'Status' => 'PENDING']]),
            new Result(['ChangeInfo' => ['Id' => '/change/V', 'Status' => 'INSYNC']]),
            new Result(['ResourceRecordSets' => []]),
            new Result(['ResourceRecordSets' => []]),
            new Result(['ChangeInfo' => ['Id' => '/change/A', 'Status' => 'INSYNC']]),
            new Result(['ChangeInfo' => ['Id' => '/change/A', 'Status' => 'INSYNC']]),
        );

        $this->domains($this->config())->deploy($this->config(), self::ENDPOINT, [], self::TAGS, $this->step());

        $request = $this->aws->params[2];
        self::assertSame('arco.acmeapp.io', $request['DomainName']);
        self::assertSame(['arco.acmeapp.io', 'api-arco.acmeapp.io'], $request['SubjectAlternativeNames']);
        self::assertSame('DNS', $request['ValidationMethod']);
        self::assertMatchesRegularExpression('/^[a-z0-9]{32}$/', $request['IdempotencyToken']);
        self::assertContains(['Key' => 'arco:managed', 'Value' => 'true'], $request['Tags']);

        $validation = $this->dnsAws->params[3]['ChangeBatch']['Changes'];
        self::assertSame([
            ['Action' => 'UPSERT', 'ResourceRecordSet' => ['Name' => '_a.arco.acmeapp.io', 'Type' => 'CNAME', 'TTL' => 300, 'ResourceRecords' => [['Value' => '_x.acm-validations.aws']]]],
            ['Action' => 'UPSERT', 'ResourceRecordSet' => ['Name' => '_b.api-arco.acmeapp.io', 'Type' => 'CNAME', 'TTL' => 300, 'ResourceRecords' => [['Value' => '_y.acm-validations.aws']]]],
        ], $validation);
        self::assertSame(['domains.step_certificate', 'domains.certificate_requested', 'domains.waiting_certificate', 'domains.step_distribution', 'domains.step_dns'], $this->stepKeys());
    }

    public function test_manual_dns_prints_the_records_and_waits_for_the_certificate(): void
    {
        $config = $this->config(fn (Environment $e) => $e->dns(false));
        self::queue($this->aws, 
            self::stackMissing(),
            new Result(['CertificateSummaryList' => []]),
            new Result(['CertificateArn' => 'arn:new']),
            new Result(['Certificate' => ['CertificateArn' => 'arn:new', 'Status' => 'PENDING_VALIDATION', 'DomainValidationOptions' => [
                ['DomainName' => 'arco.acmeapp.io', 'ResourceRecord' => ['Name' => '_a.arco.acmeapp.io.', 'Type' => 'CNAME', 'Value' => '_x.acm-validations.aws.']],
            ]]]),
            new Result(['Certificate' => ['CertificateArn' => 'arn:new', 'Status' => 'PENDING_VALIDATION']]),
            new Result(['Certificate' => ['CertificateArn' => 'arn:new', 'Status' => 'ISSUED', 'Type' => 'AMAZON_ISSUED', 'KeyAlgorithm' => 'RSA-2048']]),
            self::stackMissing(), new Result([]), self::stack('CREATE_COMPLETE'),
        );

        CustomDomains::create($config, $this->aws->clients(), null, AwsMocks::noSleep())->deploy($config, self::ENDPOINT, [], self::TAGS, $this->step());

        self::assertSame([], $this->dnsAws->calls);
        self::assertNotContains('ListHostedZonesByName', $this->aws->calls);
        $records = array_values(array_filter($this->steps, fn (array $s) => $s[0] === 'domains.manual_record'));
        self::assertSame([
            ['domains.manual_record', ['type' => 'CNAME', 'name' => '_a.arco.acmeapp.io', 'value' => '_x.acm-validations.aws']],
            ['domains.manual_record', ['type' => 'CNAME', 'name' => 'arco.acmeapp.io', 'value' => self::DIST]],
            ['domains.manual_record', ['type' => 'CNAME', 'name' => 'api-arco.acmeapp.io', 'value' => self::DIST]],
        ], $records);
        self::assertSame(2, count(array_filter($this->stepKeys(), fn (string $k) => $k === 'domains.manual_records')));
    }

    public function test_default_dns_uses_the_deploy_account_and_a_domain_without_a_zone_falls_back_to_manual(): void
    {
        $config = $this->config(fn (Environment $e) => $e->domain('shop.example.com'));
        self::queue($this->aws, 
            self::stackMissing(),
            self::zones(),
            new Result(['ResourceRecordSets' => []]),
            new Result(['ResourceRecordSets' => []]),
            new Result(['CertificateSummaryList' => [['CertificateArn' => 'arn:c', 'DomainName' => 'arco.acmeapp.io', 'HasAdditionalSubjectAlternativeNames' => true, 'Status' => 'ISSUED', 'Type' => 'AMAZON_ISSUED', 'KeyAlgorithm' => 'RSA-2048']]]),
            new Result(['Certificate' => ['CertificateArn' => 'arn:c', 'DomainName' => 'arco.acmeapp.io', 'SubjectAlternativeNames' => ['arco.acmeapp.io', 'api-arco.acmeapp.io', 'shop.example.com'], 'Status' => 'ISSUED', 'Type' => 'AMAZON_ISSUED', 'KeyAlgorithm' => 'RSA-2048']]),
            new Result(['Tags' => [['Key' => 'arco:app', 'Value' => 'shop']]]),
            self::stackMissing(), new Result([]), self::stack('CREATE_COMPLETE'),
            new Result(['ResourceRecordSets' => []]),
            new Result(['ResourceRecordSets' => []]),
            new Result(['ChangeInfo' => ['Id' => '/change/A', 'Status' => 'INSYNC']]),
            new Result(['ChangeInfo' => ['Id' => '/change/A', 'Status' => 'INSYNC']]),
        );

        $this->domains($config, withDnsAccount: false)->deploy($config, self::ENDPOINT, [], self::TAGS, $this->step());

        self::assertSame([], $this->dnsAws->calls);
        self::assertSame([
            'DescribeStacks', 'ListHostedZonesByName', 'ListResourceRecordSets', 'ListResourceRecordSets',
            'ListCertificates', 'DescribeCertificate', 'ListTagsForCertificate', 'DescribeStacks', 'CreateStack', 'DescribeStacks',
            'ListResourceRecordSets', 'ListResourceRecordSets', 'ChangeResourceRecordSets', 'GetChange',
        ], $this->aws->calls);
        self::assertCount(4, $this->aws->params[12]['ChangeBatch']['Changes'], 'only the two zoned domains');
        self::assertContains(['domains.manual_record', ['type' => 'CNAME', 'name' => 'shop.example.com', 'value' => self::DIST]], $this->steps);
    }

    public function test_configured_certificate_must_be_issued_and_cover_every_domain(): void
    {
        $arn = 'arn:aws:acm:us-east-1:111111111111:certificate/abc';
        $config = $this->config(fn (Environment $e) => $e->dns(false)->certificate($arn));
        self::queue($this->aws, 
            self::stackMissing(),
            new Result(['Certificate' => ['CertificateArn' => $arn, 'DomainName' => 'arco.acmeapp.io', 'SubjectAlternativeNames' => ['arco.acmeapp.io'], 'Status' => 'ISSUED', 'Type' => 'AMAZON_ISSUED', 'KeyAlgorithm' => 'RSA-2048']]),
        );

        try {
            CustomDomains::create($config, $this->aws->clients(), null, AwsMocks::noSleep())->deploy($config, self::ENDPOINT, [], self::TAGS, $this->step());
            self::fail('Expected an uncovered domain.');
        } catch (DeployException $e) {
            self::assertSame('domains.certificate_uncovered', $e->key);
            self::assertSame('api-arco.acmeapp.io', $e->params['domains']);
        }
        self::assertNotContains('ListCertificates', $this->aws->calls);
    }

    public function test_configured_certificate_must_be_one_cloudfront_accepts(): void
    {
        $arn = 'arn:aws:acm:us-east-1:111111111111:certificate/abc';
        $config = $this->config(fn (Environment $e) => $e->dns(false)->certificate($arn));
        self::queue($this->aws,
            self::stackMissing(),
            new Result(['Certificate' => ['CertificateArn' => $arn, 'DomainName' => '*.acmeapp.io', 'SubjectAlternativeNames' => ['*.acmeapp.io'], 'Status' => 'ISSUED', 'Type' => 'AMAZON_ISSUED', 'KeyAlgorithm' => 'EC-secp384r1']]),
        );

        try {
            CustomDomains::create($config, $this->aws->clients(), null, AwsMocks::noSleep())->deploy($config, self::ENDPOINT, [], self::TAGS, $this->step());
            self::fail('Expected an unsupported certificate.');
        } catch (DeployException $e) {
            self::assertSame('domains.certificate_unsupported', $e->key);
            self::assertSame('EC_secp384r1', $e->params['algorithm']);
        }
    }

    public function test_private_and_unsupported_key_certificates_are_not_considered(): void
    {
        $config = $this->config(fn (Environment $e) => $e->dns(false));
        self::queue($this->aws,
            self::stackMissing(),
            new Result(['CertificateSummaryList' => [
                ['CertificateArn' => 'arn:private', 'DomainName' => '*.acmeapp.io', 'SubjectAlternativeNameSummaries' => ['*.acmeapp.io'], 'Status' => 'ISSUED', 'Type' => 'PRIVATE', 'KeyAlgorithm' => 'RSA-2048'],
                ['CertificateArn' => 'arn:p384', 'DomainName' => '*.acmeapp.io', 'SubjectAlternativeNameSummaries' => ['*.acmeapp.io'], 'Status' => 'ISSUED', 'Type' => 'AMAZON_ISSUED', 'KeyAlgorithm' => 'EC-secp384r1'],
                ['CertificateArn' => 'arn:wildcard', 'DomainName' => '*.acmeapp.io', 'SubjectAlternativeNameSummaries' => ['*.acmeapp.io', 'acmeapp.io'], 'Status' => 'ISSUED', 'Type' => 'AMAZON_ISSUED', 'KeyAlgorithm' => 'RSA-2048'],
            ]]),
            new Result(['Certificate' => ['CertificateArn' => 'arn:wildcard', 'DomainName' => '*.acmeapp.io', 'SubjectAlternativeNames' => ['*.acmeapp.io', 'acmeapp.io'], 'Status' => 'ISSUED', 'Type' => 'AMAZON_ISSUED', 'KeyAlgorithm' => 'RSA-2048']]),
            new Result(['Tags' => []]),
            self::stackMissing(), new Result([]), self::stack('CREATE_COMPLETE'),
        );

        CustomDomains::create($config, $this->aws->clients(), null, AwsMocks::noSleep())->deploy($config, self::ENDPOINT, [], self::TAGS, $this->step());

        self::assertSame('arn:wildcard', $this->aws->params[2]['CertificateArn'], 'only the usable certificate is described');
        self::assertContains(['domains.certificate_using', ['arn' => 'arn:wildcard']], $this->steps);
    }

    public function test_acm_is_always_called_in_us_east_1_and_cloudformation_in_the_app_region(): void
    {
        $config = ConfigResolver::resolve(App::make('shop')->region('eu-west-1')->environment('production', fn (Environment $e) => $e
            ->domain('arco.acmeapp.io')->dns(false))->toArray(), 'production');
        self::queue($this->aws, self::stackMissing(), self::wildcard(), self::stackMissing(), new Result([]), self::stack('CREATE_COMPLETE'));

        CustomDomains::create($config, $this->aws->clients('eu-west-1'), null, AwsMocks::noSleep())->deploy($config, self::ENDPOINT, [], self::TAGS, $this->step());

        foreach ($this->aws->calls as $i => $call) {
            $expected = in_array($call, ['ListCertificates', 'DescribeCertificate', 'ListTagsForCertificate'], true) ? 'acm.us-east-1.amazonaws.com' : 'cloudformation.eu-west-1.amazonaws.com';
            self::assertSame($expected, $this->aws->hosts[$i], $call);
        }
    }

    public function test_a_stack_in_a_state_that_cannot_be_updated_is_reported_and_left_alone(): void
    {
        $config = $this->config(fn (Environment $e) => $e->dns(false));
        foreach (['UPDATE_ROLLBACK_FAILED', 'DELETE_FAILED'] as $status) {
            $this->aws = new AwsMocks();
            self::queue($this->aws, self::stack($status), self::wildcard(), self::stack($status));
            try {
                CustomDomains::create($config, $this->aws->clients(), null, AwsMocks::noSleep())->deploy($config, self::ENDPOINT, [], self::TAGS, $this->step());
                self::fail("Expected $status to stop the deploy.");
            } catch (DeployException $e) {
                self::assertSame('domains.stack_not_updatable', $e->key);
                self::assertSame(['stack' => 'arco-shop-production', 'status' => $status], $e->params);
            }
            self::assertNotContains('UpdateStack', $this->aws->calls);
            self::assertNotContains('DeleteStack', $this->aws->calls);
        }
    }

    public function test_a_stack_arco_did_not_create_for_this_environment_is_never_touched(): void
    {
        $foreign = new Result(['Stacks' => [['StackName' => 'arco-shop-production', 'StackStatus' => 'CREATE_COMPLETE',
            'Tags' => [['Key' => 'arco:app', 'Value' => 'shop'], ['Key' => 'arco:environment', 'Value' => 'staging']],
            'Outputs' => [['OutputKey' => 'DistributionDomain', 'OutputValue' => self::DIST]]]]]);

        self::queue($this->aws, $foreign);
        try {
            $this->domains($this->config())->deploy($this->config(), self::ENDPOINT, [], self::TAGS, $this->step());
            self::fail('Expected the deploy to refuse.');
        } catch (DeployException $e) {
            self::assertSame('domains.stack_not_ours', $e->key);
        }

        self::queue($this->aws, $foreign);
        try {
            $this->domains($this->config())->remove($this->config(), $this->step());
            self::fail('Expected the remove to refuse.');
        } catch (DeployException $e) {
            self::assertSame('domains.stack_not_ours', $e->key);
        }
        self::assertSame(['DescribeStacks', 'DescribeStacks'], $this->aws->calls);
        self::assertSame([], $this->dnsAws->calls);
    }

    public function test_failed_stack_reports_the_root_cause(): void
    {
        $config = $this->config(fn (Environment $e) => $e->dns(false));
        self::queue($this->aws, 
            self::stackMissing(),
            self::wildcard(),
            self::stackMissing(), new Result([]), self::stack('ROLLBACK_COMPLETE', false),
            new Result(['StackEvents' => [
                ['LogicalResourceId' => 'arco-shop-production', 'ResourceStatus' => 'ROLLBACK_COMPLETE'],
                ['LogicalResourceId' => 'ForwardHost', 'ResourceStatus' => 'DELETE_COMPLETE'],
                ['LogicalResourceId' => 'arco-shop-production', 'ResourceStatus' => 'ROLLBACK_IN_PROGRESS', 'ResourceStatusReason' => 'The following resource(s) failed to create: [Distribution].'],
                ['LogicalResourceId' => 'ForwardHost', 'ResourceStatus' => 'CREATE_FAILED', 'ResourceStatusReason' => 'Resource creation cancelled'],
                ['LogicalResourceId' => 'Distribution', 'ResourceStatus' => 'CREATE_FAILED', 'ResourceStatusReason' => 'CNAMEAlreadyExists: One or more aliases is already associated with a different resource.'],
                ['LogicalResourceId' => 'arco-shop-production', 'ResourceStatus' => 'CREATE_IN_PROGRESS', 'ResourceStatusReason' => 'User Initiated'],
                ['LogicalResourceId' => 'Distribution', 'ResourceStatus' => 'CREATE_FAILED', 'ResourceStatusReason' => 'an older operation'],
            ]]),
        );

        try {
            CustomDomains::create($config, $this->aws->clients(), null, AwsMocks::noSleep())->deploy($config, self::ENDPOINT, [], self::TAGS, $this->step());
            self::fail('Expected a stack failure.');
        } catch (DeployException $e) {
            self::assertSame('domains.stack_failed', $e->key);
            self::assertSame('ROLLBACK_COMPLETE', $e->params['status']);
            self::assertSame('Distribution: CNAMEAlreadyExists: One or more aliases is already associated with a different resource.', $e->params['reason']);
        }
    }

    public function test_a_rolled_back_first_creation_is_deleted_and_created_again(): void
    {
        $config = $this->config(fn (Environment $e) => $e->dns(false));
        self::queue($this->aws, 
            self::stack('ROLLBACK_COMPLETE', false),
            self::wildcard(),
            self::stack('ROLLBACK_COMPLETE', false),
            self::stack('ROLLBACK_COMPLETE', false), new Result([]), self::stack('DELETE_IN_PROGRESS', false), self::stackMissing(),
            new Result([]), self::stack('CREATE_COMPLETE'),
        );

        CustomDomains::create($config, $this->aws->clients(), null, AwsMocks::noSleep())->deploy($config, self::ENDPOINT, [], self::TAGS, $this->step());

        self::assertSame(['DescribeStacks', 'ListCertificates', 'DescribeCertificate', 'ListTagsForCertificate', 'DescribeStacks', 'DescribeStacks', 'DeleteStack', 'DescribeStacks', 'DescribeStacks', 'CreateStack', 'DescribeStacks'], $this->aws->calls);
    }

    public function test_remove_deletes_our_aliases_the_stack_and_only_the_managed_certificate(): void
    {
        $alias = fn (string $type, string $target) => ['Name' => 'arco.acmeapp.io.', 'Type' => $type, 'AliasTarget' => ['HostedZoneId' => 'Z2FDTNDATAQYW2', 'DNSName' => $target . '.', 'EvaluateTargetHealth' => false]];
        self::queue($this->aws, 
            self::stack('CREATE_COMPLETE'),
            self::stack('CREATE_COMPLETE'), new Result([]), self::stack('DELETE_IN_PROGRESS'), self::stackMissing(),
            new Result(['CertificateSummaryList' => [
                ['CertificateArn' => 'arn:managed', 'DomainName' => 'arco.acmeapp.io', 'SubjectAlternativeNameSummaries' => ['arco.acmeapp.io', 'api-arco.acmeapp.io']],
                ['CertificateArn' => 'arn:wildcard', 'DomainName' => '*.acmeapp.io', 'SubjectAlternativeNameSummaries' => ['*.acmeapp.io', 'api-arco.acmeapp.io']],
                ['CertificateArn' => 'arn:staging', 'DomainName' => 'arco.acmeapp.io'],
            ]]),
            new Result(['Tags' => [['Key' => 'arco:app', 'Value' => 'shop'], ['Key' => 'arco:environment', 'Value' => 'production'], ['Key' => 'arco:managed', 'Value' => 'true']]]),
            new Result(['Certificate' => ['CertificateArn' => 'arn:managed', 'Status' => 'ISSUED', 'DomainValidationOptions' => [
                ['DomainName' => 'arco.acmeapp.io', 'ResourceRecord' => ['Name' => '_a.arco.acmeapp.io.', 'Type' => 'CNAME', 'Value' => '_x.acm-validations.aws.']],
                ['DomainName' => 'api-arco.acmeapp.io', 'ResourceRecord' => ['Name' => '_b.api-arco.acmeapp.io.', 'Type' => 'CNAME', 'Value' => '_y.acm-validations.aws.']],
            ]]]),
            new Result(['Tags' => []]),
            new Result(['Tags' => [['Key' => 'arco:app', 'Value' => 'shop'], ['Key' => 'arco:environment', 'Value' => 'staging'], ['Key' => 'arco:managed', 'Value' => 'true']]]),
            AwsMocks::error(AcmException::class, 'DeleteCertificate', 'ResourceInUseException', 400),
            new Result([]),
        );
        $this->dnsAws->handler->append(
            self::zones(),
            new Result(['ResourceRecordSets' => [$alias('A', self::DIST), $alias('AAAA', self::DIST), ['Name' => 'arco.acmeapp.io.', 'Type' => 'TXT', 'TTL' => 300, 'ResourceRecords' => [['Value' => '"x"']]]]]),
            new Result(['ResourceRecordSets' => [['Name' => 'api-arco.acmeapp.io.', 'Type' => 'A', 'AliasTarget' => ['HostedZoneId' => 'Z2FDTNDATAQYW2', 'DNSName' => 'd999.cloudfront.net.', 'EvaluateTargetHealth' => false]]]]),
            new Result(['ChangeInfo' => ['Id' => '/change/R', 'Status' => 'INSYNC']]),
            new Result(['ChangeInfo' => ['Id' => '/change/R', 'Status' => 'INSYNC']]),
        );

        $this->domains($this->config())->remove($this->config(), $this->step());

        self::assertSame(['remove.step_dns', 'remove.step_distribution', 'remove.step_certificate'], $this->stepKeys());
        self::assertSame([
            'DescribeStacks', 'DescribeStacks', 'DeleteStack', 'DescribeStacks', 'DescribeStacks',
            'ListCertificates', 'ListTagsForCertificate', 'DescribeCertificate', 'ListTagsForCertificate', 'ListTagsForCertificate',
            'DeleteCertificate', 'DeleteCertificate',
        ], $this->aws->calls);
        self::assertSame('arn:managed', $this->aws->params[11]['CertificateArn']);

        $aliases = $this->dnsAws->params[3]['ChangeBatch']['Changes'];
        self::assertSame([['Action' => 'DELETE', 'ResourceRecordSet' => $alias('A', self::DIST)], ['Action' => 'DELETE', 'ResourceRecordSet' => $alias('AAAA', self::DIST)]], $aliases);
        // Validation CNAMEs are never deleted: ACM may share them with certificates in other regions.
        self::assertSame(['ListHostedZonesByName', 'ListResourceRecordSets', 'ListResourceRecordSets', 'ChangeResourceRecordSets', 'GetChange'], $this->dnsAws->calls);
    }

    public function test_remove_with_nothing_left_is_a_no_op(): void
    {
        self::queue($this->aws, self::stackMissing(), new Result(['CertificateSummaryList' => []]));

        $this->domains($this->config())->remove($this->config(), $this->step());

        self::assertSame([], $this->steps);
        self::assertSame([], $this->dnsAws->calls);
    }
}
