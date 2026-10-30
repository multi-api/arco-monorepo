<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Resources;

use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ConfigLayer;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Config\Environment;
use Multiapi\Arco\Resources\Annotator;
use Multiapi\Arco\Resources\ResourceItem;
use Multiapi\Arco\Resources\ResourceType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AnnotatorTest extends TestCase
{
    private const ACCOUNT = '943653377281';
    private const CODE_BUCKET = 'arco-code-943653377281-us-east-1';
    private const CERT = 'arn:aws:acm:us-east-1:943653377281:certificate/aaaa';
    private const OTHER_CERT = 'arn:aws:acm:us-east-1:943653377281:certificate/bbbb';
    private const QUEUE_URL = 'https://sqs.us-east-1.amazonaws.com/943653377281/legacy-jobs';

    /**
     * @param (callable(Environment): mixed)|null $env
     * @param array<string, mixed>|null $layer
     */
    private static function config(?callable $env = null, ?array $layer = null, string $app = 'shop', string $environment = 'production'): AppConfig
    {
        /** @var array{settings?: array<string, mixed>, variables?: array<string, string>, permissions?: list<array<string, mixed>>, domains?: list<string>, queues?: array<string, array<string, mixed>>, storage?: array<string, array<string, mixed>>, tables?: array<string, array<string, mixed>>}|null $layer */
        return ConfigResolver::resolve(
            App::make($app)->region('us-east-1')->environment($environment, function (Environment $e) use ($env): void {
                $e->account(self::ACCOUNT);
                if ($env !== null) {
                    $env($e);
                }
            })->toArray(),
            $environment,
            $layer === null ? null : new ConfigLayer('layer.json', $app, 'us-east-1', $environment, $layer),
        );
    }

    /** @param array<string, string> $tags */
    private static function subnet(string $id, string $vpc = 'vpc-1', bool $default = false, array $tags = [], ?string $routeTable = 'rtb-1'): ResourceItem
    {
        return new ResourceItem(ResourceType::Subnet, $id, $tags['Name'] ?? null, $tags, [
            'vpc' => ['id' => $vpc, 'name' => null, 'default' => $default], 'az' => 'use1-az1', 'cidr' => '10.0.0.0/24',
            'freeIps' => 2000, 'egress' => 'nat', 'routeTable' => $routeTable,
        ]);
    }

    /** @param array<string, string> $tags */
    private static function group(string $id, string $vpc = 'vpc-1', bool $default = false, array $tags = []): ResourceItem
    {
        return new ResourceItem(ResourceType::SecurityGroup, $id, $tags['Name'] ?? $id, $tags, [
            'vpc' => ['id' => $vpc, 'name' => null, 'default' => $default], 'description' => null, 'egressAll' => true,
        ]);
    }

    /** @param list<string> $routeTables */
    private static function endpoint(string $id, string $service = 's3', string $vpc = 'vpc-1', bool $default = false, array $routeTables = ['rtb-1']): ResourceItem
    {
        return new ResourceItem(ResourceType::Endpoint, $id, null, [], [
            'vpc' => ['id' => $vpc, 'name' => null, 'default' => $default], 'service' => $service, 'routeTables' => $routeTables,
        ]);
    }

    /** @param array<string, string> $tags */
    private static function bucket(string $name, array $tags = [], bool $hidden = false): ResourceItem
    {
        return new ResourceItem(ResourceType::Bucket, $name, $name, $tags, ['region' => 'us-east-1', 'created' => null], hidden: $hidden);
    }

    private static function queue(string $name): ResourceItem
    {
        $url = 'https://sqs.us-east-1.amazonaws.com/' . self::ACCOUNT . '/' . $name;
        return new ResourceItem(ResourceType::Queue, $url, $name, [], [
            'url' => $url, 'arn' => 'arn:aws:sqs:us-east-1:' . self::ACCOUNT . ':' . $name, 'fifo' => false, 'visibilityTimeout' => 30, 'dlq' => null,
        ]);
    }

    /** @param list<array{name: string, type: string, role: string}> $keys */
    private static function table(string $name, array $keys = [['name' => 'PK', 'type' => 'S', 'role' => 'HASH'], ['name' => 'SK', 'type' => 'S', 'role' => 'RANGE']]): ResourceItem
    {
        return new ResourceItem(ResourceType::Table, $name, $name, [], [
            'arn' => 'arn:aws:dynamodb:us-east-1:' . self::ACCOUNT . ':table/' . $name, 'keys' => $keys, 'ttl' => null, 'billing' => 'PAY_PER_REQUEST',
        ]);
    }

    /** @param list<string> $domains */
    private static function certificate(string $arn, array $domains = ['example.com', '*.example.com']): ResourceItem
    {
        return new ResourceItem(ResourceType::Certificate, $arn, $domains[0], [], [
            'arn' => $arn, 'domains' => $domains, 'status' => 'ISSUED', 'expires' => null, 'inUseBy' => 0,
        ]);
    }

    private static function zone(string $id, string $name, bool $private = false): ResourceItem
    {
        return new ResourceItem(ResourceType::Zone, $id, $name, [], ['name' => $name, 'private' => $private, 'records' => 2, 'account' => 'deploy']);
    }

    /**
     * @param list<ResourceItem> $items
     * @return array<string, ResourceItem>
     */
    private static function byId(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $out[$item->id] = $item;
        }
        return $out;
    }

    /**
     * @param array<string, list<ResourceItem>> $byType
     * @return array<string, ResourceItem>
     */
    private static function annotated(AppConfig $config, array $byType): array
    {
        $out = [];
        foreach ((new Annotator())->annotate($config, self::CODE_BUCKET, $byType) as $items) {
            $out += self::byId($items);
        }
        return $out;
    }

    /** @return list<string> */
    private static function keys(ResourceItem $item): array
    {
        return array_map(fn (array $w) => $w['key'], $item->warnings);
    }

    public function test_hides_arco_owned_code_bucket_bootstrap_and_default_vpc(): void
    {
        $items = self::annotated(self::config(), [
            'bucket' => [
                self::bucket('shop-production-files-943653377281', ['arco:app' => 'shop', 'arco:environment' => 'production']),
                self::bucket('shop-staging-files-943653377281', ['arco:app' => 'shop', 'arco:environment' => 'staging']),
                self::bucket(self::CODE_BUCKET),
                self::bucket('cdk-hnb659fds-assets-943653377281-us-east-1'),
                self::bucket('aws-sam-cli-managed-default-samclisourcebucket-1x'),
                self::bucket('far-away', hidden: true),
                self::bucket('legacy-uploads'),
            ],
            'subnet' => [self::subnet('subnet-default', 'vpc-default', true), self::subnet('subnet-mine', 'vpc-1')],
            'security-group' => [self::group('sg-default', 'vpc-default', true), self::group('sg-mine', 'vpc-1')],
            'endpoint' => [self::endpoint('vpce-default', 's3', 'vpc-default', true), self::endpoint('vpce-mine')],
        ]);

        foreach (['shop-production-files-943653377281', self::CODE_BUCKET, 'cdk-hnb659fds-assets-943653377281-us-east-1', 'aws-sam-cli-managed-default-samclisourcebucket-1x', 'far-away', 'subnet-default', 'sg-default', 'vpce-default'] as $id) {
            self::assertTrue($items[$id]->hidden, "$id is hidden");
        }
        foreach (['shop-staging-files-943653377281', 'legacy-uploads', 'subnet-mine', 'sg-mine', 'vpce-mine'] as $id) {
            self::assertFalse($items[$id]->hidden, "$id is shown");
        }
        self::assertNotNull($items[self::CODE_BUCKET]->apply, 'hidden items still get apply');
    }

    public function test_default_vpc_is_shown_when_it_is_the_only_one_or_in_use(): void
    {
        $only = self::annotated(self::config(), ['subnet' => [self::subnet('subnet-default', 'vpc-default', true)], 'security-group' => [self::group('sg-default', 'vpc-default', true)]]);
        self::assertFalse($only['subnet-default']->hidden);
        self::assertFalse($only['sg-default']->hidden);

        $used = self::annotated(
            self::config(fn (Environment $e) => $e->vpc(['subnet-default'], ['sg-default'])),
            ['subnet' => [self::subnet('subnet-default', 'vpc-default', true), self::subnet('subnet-mine')]],
        );
        self::assertFalse($used['subnet-default']->hidden, 'what the environment uses is never hidden for being in the default VPC');
    }

    public function test_in_use_from_arco_php_and_from_the_layer(): void
    {
        $config = self::config(
            fn (Environment $e) => $e
                ->vpc(['subnet-a'], ['sg-a'], ['s3' => 'vpce-a'])
                ->certificate(self::CERT)
                ->domain('app.example.com')
                ->storage('files', existing: 'legacy-files')
                ->queue('jobs', existing: 'legacy-jobs')
                ->table('state', existing: 'arn:aws:dynamodb:us-east-1:943653377281:table/legacy-state')
                ->cache(existing: 'legacy-cache')
                ->failedJobsTable(existing: 'legacy-failed'),
            ['storage' => ['media' => ['existing' => 'cloud-media']], 'tables' => ['events' => ['existing' => 'cloud-events']]],
        );
        $items = self::annotated($config, [
            'subnet' => [self::subnet('subnet-a'), self::subnet('subnet-b')],
            'security-group' => [self::group('sg-a'), self::group('sg-b')],
            'endpoint' => [self::endpoint('vpce-a'), self::endpoint('vpce-b', 'dynamodb')],
            'certificate' => [self::certificate(self::CERT), self::certificate(self::OTHER_CERT)],
            'bucket' => [self::bucket('legacy-files'), self::bucket('cloud-media'), self::bucket('other')],
            'queue' => [self::queue('legacy-jobs'), self::queue('other')],
            'table' => [self::table('legacy-state'), self::table('legacy-cache'), self::table('legacy-failed'), self::table('cloud-events'), self::table('other-table')],
            'zone' => [self::zone('Z1', 'example.com'), self::zone('Z2', 'app.example.com'), self::zone('Z3', 'other.com')],
        ]);

        $arcoPhp = ['source' => 'arco.php'];
        foreach (['subnet-a', 'sg-a', 'vpce-a', self::CERT, 'legacy-files', self::QUEUE_URL, 'legacy-state', 'legacy-cache', 'legacy-failed', 'Z2'] as $id) {
            self::assertSame($arcoPhp, $items[$id]->inUse, "$id is in use from arco.php");
        }
        self::assertSame(['source' => 'cloud'], $items['cloud-media']->inUse);
        self::assertSame(['source' => 'cloud'], $items['cloud-events']->inUse);
        foreach (['subnet-b', 'sg-b', 'vpce-b', self::OTHER_CERT, 'other', 'https://sqs.us-east-1.amazonaws.com/943653377281/other', 'other-table', 'Z1', 'Z3'] as $id) {
            self::assertNull($items[$id]->inUse, "$id is not in use");
        }
    }

    public function test_vpc_and_certificate_from_the_layer_are_cloud(): void
    {
        $config = self::config(null, ['settings' => ['vpc' => ['subnets' => ['subnet-a'], 'securityGroups' => ['sg-a']], 'certificate' => self::CERT]]);
        $items = self::annotated($config, ['subnet' => [self::subnet('subnet-a')], 'security-group' => [self::group('sg-a')], 'certificate' => [self::certificate(self::CERT)]]);

        self::assertSame(['source' => 'cloud'], $items['subnet-a']->inUse);
        self::assertSame(['source' => 'cloud'], $items['sg-a']->inUse);
        self::assertSame(['source' => 'cloud'], $items[self::CERT]->inUse);
    }

    /** @return iterable<string, array{string, string, string, ?string, string}> */
    public static function names(): iterable
    {
        yield 'app, env and account stripped' => ['multierp-staging-files-943653377281', 'multierp', 'staging', '943653377281', 'files'];
        yield 'app and env stripped' => ['comercialrosita-prod-cache', 'comercialrosita', 'prod', '943653377281', 'cache'];
        yield 'another env keeps its name' => ['comercialrosita-prod-cache', 'comercialrosita', 'staging', '943653377281', 'prod-cache'];
        yield 'lowercased and slugged' => ['Legacy_Uploads.Bucket', 'shop', 'production', '943653377281', 'legacy-uploads-bucket'];
        yield 'unknown account: a trailing account id' => ['multierp-staging-files-943653377281', 'multierp', 'staging', null, 'files'];
        yield 'the account goes first, then the prefix' => ['shop-production-943653377281', 'shop', 'production', '943653377281', 'production'];
        yield 'only the app prefix' => ['shop-uploads', 'shop', 'production', null, 'uploads'];
        yield 'runs of separators collapse' => ['--My__Bucket--', 'shop', 'production', null, 'my-bucket'];
    }

    #[DataProvider('names')]
    public function test_suggested_names(string $aws, string $app, string $environment, ?string $account, string $expected): void
    {
        self::assertSame($expected, Annotator::suggestedName($aws, $app, $environment, $account));
    }

    public function test_suggested_name_is_set_for_buckets_queues_and_tables_only(): void
    {
        $items = self::annotated(self::config(app: 'multierp', environment: 'staging'), [
            'bucket' => [self::bucket('multierp-staging-files-943653377281')],
            'queue' => [self::queue('multierp-staging-jobs')],
            'table' => [self::table('multierp-state')],
            'subnet' => [self::subnet('subnet-a')],
        ]);

        self::assertSame('files', $items['multierp-staging-files-943653377281']->suggestedName);
        self::assertSame('jobs', $items['https://sqs.us-east-1.amazonaws.com/943653377281/multierp-staging-jobs']->suggestedName);
        self::assertSame('state', $items['multierp-state']->suggestedName);
        self::assertNull($items['subnet-a']->suggestedName);
    }

    public function test_apply_argv_per_type(): void
    {
        $items = self::annotated(self::config(), [
            'bucket' => [self::bucket('shop-production-files-943653377281')],
            'queue' => [self::queue('shop-production-jobs')],
            'table' => [self::table('shop-production-state')],
            'subnet' => [self::subnet('subnet-a')],
            'security-group' => [self::group('sg-a')],
            'endpoint' => [self::endpoint('vpce-a', 'dynamodb')],
            'certificate' => [self::certificate(self::CERT)],
            'zone' => [self::zone('Z1', 'example.com')],
        ]);

        self::assertSame(['command' => 'storage:add', 'args' => ['files', '--existing=shop-production-files-943653377281', '--env=production']], $items['shop-production-files-943653377281']->apply);
        self::assertSame(['command' => 'queue:add', 'args' => ['jobs', '--existing=https://sqs.us-east-1.amazonaws.com/943653377281/shop-production-jobs', '--env=production']], $items['https://sqs.us-east-1.amazonaws.com/943653377281/shop-production-jobs']->apply);
        self::assertSame([
            'generic' => ['command' => 'table:add', 'args' => ['state', '--existing=shop-production-state', '--env=production']],
            'cache' => ['command' => 'cache:add', 'args' => ['--existing=shop-production-state', '--env=production']],
            'failedJobs' => ['command' => 'failed-jobs:add', 'args' => ['--existing=shop-production-state', '--env=production']],
        ], $items['shop-production-state']->apply);
        self::assertSame(['command' => 'vpc:set', 'args' => ['--subnet=subnet-a', '--env=production']], $items['subnet-a']->apply);
        self::assertSame(['command' => 'vpc:set', 'args' => ['--security-group=sg-a', '--env=production']], $items['sg-a']->apply);
        self::assertSame(['command' => 'vpc:set', 'args' => ['--endpoint=dynamodb=vpce-a', '--env=production']], $items['vpce-a']->apply);
        self::assertSame(['command' => 'certificate:set', 'args' => [self::CERT, '--env=production']], $items[self::CERT]->apply);
        self::assertNull($items['Z1']->apply);
    }

    public function test_order_in_use_then_app_then_name(): void
    {
        $result = (new Annotator())->annotate(self::config(fn (Environment $e) => $e->vpc(['subnet-x'], ['sg-1'])->storage('files', existing: 'zz-used')), self::CODE_BUCKET, [
            'subnet' => [
                self::subnet('subnet-z', 'vpc-2', tags: ['Name' => 'b-rest']),
                self::subnet('subnet-y', 'vpc-1', tags: ['Name' => 'shop-private-1']),
                self::subnet('subnet-x', 'vpc-2'),
                self::subnet('subnet-w', 'vpc-1', tags: ['Name' => 'z-rest']),
                self::subnet('subnet-v', 'vpc-2', tags: ['Name' => 'a-rest']),
                self::subnet('subnet-u', 'vpc-1', tags: ['aws:cloudformation:stack-name' => 'shop-network']),
            ],
            'bucket' => [
                self::bucket('mmm'),
                self::bucket('aaa'),
                self::bucket('zz-used'),
                self::bucket('tagged', ['arco:app' => 'shop', 'arco:environment' => 'staging']),
            ],
        ]);

        self::assertSame(['subnet', 'bucket'], array_keys($result));
        self::assertSame(
            ['subnet-x', 'subnet-v', 'subnet-z', 'subnet-y', 'subnet-u', 'subnet-w'],
            array_map(fn (ResourceItem $i) => $i->id, $result['subnet']),
            'grouped by VPC, vpc-2 first (it has the item in use); in each group in use, app-matching, the rest, then by name',
        );
        self::assertSame(['zz-used', 'tagged', 'aaa', 'mmm'], array_map(fn (ResourceItem $i) => $i->id, $result['bucket']));
    }

    public function test_conflicts(): void
    {
        $config = self::config(fn (Environment $e) => $e
            ->vpc(['subnet-a'], ['sg-a'], ['s3' => 'vpce-a'])
            ->certificate(self::CERT)
            ->storage('files')
            ->storage('docs', existing: 'shop-production-docs')
            ->queue('jobs')
            ->table('state'));
        $items = self::annotated($config, [
            'bucket' => [self::bucket('shop-production-files-legacy'), self::bucket('files'), self::bucket('shop-production-docs'), self::bucket('free')],
            'queue' => [self::queue('shop-production-jobs')],
            'table' => [self::table('state')],
            'subnet' => [self::subnet('subnet-a'), self::subnet('subnet-b')],
            'security-group' => [self::group('sg-b')],
            'endpoint' => [self::endpoint('vpce-a'), self::endpoint('vpce-b', 'dynamodb')],
            'certificate' => [self::certificate(self::CERT), self::certificate(self::OTHER_CERT)],
        ]);

        self::assertSame(['with' => "storage('files')", 'source' => 'arco.php'], $items['files']->conflict);
        self::assertNull($items['shop-production-files-legacy']->conflict, 'its suggested name is files-legacy');
        self::assertNull($items['shop-production-docs']->conflict, 'already that storage');
        self::assertNull($items['free']->conflict);
        self::assertSame(['with' => "queue('jobs')", 'source' => 'arco.php'], $items['https://sqs.us-east-1.amazonaws.com/943653377281/shop-production-jobs']->conflict);
        self::assertSame(['with' => "table('state')", 'source' => 'arco.php'], $items['state']->conflict);
        $vpc = ['with' => "vpc(subnets: ['subnet-a'], securityGroups: ['sg-a'], endpoints: ['s3' => 'vpce-a'])", 'source' => 'arco.php'];
        self::assertNull($items['subnet-a']->conflict);
        self::assertSame($vpc, $items['subnet-b']->conflict);
        self::assertSame($vpc, $items['sg-b']->conflict);
        self::assertNull($items['vpce-a']->conflict);
        self::assertSame($vpc, $items['vpce-b']->conflict);
        self::assertNull($items[self::CERT]->conflict);
        self::assertSame(['with' => "certificate('" . self::CERT . "')", 'source' => 'arco.php'], $items[self::OTHER_CERT]->conflict);
        self::assertNotNull($items['files']->apply, 'a conflicting command is still given');
    }

    public function test_no_conflict_without_vpc_or_certificate_and_layer_conflicts_are_cloud(): void
    {
        $none = self::annotated(self::config(), ['subnet' => [self::subnet('subnet-b')], 'certificate' => [self::certificate(self::OTHER_CERT)]]);
        self::assertNull($none['subnet-b']->conflict);
        self::assertNull($none[self::OTHER_CERT]->conflict);

        $layer = self::annotated(self::config(null, ['storage' => ['files' => []], 'settings' => ['certificate' => self::CERT]]), [
            'bucket' => [self::bucket('files')],
            'certificate' => [self::certificate(self::OTHER_CERT)],
        ]);
        self::assertSame(['with' => "storage('files')", 'source' => 'cloud'], $layer['files']->conflict);
        self::assertSame(['with' => "certificate('" . self::CERT . "')", 'source' => 'cloud'], $layer[self::OTHER_CERT]->conflict);
    }

    public function test_certificate_not_covering_and_table_schema_and_endpoint_routes(): void
    {
        $config = self::config(fn (Environment $e) => $e
            ->domain(['app.example.com', 'shop.other.org'])
            ->vpc(['subnet-a', 'subnet-b'], ['sg-a']));
        $items = self::annotated($config, [
            'certificate' => [self::certificate(self::CERT), self::certificate(self::OTHER_CERT, ['*.example.com', 'shop.other.org'])],
            'table' => [
                self::table('generic'),
                self::table('cache', [['name' => 'key', 'type' => 'S', 'role' => 'HASH']]),
                self::table('failed', [['name' => 'application', 'type' => 'S', 'role' => 'HASH'], ['name' => 'uuid', 'type' => 'S', 'role' => 'RANGE']]),
                self::table('numeric', [['name' => 'PK', 'type' => 'N', 'role' => 'HASH'], ['name' => 'SK', 'type' => 'S', 'role' => 'RANGE']]),
            ],
            'subnet' => [self::subnet('subnet-a', routeTable: 'rtb-1'), self::subnet('subnet-b', routeTable: 'rtb-2'), self::subnet('subnet-c', 'vpc-2', routeTable: 'rtb-9')],
            'endpoint' => [
                self::endpoint('vpce-partial', 's3', 'vpc-1', routeTables: ['rtb-1']),
                self::endpoint('vpce-full', 'dynamodb', 'vpc-1', routeTables: ['rtb-2', 'rtb-1', 'rtb-3']),
                self::endpoint('vpce-elsewhere', 's3', 'vpc-2', routeTables: []),
            ],
        ]);

        self::assertSame([['key' => 'resources.warning.certificate_not_covering', 'params' => ['domains' => 'shop.other.org']]], $items[self::CERT]->warnings);
        self::assertSame([], $items[self::OTHER_CERT]->warnings);

        $schema = fn (string $use, string $expected): array => ['key' => 'resources.warning.table_schema', 'params' => ['use' => $use, 'expected' => $expected]];
        $generic = 'PK (S, HASH), SK (S, RANGE)';
        $cache = 'key (S, HASH)';
        $failed = 'application (S, HASH), uuid (S, RANGE)';
        self::assertSame([$schema('cache', $cache), $schema('failedJobs', $failed)], $items['generic']->warnings);
        self::assertSame([$schema('generic', $generic), $schema('failedJobs', $failed)], $items['cache']->warnings);
        self::assertSame([$schema('generic', $generic), $schema('cache', $cache)], $items['failed']->warnings);
        self::assertSame([$schema('generic', $generic), $schema('cache', $cache), $schema('failedJobs', $failed)], $items['numeric']->warnings);

        self::assertSame([['key' => 'resources.warning.endpoint_missing_routes', 'params' => ['routeTables' => 'rtb-2']]], $items['vpce-partial']->warnings);
        self::assertSame([], $items['vpce-full']->warnings);
        self::assertSame([], $items['vpce-elsewhere']->warnings, 'no subnet in use in that VPC');
    }

    public function test_no_certificate_warning_without_domains(): void
    {
        $items = self::annotated(self::config(), ['certificate' => [self::certificate(self::CERT)]]);

        self::assertSame([], self::keys($items[self::CERT]));
    }
}
