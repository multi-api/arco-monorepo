<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy;

use Aws\ResourceGroupsTaggingAPI\Exception\ResourceGroupsTaggingAPIException;
use Aws\Iam\Exception\IamException;
use Aws\Result;
use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Deploy\OwnershipGuard;
use Multiapi\Arco\Deploy\TaggedResources;
use PHPUnit\Framework\TestCase;

final class TaggedResourcesTest extends TestCase
{
    private function config(): AppConfig
    {
        return ConfigResolver::resolve(App::make('shop')->region('us-east-1')->environment('production')->toArray(), 'production');
    }

    private static function subject(AwsMocks $mocks): TaggedResources
    {
        $clients = $mocks->clients();
        return new TaggedResources($clients->taggingApi(), new OwnershipGuard($clients));
    }

    /** @param array<string, string> $tags */
    private static function role(array $tags): Result
    {
        $list = [];
        foreach ($tags as $key => $value) {
            $list[] = ['Key' => $key, 'Value' => $value];
        }
        return new Result(['Role' => ['RoleName' => 'arco-shop-production', 'Arn' => 'arn:aws:iam::111111111111:role/arco-shop-production', 'Tags' => $list]]);
    }

    /**
     * @param array<string, string> $extra
     * @return array{ResourceARN: string, Tags: list<array{Key: string, Value: string}>}
     */
    private static function mapping(string $arn, string $app = 'shop', string $environment = 'production', array $extra = []): array
    {
        $tags = [['Key' => 'arco:app', 'Value' => $app], ['Key' => 'arco:environment', 'Value' => $environment]];
        foreach ($extra as $key => $value) {
            $tags[] = ['Key' => $key, 'Value' => $value];
        }
        return ['ResourceARN' => $arn, 'Tags' => $tags];
    }

