<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Resources;

use Multiapi\Arco\Cli\UserError;
use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Config\Environment;
use Multiapi\Arco\Resources\Annotator;
use Multiapi\Arco\Resources\ResourceItem;
use Multiapi\Arco\Resources\ResourceType;
use Multiapi\Arco\Resources\SelectionPlan;
use PHPUnit\Framework\TestCase;

final class SelectionPlanTest extends TestCase
{
    private const ACCOUNT = '943653377281';
    private const CERT = 'arn:aws:acm:us-east-1:943653377281:certificate/aaaa';

    /** @param (callable(Environment): mixed)|null $env */
    private static function config(?callable $env = null): AppConfig
    {
        return ConfigResolver::resolve(
            App::make('shop')->region('us-east-1')->environment('staging', function (Environment $e) use ($env): void {
                $e->account(self::ACCOUNT);
                if ($env !== null) {
                    $env($e);
                }
            })->toArray(),
            'staging',
            null,
        );
    }

    private static function subnet(string $id, string $vpc = 'vpc-1'): ResourceItem
    {
        return new ResourceItem(ResourceType::Subnet, $id, null, [], ['vpc' => ['id' => $vpc, 'name' => null, 'default' => false], 'egress' => 'nat']);
    }

    private static function group(string $id, string $vpc = 'vpc-1'): ResourceItem
    {
        return new ResourceItem(ResourceType::SecurityGroup, $id, $id, [], ['vpc' => ['id' => $vpc, 'name' => null, 'default' => false]]);
    }

    private static function endpoint(string $id, string $service = 's3', string $vpc = 'vpc-1'): ResourceItem
    {
        return new ResourceItem(ResourceType::Endpoint, $id, null, [], ['vpc' => ['id' => $vpc, 'name' => null, 'default' => false], 'service' => $service, 'routeTables' => []]);
    }

    private static function bucket(string $name): ResourceItem
    {
        return new ResourceItem(ResourceType::Bucket, $name, $name, [], ['region' => 'us-east-1']);
    }

    private static function queue(string $name): ResourceItem
    {
        $url = 'https://sqs.us-east-1.amazonaws.com/' . self::ACCOUNT . '/' . $name;
        return new ResourceItem(ResourceType::Queue, $url, $name, [], ['url' => $url, 'arn' => 'arn:aws:sqs:us-east-1:' . self::ACCOUNT . ':' . $name]);
    }

    private static function table(string $name): ResourceItem
    {
        return new ResourceItem(ResourceType::Table, $name, $name, [], ['arn' => 'arn:aws:dynamodb:us-east-1:' . self::ACCOUNT . ':table/' . $name, 'keys' => []]);
    }

    private static function certificate(string $arn): ResourceItem
    {
        return new ResourceItem(ResourceType::Certificate, $arn, 'example.com', [], ['arn' => $arn, 'domains' => ['example.com']]);
    }

    /**
     * The items as `arco resources` hands them to the picker: annotated for the config (inUse, apply, suggestedName).
     *
     * @param list<ResourceItem> $items
     * @return list<ResourceItem>
     */
    private static function annotate(AppConfig $config, array $items): array
    {
        $byType = [];
        foreach ($items as $item) {
            $byType[$item->type->value][] = $item;
        }
        (new Annotator())->annotate($config, 'arco-code', $byType);
        return $items;
    }

    public function test_vpc_picks_merge_into_one_call_keeping_in_use(): void
    {
        $config = self::config(fn (Environment $e) => $e->vpc(['subnet-a'], ['sg-1']));
        $picked = self::annotate($config, [self::subnet('subnet-a'), self::group('sg-1'), self::group('sg-2')]);
        self::assertNotNull($picked[0]->inUse, 'fixture: subnet-a is in use');

        self::assertSame(
            [['command' => 'vpc:set', 'args' => ['--subnet=subnet-a', '--security-group=sg-1', '--security-group=sg-2', '--env=staging']]],
            SelectionPlan::from($config, $picked, [], []),
        );
    }

