<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Commands;

use Aws\Result;
use Multiapi\Arco\Cli\Application;
use Multiapi\Arco\Commands\CertificateSetCommand;
use Multiapi\Arco\Commands\ResourcesCommand;
use Multiapi\Arco\Commands\VpcSetCommand;
use Multiapi\Arco\Tests\Unit\Deploy\AwsMocks;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** The interactive picker of `arco resources` (spec §7), driven through a fake PickerUi. */
final class ResourcesPickerTest extends TestCase
{
    /** What `arco resources` may call: List*, Describe*, Get* (spec §9), plus sts:GetCallerIdentity for the account. */
    private const READ_ONLY = [
        'GetCallerIdentity',
        'DescribeSubnets', 'DescribeRouteTables', 'DescribeSecurityGroups', 'DescribeVpcEndpoints', 'DescribeVpcs',
        'ListBuckets', 'GetBucketLocation', 'GetBucketTagging',
        'ListQueues', 'GetQueueAttributes', 'ListQueueTags',
        'ListTables', 'DescribeTable', 'DescribeTimeToLive', 'ListTagsOfResource',
        'ListCertificates', 'DescribeCertificate',
        'ListHostedZones', 'GetHostedZone',
    ];

    private const CERT = 'arn:aws:acm:us-east-1:111111111111:certificate/abcd-1234';
    private const OTHER_CERT = 'arn:aws:acm:us-east-1:111111111111:certificate/ffff-0000';
    private const TYPES = ['subnet', 'security-group', 'bucket', 'certificate'];

    private ?ProjectFixture $project = null;
    private AwsMocks $mocks;
    private FakePickerUi $ui;
    private ?CommandTester $tester = null;

    protected function setUp(): void
    {
        $this->mocks = new AwsMocks();
        $this->ui = new FakePickerUi();
    }

    protected function tearDown(): void
    {
        $this->project?->restore();
    }

    private static function arcoPhp(string $staging = ''): string
    {
        return <<<PHP
            <?php
            use Multiapi\\Arco\\Config\\App;
            use Multiapi\\Arco\\Config\\Environment;

            return App::make('shop')
                ->region('us-east-1')
                ->environment('staging', fn (Environment \$env) => \$env->timeout(30)$staging);
            PHP;
    }

    private function arcoPhpNow(): string
    {
        return (string) file_get_contents((string) $this->project?->dir . '/arco.php');
    }

    /**
     * Runs `arco resources` in a terminal. The editing commands it runs in-process use the same mocked AWS clients
     * (they validate with DescribeSubnets and DescribeCertificate when they can).
     *
     * @param list<string> $types
     */
    private function run_(string $arcoPhp, array $types = self::TYPES): CommandTester
    {
        $this->project = new ProjectFixture($arcoPhp);
        $factory = fn (?string $profile, string $region) => $this->mocks->clients($region);
        $clock = static fn (): int => (int) strtotime('2026-10-30T00:00:00Z');
        $app = Application::create();
        $app->addCommands([
            new ResourcesCommand($factory, $clock, $this->ui),
            new VpcSetCommand($factory),
            new CertificateSetCommand($factory, $clock),
        ]);
        $this->tester = new CommandTester($app->find('resources'));
        $this->tester->execute(['--env' => 'staging', '--profile' => 'p', '--type' => $types], ['interactive' => true]);
        return $this->tester;
    }

    private function account(): void
    {
        $this->mocks->handler->append(new Result(['Account' => '111111111111']));
    }

