<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Commands;

use Aws\Acm\Exception\AcmException;
use Aws\Ec2\Exception\Ec2Exception;
use Aws\Result;
use Aws\Route53\Exception\Route53Exception;
use Aws\Sqs\Exception\SqsException;
use Aws\Sts\Exception\StsException;
use Multiapi\Arco\Cli\Application;
use Multiapi\Arco\Commands\ResourcesCommand;
use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\ConfigLayer;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Config\Environment;
use Multiapi\Arco\Resources\Inventory;
use Multiapi\Arco\Resources\ResourceItem;
use Multiapi\Arco\Resources\ResourceType;
use Multiapi\Arco\Tests\Unit\Deploy\AwsMocks;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Console\Tester\CommandTester;

final class ResourcesCommandTest extends TestCase
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

    private const QUEUE_URL = 'https://sqs.us-east-1.amazonaws.com/111111111111/shop-staging-emails';
    private const FIFO_URL = 'https://sqs.us-east-1.amazonaws.com/111111111111/shop-staging-orders.fifo';
    private const CERT = 'arn:aws:acm:us-east-1:111111111111:certificate/abcd-1234';

    private ?ProjectFixture $project = null;
    private AwsMocks $mocks;
    /** @var list<array{?string, string}> profile and region of every client the command built */
    private array $factoryCalls = [];

    protected function setUp(): void
    {
        $this->mocks = new AwsMocks();
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
                ->environment('staging', fn (Environment \$env) => \$env$staging);
            PHP;
    }

    /**
     * The staging environment of the contract run: a VPC with an endpoint Arco manages, a domain whose DNS profile
     * cannot be read, an existing bucket.
     */
    private const STAGING = "->vpc(['subnet-aaa'], ['sg-aaa'], ['s3'])->domain('shop.example.com')->dns('acme-prod')->storage('uploads', existing: 'shop-uploads')";

    private function command(): ResourcesCommand
    {
        return new ResourcesCommand(
            function (?string $profile, string $region) {
                $this->factoryCalls[] = [$profile, $region];
                return $this->mocks->clients($region);
            },
            static fn (): int => (int) strtotime('2026-10-30T00:00:00Z'),
        );
    }

    /** @param array<string, mixed> $options */
    private function run_(?string $arcoPhp, array $options, bool $interactive = false): CommandTester
    {
        $this->project ??= new ProjectFixture($arcoPhp);
        $tester = $this->project->tester($this->command());
        $tester->execute($options, ['interactive' => $interactive, 'capture_stderr_separately' => true]);
        return $tester;
    }

    /** @return array<string, mixed> */
    private static function json(CommandTester $tester): array
    {
        $json = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($json);
        return $json;
    }

    private function account(): void
    {
        $this->mocks->handler->append(new Result(['Account' => '111111111111']));
    }

    private function subnets(): void
    {
        $this->mocks->handler->append(
            new Result(['Subnets' => [[
                'SubnetId' => 'subnet-aaa', 'VpcId' => 'vpc-1', 'AvailabilityZoneId' => 'use1-az1', 'CidrBlock' => '10.0.8.0/21', 'AvailableIpAddressCount' => 2011,
                'Tags' => [['Key' => 'Name', 'Value' => 'dev-lambda-1a'], ['Key' => 'aws:cloudformation:stack-name', 'Value' => 'multiapi-dev-vpc']],
            ]]]),
            new Result(['RouteTables' => [[
                'RouteTableId' => 'rtb-1', 'VpcId' => 'vpc-1',
                'Routes' => [['DestinationCidrBlock' => '0.0.0.0/0', 'NatGatewayId' => 'nat-1']],
                'Associations' => [['SubnetId' => 'subnet-aaa', 'Main' => false]],
            ]]]),
            new Result(['Vpcs' => [['VpcId' => 'vpc-1', 'Tags' => [['Key' => 'Name', 'Value' => 'dev']]]]]),
        );
    }

    private function securityGroups(): void
    {
        $this->mocks->handler->append(
            new Result(['SecurityGroups' => [[
                'GroupId' => 'sg-aaa', 'GroupName' => 'lambda', 'VpcId' => 'vpc-1', 'Description' => 'Lambda functions', 'Tags' => [],
                'IpPermissionsEgress' => [['IpProtocol' => '-1', 'IpRanges' => [['CidrIp' => '0.0.0.0/0']]]],
            ]]]),
            new Result(['Vpcs' => [['VpcId' => 'vpc-1', 'Tags' => [['Key' => 'Name', 'Value' => 'dev']]]]]),
        );
    }

    private function endpoints(): void
    {
        $this->mocks->handler->append(
            new Result(['VpcEndpoints' => [[
                'VpcEndpointId' => 'vpce-0123abcd', 'VpcEndpointType' => 'Gateway', 'ServiceName' => 'com.amazonaws.us-east-1.s3',
                'VpcId' => 'vpc-1', 'State' => 'available', 'RouteTableIds' => ['rtb-2'],
            ]]]),
            new Result(['Vpcs' => [['VpcId' => 'vpc-1', 'Tags' => [['Key' => 'Name', 'Value' => 'dev']]]]]),
        );
    }

    /** Arco's code bucket (hidden) and an app bucket. */
    private function buckets(): void
    {
        $this->mocks->handler->append(
            new Result(['Buckets' => [
                ['Name' => 'arco-111111111111-us-east-1', 'CreationDate' => '2026-01-01T00:00:00+00:00'],
                ['Name' => 'shop-uploads', 'CreationDate' => '2026-02-01T00:00:00+00:00'],
            ]]),
            new Result([]),
            new Result(['TagSet' => []]),
            new Result([]),
            new Result(['TagSet' => [['Key' => 'team', 'Value' => 'web']]]),
        );
    }

    private function queues(): void
    {
        $this->mocks->handler->append(
            new Result(['QueueUrls' => [self::QUEUE_URL]]),
            new Result(['Attributes' => ['QueueArn' => 'arn:aws:sqs:us-east-1:111111111111:shop-staging-emails', 'VisibilityTimeout' => '60']]),
            new Result(['Tags' => []]),
        );
    }

    /** A standard queue and a FIFO one. */
    private function queuesWithFifo(): void
    {
        $this->mocks->handler->append(
            new Result(['QueueUrls' => [self::QUEUE_URL, self::FIFO_URL]]),
            new Result(['Attributes' => ['QueueArn' => 'arn:aws:sqs:us-east-1:111111111111:shop-staging-emails', 'VisibilityTimeout' => '60']]),
            new Result(['Tags' => []]),
            new Result(['Attributes' => ['QueueArn' => 'arn:aws:sqs:us-east-1:111111111111:shop-staging-orders.fifo', 'FifoQueue' => 'true', 'VisibilityTimeout' => '30']]),
            new Result(['Tags' => []]),
        );
    }

    /** One issued certificate for another domain, used by one distribution. */
    private function certificates(): void
    {
        $this->mocks->handler->append(
            new Result(['CertificateSummaryList' => [['CertificateArn' => self::CERT]]]),
            new Result(['Certificate' => [
                'CertificateArn' => self::CERT, 'DomainName' => 'old.example.com', 'SubjectAlternativeNames' => ['old.example.com'],
                'Status' => 'ISSUED', 'NotAfter' => (int) strtotime('2027-06-01T00:00:00Z'), 'InUseBy' => ['arn:aws:cloudfront::111111111111:distribution/E1'],
            ]]),
        );
    }

    private function securityGroupsDenied(): void
    {
        $this->mocks->handler->append(AwsMocks::error(Ec2Exception::class, 'DescribeSecurityGroups', 'UnauthorizedOperation', 403));
    }

    private function tables(): void
    {
        $this->mocks->handler->append(
            new Result(['TableNames' => ['shop-staging-cache']]),
            new Result(['Table' => [
                'TableArn' => 'arn:aws:dynamodb:us-east-1:111111111111:table/shop-staging-cache',
                'KeySchema' => [['AttributeName' => 'key', 'KeyType' => 'HASH']],
                'AttributeDefinitions' => [['AttributeName' => 'key', 'AttributeType' => 'S']],
                'BillingModeSummary' => ['BillingMode' => 'PAY_PER_REQUEST'],
            ]]),
            new Result(['TimeToLiveDescription' => ['TimeToLiveStatus' => 'ENABLED', 'AttributeName' => 'expires_at']]),
            new Result(['Tags' => []]),
        );
    }

    private function certificatesDenied(): void
    {
        $this->mocks->handler->append(AwsMocks::error(AcmException::class, 'ListCertificates', 'AccessDeniedException', 400));
    }

    private function zone(string $id, string $name): Result
    {
        return new Result(['HostedZones' => [['Id' => "/hostedzone/$id", 'Name' => "$name.", 'Config' => ['PrivateZone' => false], 'ResourceRecordSetCount' => 5]], 'IsTruncated' => false]);
    }

    /**
     * Every type, in the order the command lists them: the security groups are denied, and the DNS profile's zones
     * cannot be read (the deploy account has both).
     */
    private function everything(): void
    {
        $this->account();
        $this->listings();
    }

    /** What the contract run lists, after the account. */
    private function listings(): void
    {
        $this->subnets();
        $this->securityGroupsDenied();
        $this->endpoints();
        $this->buckets();
        $this->queuesWithFifo();
        $this->tables();
        $this->certificates();
        $this->mocks->handler->append(
            new Result(['HostedZones' => [
                ['Id' => '/hostedzone/Z1', 'Name' => 'example.com.', 'Config' => ['PrivateZone' => false], 'ResourceRecordSetCount' => 5],
                ['Id' => '/hostedzone/Z2', 'Name' => 'shop.example.com.', 'Config' => ['PrivateZone' => false], 'ResourceRecordSetCount' => 5],
            ], 'IsTruncated' => false]),
            AwsMocks::error(Route53Exception::class, 'ListHostedZones', 'AccessDenied', 403),
        );
    }

    public function test_json_shape_matches_the_contract_fixture(): void
    {
        $this->everything();

        $tester = $this->run_(self::arcoPhp(self::STAGING), ['--env' => 'staging', '--profile' => 'p', '--json' => true]);

        $tester->assertCommandIsSuccessful();
        $fixture = (string) file_get_contents(__DIR__ . '/fixtures/resources-contract.json');
        $normalize = static fn (string $json): string => (string) json_encode(json_decode($json, flags: JSON_THROW_ON_ERROR), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        // Key order, {} versus [] and every value: the document Arco Cloud parses.
        self::assertSame($normalize($fixture), $normalize($tester->getDisplay()));

        $json = self::json($tester);
        self::assertSame(['schema', 'account', 'region', 'environment', 'vpc', 'types', 'warnings'], array_keys($json));
        self::assertSame(['subnet', 'security-group', 'endpoint', 'bucket', 'queue', 'table', 'certificate', 'zone'], array_keys($json['types']));
        // The endpoint Arco manages has no item: only `vpc` says it is there, for the one vpc:set to keep.
        self::assertSame([['service' => 's3', 'id' => null]], $json['vpc']['endpoints']);
        self::assertSame([['key', 'params', 'message']], array_map('array_keys', $json['warnings']));
        self::assertSame('resources.dns_profile_failed', $json['warnings'][0]['key']);
        self::assertStringNotContainsString('Listing', $tester->getErrorOutput(), 'no progress lines with --json');
        self::assertStringContainsString('acme-prod', $tester->getErrorOutput(), 'the warning is a diagnostic on stderr too');
        self::assertSame([], array_values(array_diff($this->mocks->calls, self::READ_ONLY)), 'read-only AWS calls only');
        self::assertSame([['p', 'us-east-1'], ['acme-prod', 'us-east-1']], $this->factoryCalls);
    }

    public function test_json_vpc_is_the_environment_resolved_vpc(): void
    {
        $vpc = function (string $staging, ?string $layer = null): mixed {
            $this->project?->restore();
            $this->project = new ProjectFixture(self::arcoPhp($staging));
            $this->account();
            $options = ['--env' => 'staging', '--profile' => 'p', '--type' => ['queue'], '--json' => true];
            if ($layer !== null) {
                $options['--config-layer'] = $this->project->layer(['schema' => 1, 'name' => 'shop', 'region' => 'us-east-1', 'environments' => ['staging' => json_decode($layer, true)]]);
            }
            $this->mocks->handler->append(new Result(['QueueUrls' => []]));
            $tester = $this->run_(null, $options);
            $tester->assertCommandIsSuccessful();
            return self::json($tester)['vpc'];
        };

        self::assertNull($vpc(''));
        self::assertFalse($vpc('->vpc(false)'));
        self::assertSame(
            ['subnets' => ['subnet-aaa'], 'securityGroups' => ['sg-aaa'], 'endpoints' => [['service' => 's3', 'id' => null], ['service' => 'dynamodb', 'id' => 'vpce-0abc']], 'source' => 'arco.php'],
            $vpc("->vpc(['subnet-aaa'], ['sg-aaa'], ['s3', 'dynamodb' => 'vpce-0abc'])"),
        );
        self::assertSame(
            ['subnets' => ['subnet-c'], 'securityGroups' => ['sg-c'], 'endpoints' => [], 'source' => 'cloud'],
            $vpc('', '{"settings": {"vpc": {"subnets": ["subnet-c"], "securityGroups": ["sg-c"]}}}'),
        );
    }

    public function test_plain_output_names_the_use_a_table_fits_in_one_line(): void
    {
        $this->account();
        $this->tables();

        $tester = $this->run_(self::arcoPhp(), ['--profile' => 'p', '--type' => ['table']]);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        self::assertSame(1, substr_count($display, '! '), 'one line for the table\'s keys');
        self::assertStringContainsString('Its keys fit only as the cache.', $display);
        self::assertStringNotContainsString('failedJobs use', $display);
        self::assertStringContainsString('arco table:add cache-table --existing=shop-staging-cache --env=staging', $display);
    }

    public function test_plain_output_when_no_use_fits(): void
    {
        $this->account();
        $this->mocks->handler->append(
            new Result(['TableNames' => ['orders']]),
            new Result(['Table' => ['TableArn' => 'arn:aws:dynamodb:us-east-1:111111111111:table/orders', 'KeySchema' => [['AttributeName' => 'id', 'KeyType' => 'HASH']], 'AttributeDefinitions' => [['AttributeName' => 'id', 'AttributeType' => 'N']]]]),
            new Result(['TimeToLiveDescription' => []]),
            new Result(['Tags' => []]),
        );

        $tester = $this->run_(self::arcoPhp(), ['--profile' => 'p', '--type' => ['table']]);

        $display = $tester->getDisplay();
        self::assertSame(1, substr_count($display, '! '));
        self::assertStringContainsString('Its keys fit no use: as a table it needs PK (S, HASH), SK (S, RANGE); as the cache, key (S, HASH); as the failed-jobs table, application (S, HASH), uuid (S, RANGE).', $display);
    }

    public function test_one_type_denied_others_listed(): void
    {
        $this->account();
        $this->subnets();
        $this->certificatesDenied();

        $tester = $this->run_(self::arcoPhp(), ['--profile' => 'p', '--type' => ['subnet', 'certificate'], '--json' => true]);

        $tester->assertCommandIsSuccessful();
        $json = self::json($tester);
        self::assertTrue($json['types']['subnet']['ok']);
        self::assertSame(['subnet-aaa'], array_column($json['types']['subnet']['items'], 'id'));
        self::assertFalse($json['types']['certificate']['ok']);
        self::assertSame('resources.permission_denied', $json['types']['certificate']['error']['key']);
        self::assertSame(['action' => 'acm:ListCertificates'], $json['types']['certificate']['error']['params']);
        self::assertStringContainsString('acm:ListCertificates', $json['types']['certificate']['error']['message']);
    }

    public function test_other_failures_name_the_aws_code_and_s3_list_buckets_is_list_all_my_buckets(): void
    {
        $this->account();
        $this->mocks->handler->append(
            AwsMocks::error(\Aws\S3\Exception\S3Exception::class, 'ListBuckets', 'AccessDenied', 403),
            AwsMocks::error(SqsException::class, 'ListQueues', 'InternalError', 400),
        );

        $tester = $this->run_(self::arcoPhp(), ['--profile' => 'p', '--type' => ['bucket', 'queue'], '--json' => true]);

        $json = self::json($tester);
        self::assertSame(['key' => 'resources.permission_denied', 'params' => ['action' => 's3:ListAllMyBuckets']], array_intersect_key($json['types']['bucket']['error'], ['key' => 1, 'params' => 1]));
        self::assertSame(['key' => 'resources.list_failed', 'params' => ['code' => 'InternalError']], array_intersect_key($json['types']['queue']['error'], ['key' => 1, 'params' => 1]));
    }

    public function test_type_filter(): void
    {
        $this->account();
        $this->buckets();
        $this->queues();

        $tester = $this->run_(self::arcoPhp(), ['--profile' => 'p', '--type' => ['queue', 'bucket'], '--json' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertSame(['bucket', 'queue'], array_keys(self::json($tester)['types']));
        self::assertSame(['GetCallerIdentity', 'ListBuckets', 'GetBucketLocation', 'GetBucketTagging', 'GetBucketLocation', 'GetBucketTagging', 'ListQueues', 'GetQueueAttributes', 'ListQueueTags'], $this->mocks->calls);
    }

    public function test_unknown_type_is_refused(): void
    {
        $tester = $this->run_(self::arcoPhp(), ['--profile' => 'p', '--type' => ['rds'], '--json' => true]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertSame(['schema' => 1, 'error' => 'Unknown resource type "rds". Types: subnet, security-group, endpoint, bucket, queue, table, certificate, zone.'], self::json($tester));
        self::assertSame([], $this->mocks->calls);
    }

    public function test_endpoints_without_subnets_list_no_subnets(): void
    {
        $this->account();
        $this->endpoints();

        $tester = $this->run_(self::arcoPhp("->vpc(['subnet-aaa'], ['sg-aaa'])"), ['--profile' => 'p', '--type' => ['endpoint'], '--json' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertSame(['GetCallerIdentity', 'DescribeVpcEndpoints', 'DescribeVpcs'], $this->mocks->calls);
        self::assertSame([], self::json($tester)['types']['endpoint']['items'][0]['warnings'], 'without the subnets, no endpoint_missing_routes');
    }

    public function test_hidden_left_out_of_plain_output_unless_all(): void
    {
        $this->account();
        $this->buckets();
        $tester = $this->run_(self::arcoPhp(), ['--profile' => 'p', '--type' => ['bucket']]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('shop-uploads', $tester->getDisplay());
        self::assertStringNotContainsString('arco-111111111111-us-east-1', $tester->getDisplay());
        self::assertStringContainsString('1 hidden', $tester->getDisplay());

        $this->account();
        $this->buckets();
        $tester = $this->run_(null, ['--profile' => 'p', '--type' => ['bucket'], '--all' => true]);
        self::assertStringContainsString('arco-111111111111-us-east-1', $tester->getDisplay());

        $this->account();
        $this->buckets();
        $tester = $this->run_(null, ['--profile' => 'p', '--type' => ['bucket'], '--json' => true]);
        $items = self::json($tester)['types']['bucket']['items'];
        self::assertSame(['shop-uploads' => false, 'arco-111111111111-us-east-1' => true], array_column($items, 'hidden', 'id'), 'the bucket matching the app sorts first');
    }

    public function test_non_tty_plain_table_never_prompts(): void
    {
        $this->everything();
        $arcoPhp = self::arcoPhp(self::STAGING);

        $tester = $this->run_($arcoPhp, ['--env' => 'staging', '--profile' => 'p']);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        foreach (['Subnets', 'Security groups', 'Gateway endpoints', 'Buckets', 'Queues', 'Tables', 'Certificates', 'Hosted zones'] as $title) {
            self::assertStringContainsString($title, $display);
        }
        self::assertStringContainsString('dev-lambda-1a', $display);
        self::assertStringContainsString('in use (arco.php)', $display);
        self::assertStringContainsString('arco vpc:set --subnet=subnet-aaa --env=staging', $display);
        self::assertStringContainsString('arco queue:add emails --existing=' . self::QUEUE_URL . ' --env=staging', $display);
        self::assertStringContainsString('arco cache:add --existing=shop-staging-cache --env=staging', $display);
        self::assertStringContainsString('Not attached to the route tables of the subnets in use: rtb-1.', $display);
        self::assertStringContainsString('Missing permission ec2:DescribeSecurityGroups', $display);
        self::assertStringContainsString('Its keys fit only as the cache.', $display);
        self::assertStringContainsString('Nothing was written', $display);
        self::assertStringContainsString('Listing subnets', $tester->getErrorOutput());
        self::assertSame($arcoPhp, file_get_contents((string) $this->project?->dir . '/arco.php'), 'arco.php unchanged');
    }

    public function test_apply_commands_quote_what_a_shell_would_split(): void
    {
        self::assertSame("arco storage:add uploads '--existing=my bucket' --env=staging", ResourcesCommand::shellLine(['command' => 'storage:add', 'args' => ['uploads', '--existing=my bucket', '--env=staging']]));
        self::assertSame("arco table:add 'it'\\''s' --env=staging", ResourcesCommand::shellLine(['command' => 'table:add', 'args' => ["it's", '--env=staging']]));
    }

    public function test_requires_arco_php_or_layer(): void
    {
        $tester = $this->run_(null, ['--profile' => 'p', '--json' => true]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringStartsWith('No arco.php found at ', self::json($tester)['error']);
        self::assertSame([], $this->mocks->calls);
    }

    public function test_runs_with_only_a_layer(): void
    {
        $this->project = new ProjectFixture();
        $layer = $this->project->layer([
            'schema' => 1, 'name' => 'shop', 'region' => 'us-east-1',
            'environments' => ['production' => ['storage' => ['uploads' => ['existing' => 'shop-uploads']]]],
        ]);
        $this->account();
        $this->buckets();

        $tester = $this->run_(null, ['--config-layer' => $layer, '--profile' => 'p', '--type' => ['bucket'], '--json' => true]);

        $tester->assertCommandIsSuccessful();
        $json = self::json($tester);
        self::assertSame('production', $json['environment']);
        $uploads = array_values(array_filter($json['types']['bucket']['items'], fn (array $item) => $item['id'] === 'shop-uploads'))[0];
        self::assertSame(['source' => 'cloud'], $uploads['inUse']);
        self::assertFileDoesNotExist($this->project->dir . '/arco.php');
    }

    /**
     * Arco Cloud calls Inventory in-process (docs/config-layer.md): its items are the --json items, its errors and
     * warnings the --json ones without `message`. It knows the account, so nothing asks STS.
     */
    public function test_library_inventory_gives_the_contract_items(): void
    {
        $this->listings();
        $config = ConfigResolver::resolve(App::make('shop')->region('us-east-1')
            ->environment('staging', fn (Environment $env) => $env->vpc(['subnet-aaa'], ['sg-aaa'], ['s3'])->domain('shop.example.com')->dns('acme-prod')->storage('uploads', existing: 'shop-uploads'))
            ->toArray(), 'staging')->withAccount('111111111111');
        $inventory = new Inventory('p', 'acme-prod', static fn (): int => (int) strtotime('2026-10-30T00:00:00Z'));

        $result = $inventory->collect($config, $this->mocks->clients(), $this->mocks->clients(), ResourceType::cases(), static function (ResourceType $type): void {
        });

        $fixture = json_decode((string) file_get_contents(__DIR__ . '/fixtures/resources-contract.json'), true, flags: JSON_THROW_ON_ERROR);
        $plain = static fn (mixed $value): mixed => json_decode((string) json_encode($value), true, flags: JSON_THROW_ON_ERROR);
        $withoutMessage = static fn (array $error): array => ['key' => $error['key'], 'params' => $error['params']];
        self::assertSame(array_keys($fixture['types']), array_keys($result));
        foreach ($fixture['types'] as $type => $expected) {
            if ($expected['ok']) {
                self::assertTrue($result[$type]['ok'], $type);
                self::assertSame($expected['items'], $plain(array_map(fn (ResourceItem $item) => $item->toArray(), $result[$type]['items'])), $type);
            } else {
                self::assertSame($withoutMessage($expected['error']), $result[$type]['error'] ?? null, $type);
            }
        }
        self::assertSame(array_map($withoutMessage, $fixture['warnings']), $inventory->warnings);
        self::assertNotContains('GetCallerIdentity', $this->mocks->calls);
    }

    public function test_library_inventory_with_only_a_layer(): void
    {
        $this->project = new ProjectFixture();
        $layer = ConfigLayer::load($this->project->layer([
            'schema' => 1, 'name' => 'shop', 'region' => 'us-east-1',
            'environments' => ['production' => ['storage' => ['uploads' => ['existing' => 'shop-uploads']]]],
        ]));
        $this->buckets();

        $config = ConfigResolver::resolve([], 'production', $layer)->withAccount('111111111111');
        $result = (new Inventory('cloud'))->collect($config, $this->mocks->clients(), null, [ResourceType::Bucket], static function (ResourceType $type): void {
        });

        self::assertTrue($result['bucket']['ok']);
        $uploads = array_values(array_filter($result['bucket']['items'], fn (ResourceItem $item) => $item->id === 'shop-uploads'))[0];
        self::assertSame(['source' => 'cloud'], $uploads->inUse);
        self::assertFileDoesNotExist($this->project->dir . '/arco.php');
    }

    public function test_region_flag_overrides(): void
    {
        $this->account();
        $this->queues();

        $tester = $this->run_(self::arcoPhp(), ['--profile' => 'p', '--region' => 'eu-west-1', '--type' => ['queue'], '--json' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertSame('eu-west-1', self::json($tester)['region']);
        self::assertSame([['p', 'eu-west-1']], $this->factoryCalls);
        self::assertStringContainsString('eu-west-1', $this->mocks->hosts[1]);
    }

    public function test_dns_profile_defaults_to_the_dns_setting(): void
    {
        $this->account();
        $this->mocks->handler->append($this->zone('Z1', 'example.com'), $this->zone('Z2', 'shop.example.com'));

        $tester = $this->run_(self::arcoPhp("->domain('shop.example.com')->dns('acme-prod')"), ['--profile' => 'p', '--type' => ['zone'], '--json' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertSame([['p', 'us-east-1'], ['acme-prod', 'us-east-1']], $this->factoryCalls);
        $zones = self::json($tester)['types']['zone']['items'];
        self::assertSame(['Z2' => 'acme-prod', 'Z1' => 'p'], array_column($zones, 'account', 'id'));
        self::assertSame(['source' => 'arco.php'], $zones[0]['inUse'], 'the zone serving the domain sorts first');
    }

    public function test_dns_profile_flag_wins_and_cloudflare_has_none(): void
    {
        $this->account();
        $this->mocks->handler->append($this->zone('Z1', 'example.com'), $this->zone('Z2', 'other.com'));
        $tester = $this->run_(self::arcoPhp("->domain('shop.example.com')->dns('acme-prod')"), ['--profile' => 'p', '--dns-profile' => 'other', '--type' => ['zone'], '--json' => true]);
        $tester->assertCommandIsSuccessful();
        self::assertSame([['p', 'us-east-1'], ['other', 'us-east-1']], $this->factoryCalls);

        $this->project?->restore();
        $this->project = null;
        $this->factoryCalls = [];
        $this->account();
        $this->mocks->handler->append($this->zone('Z1', 'example.com'));
        $tester = $this->run_(self::arcoPhp("->domain('shop.example.com')->dns('cloudflare')"), ['--profile' => 'p', '--type' => ['zone'], '--json' => true]);
        $tester->assertCommandIsSuccessful();
        self::assertSame([['p', 'us-east-1']], $this->factoryCalls);
    }

    public function test_dns_profile_failure_keeps_the_deploy_zones(): void
    {
        $this->account();
        $this->mocks->handler->append($this->zone('Z1', 'example.com'), AwsMocks::error(Route53Exception::class, 'ListHostedZones', 'InvalidClientTokenId', 403, 'The security token is invalid'));

        $tester = $this->run_(self::arcoPhp("->domain('shop.example.com')->dns('acme-prod')"), ['--profile' => 'p', '--type' => ['zone'], '--json' => true]);

        $tester->assertCommandIsSuccessful();
        $json = self::json($tester);
        self::assertTrue($json['types']['zone']['ok']);
        self::assertSame(['Z1'], array_column($json['types']['zone']['items'], 'id'));
        self::assertSame('resources.dns_profile_failed', $json['warnings'][0]['key']);
        self::assertSame(['profile' => 'acme-prod', 'error' => 'InvalidClientTokenId'], $json['warnings'][0]['params']);
        self::assertStringContainsString('acme-prod', $json['warnings'][0]['message']);
        self::assertStringContainsString('acme-prod', $tester->getErrorOutput());
    }

    public function test_json_error_document(): void
    {
        $this->mocks->handler->append(AwsMocks::error(StsException::class, 'GetCallerIdentity', 'ExpiredToken', 400, 'The security token included in the request is expired'));

        $tester = $this->run_(self::arcoPhp(), ['--profile' => 'p', '--json' => true], interactive: true);

        self::assertSame(1, $tester->getStatusCode());
        $json = self::json($tester);
        self::assertSame(['schema', 'error'], array_keys($json));
        self::assertSame(1, $json['schema']);
        self::assertIsString($json['error']);
        self::assertStringContainsString('p', $json['error'], 'names the profile');
        self::assertStringContainsString('expired', $json['error']);
        self::assertSame(['GetCallerIdentity'], $this->mocks->calls);
    }

    public function test_spanish_plain_output(): void
    {
        $this->project = new ProjectFixture(self::arcoPhp());
        $this->account();
        $this->buckets();
        $app = Application::create();
        $app->addCommands([$this->command()]);
        $app->setAutoExit(false);
        $tester = new ApplicationTester($app);

        $tester->run(['command' => 'resources', '--profile' => 'p', '--type' => ['bucket'], '--lang' => 'es'], ['interactive' => false]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Buckets', $tester->getDisplay());
        self::assertStringContainsString('1 oculto', $tester->getDisplay());
        self::assertStringContainsString('No se escribió nada', $tester->getDisplay());
    }
}