    public function test_endpoints_and_arco_managed_ones_are_kept(): void
    {
        $config = self::config(fn (Environment $e) => $e->vpc(['subnet-a'], ['sg-1'], endpoints: ['dynamodb']));
        $picked = self::annotate($config, [self::subnet('subnet-a'), self::group('sg-1'), self::endpoint('vpce-1')]);

        self::assertSame(
            [['command' => 'vpc:set', 'args' => ['--subnet=subnet-a', '--security-group=sg-1', '--endpoint=dynamodb', '--endpoint=s3=vpce-1', '--env=staging']]],
            SelectionPlan::from($config, $picked, [], []),
        );
    }

    public function test_unticking_an_in_use_subnet_removes_it(): void
    {
        $config = self::config(fn (Environment $e) => $e->vpc(['subnet-a', 'subnet-b'], ['sg-1']));
        $listed = self::annotate($config, [self::subnet('subnet-a'), self::subnet('subnet-b'), self::group('sg-1')]);
        $picked = [$listed[0], $listed[2]]; // subnet-b unticked

        self::assertSame(
            [['command' => 'vpc:set', 'args' => ['--subnet=subnet-a', '--security-group=sg-1', '--env=staging']]],
            SelectionPlan::from($config, $picked, [], []),
        );
    }

    public function test_unticking_everything_in_use_removes_the_vpc(): void
    {
        $config = self::config(fn (Environment $e) => $e->vpc(['subnet-a'], ['sg-1']));
        self::annotate($config, [self::subnet('subnet-a'), self::group('sg-1')]);

        self::assertSame([['command' => 'vpc:remove', 'args' => ['--env=staging']]], SelectionPlan::from($config, [], [], []));
    }

    public function test_in_use_vpc_kept_as_is_is_no_command(): void
    {
        $config = self::config(fn (Environment $e) => $e->vpc(['subnet-a'], ['sg-1']));
        $picked = self::annotate($config, [self::subnet('subnet-a'), self::group('sg-1')]);

        self::assertSame([], SelectionPlan::from($config, $picked, [], []));
        self::assertSame([], SelectionPlan::from(self::config(), [], [], []), 'no vpc and nothing picked: no vpc command');
    }

    public function test_network_types_not_offered_keep_the_config(): void
    {
        $config = self::config(fn (Environment $e) => $e->vpc(['subnet-a'], ['sg-1']));
        $picked = self::annotate($config, [self::group('sg-1'), self::group('sg-2')]);

        // Only security groups were listed (--type=security-group): the subnets stay what arco.php has.
        self::assertSame(
            [['command' => 'vpc:set', 'args' => ['--subnet=subnet-a', '--security-group=sg-1', '--security-group=sg-2', '--env=staging']]],
            SelectionPlan::from($config, $picked, [], [], [ResourceType::SecurityGroup]),
        );
        self::assertSame([], SelectionPlan::from($config, [], [], [], [ResourceType::Bucket]), 'no network type listed: vpc() untouched');
    }

    public function test_subnets_from_two_vpcs_refused(): void
    {
        $config = self::config();
        $picked = self::annotate($config, [self::subnet('subnet-a', 'vpc-1'), self::subnet('subnet-b', 'vpc-2'), self::group('sg-1')]);

        try {
            SelectionPlan::from($config, $picked, [], []);
            self::fail('expected the mixed VPCs to be refused');
        } catch (UserError $e) {
            self::assertSame('resources.mixed_vpcs', $e->key);
            self::assertSame(['vpcs' => 'vpc-1, vpc-2'], $e->params);
        }
    }

    public function test_a_vpc_without_security_groups_is_refused(): void
    {
        $config = self::config();
        $picked = self::annotate($config, [self::subnet('subnet-a')]);

        $this->expectExceptionObject(new UserError('resources.vpc_incomplete', 'resources.vpc_incomplete'));
        SelectionPlan::from($config, $picked, [], []);
    }