    /** One subnet and one security group in vpc-1, Arco's code bucket and shop-uploads, one certificate. */
    private function listing(): void
    {
        $this->account();
        $this->mocks->handler->append(
            new Result(['Subnets' => [[
                'SubnetId' => 'subnet-aaa', 'VpcId' => 'vpc-1', 'AvailabilityZoneId' => 'use1-az1', 'CidrBlock' => '10.0.8.0/21', 'AvailableIpAddressCount' => 2011,
                'Tags' => [['Key' => 'Name', 'Value' => 'dev-lambda-1a']],
            ]]]),
            new Result(['RouteTables' => [[
                'RouteTableId' => 'rtb-1', 'VpcId' => 'vpc-1',
                'Routes' => [['DestinationCidrBlock' => '0.0.0.0/0', 'NatGatewayId' => 'nat-1']],
                'Associations' => [['SubnetId' => 'subnet-aaa', 'Main' => false]],
            ]]]),
            new Result(['Vpcs' => [['VpcId' => 'vpc-1', 'Tags' => [['Key' => 'Name', 'Value' => 'dev']]]]]),
            new Result(['SecurityGroups' => [[
                'GroupId' => 'sg-aaa', 'GroupName' => 'lambda', 'VpcId' => 'vpc-1', 'Description' => 'Lambda functions', 'Tags' => [],
                'IpPermissionsEgress' => [['IpProtocol' => '-1', 'IpRanges' => [['CidrIp' => '0.0.0.0/0']]]],
            ]]]),
            new Result(['Vpcs' => [['VpcId' => 'vpc-1', 'Tags' => [['Key' => 'Name', 'Value' => 'dev']]]]]),
            new Result(['Buckets' => [
                ['Name' => 'arco-111111111111-us-east-1', 'CreationDate' => '2026-01-01T00:00:00+00:00'],
                ['Name' => 'shop-uploads', 'CreationDate' => '2026-02-01T00:00:00+00:00'],
            ]]),
            new Result([]),
            new Result(['TagSet' => []]),
            new Result([]),
            new Result(['TagSet' => []]),
            new Result(['CertificateSummaryList' => [['CertificateArn' => self::CERT]]]),
            new Result(['Certificate' => [
                'CertificateArn' => self::CERT, 'DomainName' => '*.example.com', 'SubjectAlternativeNames' => ['*.example.com'],
                'Status' => 'ISSUED', 'NotAfter' => (int) strtotime('2027-06-01T00:00:00Z'), 'InUseBy' => [],
            ]]),
        );
    }

    /** The answers of a run that picks the subnet, the group, shop-uploads (named files) and the certificate. */
    private function pickEverything(): void
    {
        $this->ui->confirms = [true, true];
        $this->ui->multiselects = [['subnet-aaa'], ['sg-aaa'], ['shop-uploads'], [self::CERT]];
        $this->ui->texts = ['files'];
    }

    public function test_declining_the_account_line_lists_nothing(): void
    {
        $this->account();
        $this->ui->confirms = [false];

        $tester = $this->run_(self::arcoPhp());

        $tester->assertCommandIsSuccessful();
        self::assertSame(['GetCallerIdentity'], $this->mocks->calls, 'no AWS list call after the account line is declined');
        self::assertSame('confirm', $this->ui->asked[0][0]);
        self::assertStringContainsString('111111111111', $this->ui->asked[0][1]);
        self::assertStringContainsString('(p)', $this->ui->asked[0][1]);
        self::assertStringContainsString('us-east-1', $this->ui->asked[0][1]);
        self::assertCount(1, $this->ui->asked);
        self::assertSame(self::arcoPhp(), $this->arcoPhpNow());
    }

    public function test_applies_picks_and_writes_arco_php(): void
    {
        $this->listing();
        $this->pickEverything();

        $tester = $this->run_(self::arcoPhp());

        $tester->assertCommandIsSuccessful();
        $arcoPhp = $this->arcoPhpNow();
        self::assertStringContainsString("->vpc(subnets: ['subnet-aaa'], securityGroups: ['sg-aaa'])", $arcoPhp);
        self::assertStringContainsString("->storage('files', existing: 'shop-uploads')", $arcoPhp);
        self::assertStringContainsString("->certificate('" . self::CERT . "')", $arcoPhp);
        $display = $tester->getDisplay();
        self::assertStringContainsString('arco vpc:set --subnet=subnet-aaa --security-group=sg-aaa --env=staging', $display);
        self::assertStringContainsString('arco storage:add files --existing=shop-uploads --env=staging', $display);
        // The bucket's name was asked, prefilled with its suggested name; Arco's code bucket was not offered.
        self::assertContains(['text', 'Arco name for bucket shop-uploads', 'uploads'], $this->ui->asked);
        $bucketQuestion = array_values(array_filter($this->ui->asked, fn (array $q) => $q[0] === 'multiselect'))[2];
        self::assertSame([], $bucketQuestion[2], 'nothing in use: nothing pre-ticked');
    }

    public function test_in_use_items_are_pre_ticked(): void
    {
        $this->listing();
        $this->ui->confirms = [true];
        $this->ui->multiselects = [
            fn (string $label, array $options, array $default) => $default,
            fn (string $label, array $options, array $default) => $default,
            fn (string $label, array $options, array $default) => $default,
            fn (string $label, array $options, array $default) => $default,
        ];

        $tester = $this->run_(self::arcoPhp("->vpc(['subnet-aaa'], ['sg-aaa'])->storage('uploads', existing: 'shop-uploads')"));

        $tester->assertCommandIsSuccessful();
        $defaults = array_map(fn (array $q) => $q[2], array_values(array_filter($this->ui->asked, fn (array $q) => $q[0] === 'multiselect')));
        self::assertSame([['subnet-aaa'], ['sg-aaa'], ['shop-uploads'], []], $defaults);
        self::assertStringContainsString('Nothing to change', $tester->getDisplay());
    }

