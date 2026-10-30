<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Commands;

use Aws\Result;
use Multiapi\Arco\Cli\Application;
use Multiapi\Arco\Commands\CertificateSetCommand;
use Multiapi\Arco\Commands\ResourcesCommand;
use Multiapi\Arco\Commands\VpcSetCommand;
use Multiapi\Arco\Resources\PromptsUnavailable;
use Multiapi\Arco\Tests\Unit\Deploy\AwsMocks;
use Laravel\Prompts\Prompt;
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
        Prompt::flushState();
        Prompt::interactive(false);
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
     * @param array<string, mixed> $options
     */
    private function run_(string $arcoPhp, array $types = self::TYPES, array $options = []): CommandTester
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
        $this->tester->execute(['--env' => 'staging', '--profile' => 'p', '--type' => $types, ...$options], ['interactive' => true]);
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
        // The target is a line of its own, never cut: prompts truncates a label at the terminal's width.
        self::assertStringContainsString('Account 111111111111 (p), region us-east-1, environment staging.', $tester->getDisplay());
        self::assertSame("List this account's resources?", $this->ui->asked[0][1]);
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

    /** subnet-aaa, and security groups sg-aaa and sg-bbb, all in vpc-1. */
    private function networkListing(): void
    {
        $this->account();
        $group = static fn (string $id): array => [
            'GroupId' => $id, 'GroupName' => $id, 'VpcId' => 'vpc-1', 'Description' => 'Lambda', 'Tags' => [],
            'IpPermissionsEgress' => [['IpProtocol' => '-1', 'IpRanges' => [['CidrIp' => '0.0.0.0/0']]]],
        ];
        $this->mocks->handler->append(
            new Result(['Subnets' => [['SubnetId' => 'subnet-aaa', 'VpcId' => 'vpc-1', 'AvailableIpAddressCount' => 2011, 'Tags' => []]]]),
            new Result(['RouteTables' => [[
                'RouteTableId' => 'rtb-1', 'VpcId' => 'vpc-1',
                'Routes' => [['DestinationCidrBlock' => '0.0.0.0/0', 'NatGatewayId' => 'nat-1']],
                'Associations' => [['SubnetId' => 'subnet-aaa', 'Main' => false]],
            ]]]),
            new Result(['Vpcs' => [['VpcId' => 'vpc-1']]]),
            new Result(['SecurityGroups' => [$group('sg-aaa'), $group('sg-bbb')]]),
            new Result(['Vpcs' => [['VpcId' => 'vpc-1']]]),
        );
    }

    public function test_existing_vpc_plus_one_sg_adds_it(): void
    {
        $this->networkListing();
        $this->ui->confirms = [true, true];
        $this->ui->multiselects = [
            fn (string $label, array $options, array $default) => $default,
            fn (string $label, array $options, array $default) => [...$default, 'sg-bbb'],
        ];

        $tester = $this->run_(self::arcoPhp("->vpc(['subnet-aaa'], ['sg-aaa'])"), ['subnet', 'security-group']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString("->vpc(subnets: ['subnet-aaa'], securityGroups: ['sg-aaa', 'sg-bbb'])", $this->arcoPhpNow());
        $confirms = array_values(array_filter($this->ui->asked, fn (array $q) => $q[0] === 'confirm'));
        self::assertCount(2, $confirms, 'the account line and the final confirmation: a pure addition is not a replacement');
    }

    public function test_changing_the_vpc_in_use_asks_once_with_the_old_and_new_lines(): void
    {
        $this->networkListing();
        // Untick sg-aaa (in use), tick sg-bbb: a real change, asked with default no; declining keeps vpc() as is.
        $this->ui->confirms = [true, false];
        $this->ui->multiselects = [
            fn (string $label, array $options, array $default) => $default,
            ['sg-bbb'],
        ];

        $arcoPhp = self::arcoPhp("->vpc(['subnet-aaa'], ['sg-aaa'])");
        $tester = $this->run_($arcoPhp, ['subnet', 'security-group']);

        $tester->assertCommandIsSuccessful();
        $question = $this->ui->asked[3];
        self::assertSame('confirm', $question[0]);
        self::assertFalse($question[2], 'defaults to no');
        self::assertStringContainsString("->vpc(subnets: ['subnet-aaa'], securityGroups: ['sg-aaa'])", $question[1]);
        self::assertStringContainsString("->vpc(subnets: ['subnet-aaa'], securityGroups: ['sg-bbb'])", $question[1]);
        self::assertSame($arcoPhp, $this->arcoPhpNow());
        self::assertStringContainsString('Nothing to change', $tester->getDisplay());
    }

    public function test_another_region_lists_without_applying(): void
    {
        $this->account();
        $this->mocks->handler->append(new Result(['QueueUrls' => []]));
        $this->ui->confirms = [true];

        $this->project = new ProjectFixture(self::arcoPhp());
        $factory = fn (?string $profile, string $region) => $this->mocks->clients($region);
        $app = Application::create();
        $app->addCommands([new ResourcesCommand($factory, null, $this->ui)]);
        $tester = new CommandTester($app->find('resources'));
        $tester->execute(['--env' => 'staging', '--profile' => 'p', '--region' => 'eu-west-1', '--type' => ['queue']], ['interactive' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertSame(['confirm'], array_column($this->ui->asked, 0), 'nothing to pick: only the account line');
        self::assertStringContainsString('Queues', $tester->getDisplay());
        self::assertStringContainsString('eu-west-1', $tester->getDisplay());
        self::assertStringContainsString("only in the environment's region (us-east-1)", $tester->getDisplay());
        self::assertSame(self::arcoPhp(), $this->arcoPhpNow());
    }

    public function test_piped_stdin_without_n_prints_the_plain_listing_and_asks_nothing(): void
    {
        $this->account();
        $this->mocks->handler->append(new Result(['QueueUrls' => []]));

        $this->project = new ProjectFixture(self::arcoPhp());
        $factory = fn (?string $profile, string $region) => $this->mocks->clients($region);
        $app = Application::create();
        $app->addCommands([new ResourcesCommand($factory)]); // the real UI: laravel/prompts under the non-TTY STDIN
        $tester = new CommandTester($app->find('resources'));
        $tester->execute(['--env' => 'staging', '--profile' => 'p', '--type' => ['queue']], ['interactive' => true]);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        self::assertStringContainsString('Queues (0)', $display);
        self::assertStringNotContainsString("List this account's resources?", $display, 'no confirmation without a terminal');
        self::assertStringNotContainsString('Nothing to change', $display);
        self::assertSame(self::arcoPhp(), $this->arcoPhpNow());
    }

    public function test_prompts_unavailable_prints_the_listing(): void
    {
        $this->account();
        $this->mocks->handler->append(new Result(['QueueUrls' => []]));
        $this->ui->confirms = [fn () => throw new PromptsUnavailable('stty: not a tty')];

        $tester = $this->run_(self::arcoPhp(), ['queue']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('stty: not a tty', $tester->getDisplay());
        self::assertStringContainsString('Queues (0)', $tester->getDisplay());
        self::assertStringContainsString('Nothing was written', $tester->getDisplay());
        self::assertSame(self::arcoPhp(), $this->arcoPhpNow());
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

    public function test_fifo_queue_is_written_as_fifo(): void
    {
        $url = 'https://sqs.us-east-1.amazonaws.com/111111111111/shop-staging-orders.fifo';
        $this->account();
        $this->mocks->handler->append(
            new Result(['QueueUrls' => [$url]]),
            new Result(['Attributes' => ['QueueArn' => 'arn:aws:sqs:us-east-1:111111111111:shop-staging-orders.fifo', 'FifoQueue' => 'true']]),
            new Result(['Tags' => []]),
        );
        $this->ui->confirms = [true, true];
        $this->ui->multiselects = [[$url]];
        $this->ui->texts = ['orders'];

        $tester = $this->run_(self::arcoPhp(), ['queue']);

        $tester->assertCommandIsSuccessful();
        $line = "->queue('orders', existing: '$url', fifo: true)";
        self::assertStringContainsString("+ $line", $tester->getDisplay());
        self::assertStringContainsString("arco queue:add orders --existing=$url --fifo --env=staging", $tester->getDisplay());
        self::assertStringContainsString($line, $this->arcoPhpNow());
    }

    public function test_endpoints_arco_created_are_not_offered(): void
    {
        $this->account();
        $this->mocks->handler->append(
            new Result(['VpcEndpoints' => [
                ['VpcEndpointId' => 'vpce-arco', 'VpcEndpointType' => 'Gateway', 'ServiceName' => 'com.amazonaws.us-east-1.s3', 'VpcId' => 'vpc-1', 'State' => 'available',
                    'RouteTableIds' => ['rtb-1'], 'Tags' => [['Key' => 'arco:managed', 'Value' => 'gateway-endpoint']]],
                ['VpcEndpointId' => 'vpce-mine', 'VpcEndpointType' => 'Gateway', 'ServiceName' => 'com.amazonaws.us-east-1.dynamodb', 'VpcId' => 'vpc-1', 'State' => 'available',
                    'RouteTableIds' => ['rtb-1']],
            ]]),
            new Result(['Vpcs' => [['VpcId' => 'vpc-1']]]),
        );
        $offered = [];
        $this->ui->confirms = [true];
        $this->ui->multiselects = [function (string $label, array $options) use (&$offered): array {
            $offered = array_keys($options);
            return [];
        }];

        // Even with --all: another environment's release could delete it, so it is never picked by id.
        $tester = $this->run_(self::arcoPhp(), ['endpoint'], ['--all' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertSame(['vpce-mine'], $offered);
    }

    public function test_a_table_shows_one_line_for_its_keys(): void
    {
        $this->account();
        $this->mocks->handler->append(
            new Result(['TableNames' => ['shop-staging-cache']]),
            new Result(['Table' => [
                'TableArn' => 'arn:aws:dynamodb:us-east-1:111111111111:table/shop-staging-cache',
                'KeySchema' => [['AttributeName' => 'key', 'KeyType' => 'HASH']],
                'AttributeDefinitions' => [['AttributeName' => 'key', 'AttributeType' => 'S']],
            ]]),
            new Result(['TimeToLiveDescription' => []]),
            new Result(['Tags' => []]),
        );
        $labels = [];
        $this->ui->confirms = [true];
        $this->ui->multiselects = [function (string $label, array $options) use (&$labels): array {
            $labels = array_values($options);
            return [];
        }];

        $tester = $this->run_(self::arcoPhp(), ['table']);

        $tester->assertCommandIsSuccessful();
        // The row stays short (prompts cuts it at the terminal's width); the warning is printed in full above the list.
        self::assertSame(['shop-staging-cache · ! 1'], $labels);
        $display = $tester->getDisplay();
        self::assertSame(1, substr_count($display, '! Its keys fit only as the cache.'));
        self::assertStringNotContainsString('failedJobs', $display);
    }

    public function test_rows_are_short_and_the_details_are_printed_above_the_list(): void
    {
        $this->account();
        $this->mocks->handler->append(
            new Result(['CertificateSummaryList' => [['CertificateArn' => self::CERT]]]),
            new Result(['Certificate' => [
                'CertificateArn' => self::CERT, 'DomainName' => '*.other.com', 'SubjectAlternativeNames' => ['*.other.com'],
                'Status' => 'ISSUED', 'NotAfter' => (int) strtotime('2027-06-01T00:00:00Z'), 'InUseBy' => [],
            ]]),
        );
        $labels = [];
        $this->ui->confirms = [true];
        $this->ui->multiselects = [function (string $label, array $options) use (&$labels): array {
            $labels = $options;
            return [];
        }];

        $tester = $this->run_(self::arcoPhp("->domain('shop.example.com')"), ['certificate']);

        $tester->assertCommandIsSuccessful();
        self::assertSame([self::CERT => '*.other.com · ! 1 · abcd-1234'], $labels);
        $display = $tester->getDisplay();
        self::assertStringContainsString('*.other.com  ' . self::CERT, $display, 'the full ARN, which the row leaves out');
        self::assertStringContainsString('! ', $display);
        self::assertLessThan(strpos($display, '! '), strpos($display, 'Certificates'), 'the type title comes first');
    }

    public function test_a_vpc_without_security_groups_is_asked_again_before_names(): void
    {
        $this->networkListing();
        $this->mocks->handler->append(
            new Result(['Buckets' => [['Name' => 'shop-uploads', 'CreationDate' => '2026-02-01T00:00:00+00:00']]]),
            new Result([]),
            new Result(['TagSet' => []]),
        );
        $this->ui->confirms = [true, true];
        // The subnet without a group: refused once the network lists are answered, then both asked again.
        $this->ui->multiselects = [['subnet-aaa'], [], ['subnet-aaa'], ['sg-aaa'], ['shop-uploads']];
        $this->ui->texts = ['files'];

        $tester = $this->run_(self::arcoPhp(), ['subnet', 'security-group', 'bucket']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('vpc() needs at least one subnet and one security group', $tester->getDisplay());
        $kinds = array_column($this->ui->asked, 0);
        self::assertSame(['confirm', 'multiselect', 'multiselect', 'multiselect', 'multiselect', 'multiselect', 'text', 'confirm'], $kinds);
        self::assertSame(['subnet-aaa'], $this->ui->asked[3][2], 'asked again with the ticks kept');
        self::assertStringContainsString("->vpc(subnets: ['subnet-aaa'], securityGroups: ['sg-aaa'])", $this->arcoPhpNow());
        self::assertStringContainsString("->storage('files', existing: 'shop-uploads')", $this->arcoPhpNow());
    }

    public function test_removing_an_inherited_vpc_previews_vpc_false_on_the_environment(): void
    {
        $this->networkListing();
        $this->ui->confirms = [true, true, true];
        $this->ui->multiselects = [[], []];
        $arcoPhp = <<<'PHP'
            <?php
            use Multiapi\Arco\Config\App;
            use Multiapi\Arco\Config\Environment;

            return App::make('shop')
                ->region('us-east-1')
                ->vpc(['subnet-aaa'], ['sg-aaa'])
                ->environment('staging', fn (Environment $env) => $env->timeout(30));
            PHP;

        $tester = $this->run_($arcoPhp, ['subnet', 'security-group']);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        // The environment sets no vpc() of its own: nothing to replace there, it opts out with ->vpc(false).
        self::assertStringContainsString("Change ->vpc(subnets: ['subnet-aaa'], securityGroups: ['sg-aaa']) (arco.php) to ->vpc(false)?", $this->ui->asked[3][1]);
        self::assertStringContainsString('+ ->vpc(false)', $display);
        self::assertStringNotContainsString('- ->vpc(', $display);
        self::assertStringContainsString('arco vpc:remove --env=staging', $display);
        self::assertStringContainsString("fn (Environment \$env) => \$env\n        ->vpc(false)->timeout(30)", $this->arcoPhpNow());
        self::assertStringContainsString("->vpc(['subnet-aaa'], ['sg-aaa'])", $this->arcoPhpNow(), "the app's vpc() stays");
    }
}