    public function test_lists_every_resource_tagged_for_the_environment_with_whether_remove_keeps_it(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['ResourceTagMappingList' => [
                self::mapping('arn:aws:lambda:us-east-1:111111111111:function:shop-production-console'),
                self::mapping('arn:aws:lambda:us-east-1:111111111111:function:shop-production-queue-emails'),
                self::mapping('arn:aws:sqs:us-east-1:111111111111:shop-production-emails', extra: ['aws:cloudformation:stack-name' => 'arco-shop-production-resources']),
                self::mapping('arn:aws:s3:::shop-production-uploads-111111111111'),
                self::mapping('arn:aws:dynamodb:us-east-1:111111111111:table/shop-production-cache'),
                self::mapping('arn:aws:ssm:us-east-1:111111111111:parameter/shop/production/app-key'),
                self::mapping('arn:aws:logs:us-east-1:111111111111:log-group:/aws/lambda/web/shop-production'),
                self::mapping('arn:aws:events:us-east-1:111111111111:rule/arco-shop-production-scheduler'),
                self::mapping('arn:aws:cloudformation:us-east-1:111111111111:stack/arco-shop-production-resources/0a1b2c'),
            ], 'PaginationToken' => 'next']),
            new Result(['ResourceTagMappingList' => [
                self::mapping('arn:aws:cloudfront::111111111111:distribution/E1ABC', extra: ['aws:cloudformation:stack-name' => 'arco-shop-production']),
                self::mapping('arn:aws:acm:us-east-1:111111111111:certificate/abc', extra: ['arco:managed' => 'true']),
                self::mapping('arn:aws:resource-groups:us-east-1:111111111111:group/arco-shop-production'),
                self::mapping('arn:aws:lambda:us-east-1:111111111111:function:shop-production'),
                self::mapping('arn:aws:cloudfront::111111111111:function/arco-shop-production-host'),
                self::mapping('arn:aws:cloudfront::111111111111:function/someone-elses-function'),
                self::mapping('arn:aws:acm:us-east-1:111111111111:certificate/reused'),
            ]]),
            self::role(['arco:app' => 'shop', 'arco:environment' => 'production']),
        );

        $resources = (self::subject($mocks))->list($this->config());

        self::assertSame(['GetResources', 'GetResources', 'GetRole'], $mocks->calls);
        self::assertSame('arco-shop-production', $mocks->params[2]['RoleName']);
        self::assertSame([['Key' => 'arco:app', 'Values' => ['shop']], ['Key' => 'arco:environment', 'Values' => ['production']]], $mocks->params[0]['TagFilters']);
        self::assertSame('next', $mocks->params[1]['PaginationToken']);
        $byName = [];
        foreach ($resources as $resource) {
            $byName[$resource['type'] . ' ' . $resource['name']] = $resource['retained'];
        }
        $expected = [
            'lambda:function shop-production-console' => false,
            'lambda:function shop-production-queue-emails' => false,
            'sqs shop-production-emails' => true,
            's3 shop-production-uploads-111111111111' => true,
            'dynamodb:table shop-production-cache' => true,
            'ssm:parameter /shop/production/app-key' => true,
            'logs:log-group /aws/lambda/web/shop-production' => true,
            'events:rule arco-shop-production-scheduler' => false,
            'cloudformation:stack arco-shop-production-resources' => false,
            'cloudfront:distribution E1ABC' => false,
            'acm:certificate abc' => false,
            'resource-groups:group arco-shop-production' => false,
            'lambda:function shop-production' => false,
            'cloudfront:function arco-shop-production-host' => false,
            'cloudfront:function someone-elses-function' => true,
            'acm:certificate reused' => true,
            'iam:role arco-shop-production' => false,
        ];
        ksort($expected);
        ksort($byName);
        self::assertSame($expected, $byName);
        self::assertSame('arn:aws:sqs:us-east-1:111111111111:shop-production-emails', $resources[array_search('shop-production-emails', array_column($resources, 'name'), true)]['arn']);
        $sorted = $resources;
        usort($sorted, fn (array $a, array $b) => [$a['type'], $a['name']] <=> [$b['type'], $b['name']]);
        self::assertSame($sorted, $resources, 'sorted by type, then name');
    }

    /** @return array<string, list<array{type: string, target: string}>> relations by "type name" */
    private static function relations(AppConfig $config, array $mappings): array
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(new Result(['ResourceTagMappingList' => $mappings]), AwsMocks::error(IamException::class, 'GetRole', 'NoSuchEntity', 404));
        $out = [];
        foreach (self::subject($mocks)->list($config) as $resource) {
            $out[$resource['type'] . ' ' . $resource['name']] = $resource['relations'];
        }
        return $out;
    }

    public function test_lists_each_resources_outgoing_relations(): void
    {
        $config = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->environment('production', fn ($e) => $e
            ->domain(['shop.example.com', 'www.example.com'])
            ->queue('emails')
            ->queue('legacy', existing: 'arn:aws:sqs:us-east-1:222222222222:legacy-jobs'))->toArray(), 'production');
        $queue = 'arn:aws:sqs:us-east-1:111111111111:shop-production-emails';
        $console = 'arn:aws:lambda:us-east-1:111111111111:function:shop-production-console';

        $relations = self::relations($config, [
            self::mapping($console),
            self::mapping('arn:aws:lambda:us-east-1:111111111111:function:shop-production'),
            self::mapping('arn:aws:lambda:us-east-1:111111111111:function:shop-production-queue-emails'),
            self::mapping('arn:aws:lambda:us-east-1:111111111111:function:shop-production-queue-legacy'),
            self::mapping($queue),
            self::mapping('arn:aws:sqs:us-east-1:111111111111:shop-production-emails-dlq'),
            self::mapping('arn:aws:events:us-east-1:111111111111:rule/arco-shop-production-scheduler'),
            self::mapping('arn:aws:cloudfront::111111111111:distribution/E1ABC'),
            self::mapping('arn:aws:acm:us-east-1:111111111111:certificate/abc'),
            self::mapping('arn:aws:s3:::shop-production-uploads-111111111111'),
        ]);

        self::assertSame([['type' => 'worker_of', 'target' => $queue]], $relations['lambda:function shop-production-queue-emails']);
        self::assertSame([['type' => 'worker_of', 'target' => 'arn:aws:sqs:us-east-1:222222222222:legacy-jobs']], $relations['lambda:function shop-production-queue-legacy']);
        self::assertSame([['type' => 'dlq_of', 'target' => $queue]], $relations['sqs shop-production-emails-dlq']);
        self::assertSame([['type' => 'invokes', 'target' => $console]], $relations['events:rule arco-shop-production-scheduler']);
        $domains = [['type' => 'serves', 'target' => 'shop.example.com'], ['type' => 'serves', 'target' => 'www.example.com']];
        self::assertSame($domains, $relations['cloudfront:distribution E1ABC']);
        self::assertSame(array_map(static fn (array $r): array => ['type' => 'validates'] + $r, array_map(static fn (array $r): array => ['target' => $r['target']], $domains)), $relations['acm:certificate abc']);
        foreach (['lambda:function shop-production-console', 'lambda:function shop-production', 'sqs shop-production-emails', 's3 shop-production-uploads-111111111111'] as $other) {
            self::assertSame([], $relations[$other], $other);
        }
    }

    public function test_unresolvable_relations_are_left_out(): void
    {
        // No console function listed, no domains configured, the worker's queue is not configured.
        $relations = self::relations($this->config(), [
            self::mapping('arn:aws:events:us-east-1:111111111111:rule/arco-shop-production-scheduler'),
            self::mapping('arn:aws:lambda:us-east-1:111111111111:function:shop-production-queue-ghost'),
            self::mapping('arn:aws:cloudfront::111111111111:distribution/E1ABC'),
            self::mapping('arn:aws:acm:us-east-1:111111111111:certificate/abc'),
        ]);

        self::assertSame(['acm:certificate abc' => [], 'cloudfront:distribution E1ABC' => [], 'events:rule arco-shop-production-scheduler' => [], 'lambda:function shop-production-queue-ghost' => []], $relations);
    }

    public function test_validates_needs_a_certificate_that_can_be_identified(): void
    {
        $domains = fn (?string $arn) => ConfigResolver::resolve(App::make('shop')->region('us-east-1')->environment('production', function ($e) use ($arn) {
            $e->domain('shop.example.com');
            if ($arn !== null) {
                $e->certificate($arn);
            }
        })->toArray(), 'production');
        $one = 'arn:aws:acm:us-east-1:111111111111:certificate/one';
        $two = 'arn:aws:acm:us-east-1:111111111111:certificate/two';
        $both = [self::mapping($one), self::mapping($two)];
        $validates = [['type' => 'validates', 'target' => 'shop.example.com']];

        // Two certificates and none configured: which one covers the domain is unknown.
        self::assertSame(['acm:certificate one' => [], 'acm:certificate two' => []], self::relations($domains(null), $both));
        // A configured certificate ARN: only that one.
        self::assertSame(['acm:certificate one' => [], 'acm:certificate two' => $validates], self::relations($domains($two), $both));
        // A configured ARN that is not listed: none.
        self::assertSame(['acm:certificate one' => [], 'acm:certificate two' => []], self::relations($domains('arn:aws:acm:us-east-1:111111111111:certificate/other'), $both));
        // Exactly one listed and none configured: it is the environment's.
        self::assertSame(['acm:certificate one' => $validates], self::relations($domains(null), [self::mapping($one)]));
    }

    public function test_a_managed_queue_missing_from_the_listing_gets_no_worker_or_dlq_target(): void
    {
        $config = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->environment('production', fn ($e) => $e->queue('emails'))->toArray(), 'production')->withAccount('111111111111');

        $relations = self::relations($config, [
            self::mapping('arn:aws:lambda:us-east-1:111111111111:function:shop-production-queue-emails'),
            self::mapping('arn:aws:sqs:us-east-1:111111111111:shop-production-emails-dlq'),
        ]);

        self::assertSame(['lambda:function shop-production-queue-emails' => [], 'sqs shop-production-emails-dlq' => []], $relations);
    }

    public function test_never_lists_a_resource_whose_tags_differ(): void
    {
        // AWS filters by tag; a mapping with other values (or one tag missing) is still never listed.
        $mocks = new AwsMocks();
        $mocks->handler->append(new Result(['ResourceTagMappingList' => [
            self::mapping('arn:aws:lambda:us-east-1:111111111111:function:shop-production'),
            self::mapping('arn:aws:lambda:us-east-1:111111111111:function:shop-production-console', 'shop', 'staging'),
            self::mapping('arn:aws:sqs:us-east-1:111111111111:shop-eu-production-jobs', 'shop-eu'),
            ['ResourceARN' => 'arn:aws:s3:::half-tagged', 'Tags' => [['Key' => 'arco:app', 'Value' => 'shop']]],
        ]]), self::role(['arco:app' => 'shop', 'arco:environment' => 'staging']));

        $resources = (self::subject($mocks))->list($this->config());

        self::assertSame(['shop-production'], array_column($resources, 'name'));
    }

    public function test_lists_the_execution_role_read_by_name_with_its_real_arn(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(new Result(['ResourceTagMappingList' => []]), self::role(['arco:app' => 'shop', 'arco:environment' => 'production']));

        $resources = self::subject($mocks)->list($this->config());

        self::assertSame([['type' => 'iam:role', 'name' => 'arco-shop-production', 'arn' => 'arn:aws:iam::111111111111:role/arco-shop-production', 'retained' => false, 'relations' => []]], $resources);
    }

    public function test_a_missing_or_denied_role_is_not_listed_and_does_not_fail(): void
    {
        foreach (['NoSuchEntity' => 404, 'AccessDenied' => 403] as $code => $status) {
            $mocks = new AwsMocks();
            $mocks->handler->append(
                new Result(['ResourceTagMappingList' => [self::mapping('arn:aws:lambda:us-east-1:111111111111:function:shop-production')]]),
                AwsMocks::error(IamException::class, 'GetRole', $code, $status),
            );

            self::assertSame(['shop-production'], array_column(self::subject($mocks)->list($this->config()), 'name'), $code);
        }
    }

    public function test_a_role_without_this_environments_tags_is_not_listed_and_a_custom_role_is_not_read(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(new Result(['ResourceTagMappingList' => []]), self::role(['arco:app' => 'shop']));
        self::assertSame([], self::subject($mocks)->list($this->config()));

        $custom = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->environment('production', fn ($e) => $e->role('arn:aws:iam::111111111111:role/mine'))->toArray(), 'production');
        $mocks = new AwsMocks();
        $mocks->handler->append(new Result(['ResourceTagMappingList' => []]));
        self::assertSame([], self::subject($mocks)->list($custom));
        self::assertSame(['GetResources'], $mocks->calls);
    }

    public function test_a_refused_listing_propagates(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(AwsMocks::error(ResourceGroupsTaggingAPIException::class, 'GetResources', 'AccessDeniedException', 400));

        $this->expectException(ResourceGroupsTaggingAPIException::class);
        (self::subject($mocks))->list($this->config());
    }
}