    public function test_bucket_queue_table_uses(): void
    {
        $config = self::config();
        $picked = self::annotate($config, [
            self::bucket('shop-staging-uploads'),
            self::queue('shop-staging-emails'),
            self::table('shop-staging-cache'),
            self::table('legacy-data'),
            self::table('shop-failed-jobs'),
            self::certificate(self::CERT),
        ]);

        self::assertSame([
            ['command' => 'storage:add', 'args' => ['files', '--existing=shop-staging-uploads', '--env=staging']],
            ['command' => 'queue:add', 'args' => ['emails', '--existing=https://sqs.us-east-1.amazonaws.com/943653377281/shop-staging-emails', '--env=staging']],
            ['command' => 'cache:add', 'args' => ['--existing=shop-staging-cache', '--env=staging']],
            ['command' => 'table:add', 'args' => ['legacy', '--existing=legacy-data', '--env=staging']],
            ['command' => 'failed-jobs:add', 'args' => ['--existing=shop-failed-jobs', '--env=staging']],
            ['command' => 'certificate:set', 'args' => [self::CERT, '--env=staging']],
        ], SelectionPlan::from(
            $config,
            $picked,
            ['shop-staging-uploads' => 'files', 'legacy-data' => 'legacy'],
            ['shop-staging-cache' => 'cache', 'legacy-data' => 'generic', 'shop-failed-jobs' => 'failedJobs'],
        ));
    }

    public function test_picks_already_in_use_are_not_applied_again(): void
    {
        $config = self::config(fn (Environment $e) => $e->storage('uploads', existing: 'shop-uploads')->certificate(self::CERT));
        $picked = self::annotate($config, [self::bucket('shop-uploads'), self::certificate(self::CERT)]);

        self::assertSame([], SelectionPlan::from($config, $picked, [], []));
    }

    public function test_preview_shows_the_old_and_new_lines(): void
    {
        $config = self::config(fn (Environment $e) => $e->vpc(['subnet-a'], ['sg-1'])->certificate(self::CERT));

        self::assertSame(
            ['old' => "->vpc(subnets: ['subnet-a'], securityGroups: ['sg-1'])", 'new' => "->vpc(subnets: ['subnet-a'], securityGroups: ['sg-1', 'sg-2'])"],
            SelectionPlan::preview($config, ['command' => 'vpc:set', 'args' => ['--subnet=subnet-a', '--security-group=sg-1', '--security-group=sg-2', '--env=staging']]),
        );
        self::assertSame(
            ['old' => "->vpc(subnets: ['subnet-a'], securityGroups: ['sg-1'])", 'new' => null],
            SelectionPlan::preview($config, ['command' => 'vpc:remove', 'args' => ['--env=staging']]),
        );
        self::assertSame(
            ['old' => "->certificate('" . self::CERT . "')", 'new' => "->certificate('arn:aws:acm:us-east-1:943653377281:certificate/bbbb')"],
            SelectionPlan::preview($config, ['command' => 'certificate:set', 'args' => ['arn:aws:acm:us-east-1:943653377281:certificate/bbbb', '--env=staging']]),
        );
        self::assertSame(
            ['old' => null, 'new' => "->storage('files', existing: 'shop-uploads')"],
            SelectionPlan::preview($config, ['command' => 'storage:add', 'args' => ['files', '--existing=shop-uploads', '--env=staging']]),
        );
        self::assertSame(
            ['old' => null, 'new' => "->queue('emails', existing: 'https://q')"],
            SelectionPlan::preview($config, ['command' => 'queue:add', 'args' => ['emails', '--existing=https://q', '--env=staging']]),
        );
        self::assertSame(
            ['old' => null, 'new' => "->table('legacy', existing: 'legacy-data')"],
            SelectionPlan::preview($config, ['command' => 'table:add', 'args' => ['legacy', '--existing=legacy-data', '--env=staging']]),
        );
        self::assertSame(
            ['old' => null, 'new' => "->cache(existing: 'c')"],
            SelectionPlan::preview($config, ['command' => 'cache:add', 'args' => ['--existing=c', '--env=staging']]),
        );
        self::assertSame(
            ['old' => null, 'new' => "->failedJobsTable(existing: 'f')"],
            SelectionPlan::preview($config, ['command' => 'failed-jobs:add', 'args' => ['--existing=f', '--env=staging']]),
        );
    }
}