    public function test_conflict_asks_before_replacing(): void
    {
        $this->listing();
        // Account line yes; replace the certificate no; nothing left to apply.
        $this->ui->confirms = [true, false];
        $this->ui->multiselects = [[], [], [], [self::CERT]];

        $arcoPhp = self::arcoPhp("->certificate('" . self::OTHER_CERT . "')");
        $tester = $this->run_($arcoPhp);

        $tester->assertCommandIsSuccessful();
        self::assertSame('confirm', $this->ui->asked[5][0]);
        self::assertStringContainsString("Replace certificate('" . self::OTHER_CERT . "')", $this->ui->asked[5][1]);
        self::assertSame($arcoPhp, $this->arcoPhpNow());
    }

    public function test_stops_at_the_first_failing_command(): void
    {
        $this->listing();
        $this->pickEverything();
        $this->ui->texts = ['Not A Valid Name!'];

        $tester = $this->run_(self::arcoPhp());

        self::assertSame(1, $tester->getStatusCode());
        $arcoPhp = $this->arcoPhpNow();
        self::assertStringContainsString("->vpc(subnets: ['subnet-aaa'], securityGroups: ['sg-aaa'])", $arcoPhp, 'the first command stays written');
        self::assertStringNotContainsString('->storage(', $arcoPhp);
        self::assertStringNotContainsString('->certificate(', $arcoPhp, 'nothing after the failure runs');
        self::assertStringContainsString('Stopped: 1 written, then storage:add failed', $tester->getDisplay());
    }

    public function test_shows_equivalent_commands_before_confirming(): void
    {
        $this->listing();
        $this->pickEverything();
        $shown = '';
        $this->ui->confirms = [true, function () use (&$shown): bool {
            $shown = (string) $this->tester?->getDisplay();
            return false;
        }];

        $tester = $this->run_(self::arcoPhp());

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('To repeat this in CI:', $shown);
        self::assertStringContainsString('arco vpc:set --subnet=subnet-aaa --security-group=sg-aaa --env=staging', $shown);
        self::assertStringContainsString('arco storage:add files --existing=shop-uploads --env=staging', $shown);
        self::assertStringContainsString('arco certificate:set ' . self::CERT . ' --env=staging', $shown);
        self::assertStringContainsString("+ ->vpc(subnets: ['subnet-aaa'], securityGroups: ['sg-aaa'])", $shown);
        self::assertStringContainsString("+ ->storage('files', existing: 'shop-uploads')", $shown);
        self::assertSame(self::arcoPhp(), $this->arcoPhpNow(), 'declined: nothing written');
    }

    public function test_subnets_from_two_vpcs_are_refused_at_once(): void
    {
        $this->account();
        $this->mocks->handler->append(
            new Result(['Subnets' => [
                ['SubnetId' => 'subnet-aaa', 'VpcId' => 'vpc-1', 'Tags' => []],
                ['SubnetId' => 'subnet-bbb', 'VpcId' => 'vpc-2', 'Tags' => []],
            ]]),
            new Result(['RouteTables' => []]),
            new Result(['Vpcs' => [['VpcId' => 'vpc-1'], ['VpcId' => 'vpc-2']]]),
        );
        $this->ui->confirms = [true];
        $this->ui->multiselects = [['subnet-aaa', 'subnet-bbb'], []];

        $tester = $this->run_(self::arcoPhp(), ['subnet']);

        $tester->assertCommandIsSuccessful();
        self::assertCount(2, array_filter($this->ui->asked, fn (array $q) => $q[0] === 'multiselect'), 'asked again');
        self::assertStringContainsString('more than one VPC (vpc-1, vpc-2)', $tester->getDisplay());
    }

    public function test_never_calls_aws_writes(): void
    {
        $this->listing();
        $this->pickEverything();

        $tester = $this->run_(self::arcoPhp());

        $tester->assertCommandIsSuccessful();
        self::assertNotSame([], $this->mocks->calls);
        self::assertSame([], array_values(array_diff($this->mocks->calls, self::READ_ONLY)), 'read-only AWS calls only');
    }
}
