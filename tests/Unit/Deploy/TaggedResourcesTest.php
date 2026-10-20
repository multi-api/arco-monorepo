<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy;

use Aws\ResourceGroupsTaggingAPI\Exception\ResourceGroupsTaggingAPIException;
use Aws\Result;
use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Deploy\TaggedResources;
use PHPUnit\Framework\TestCase;

final class TaggedResourcesTest extends TestCase
{
    private function config(): AppConfig
    {
        return ConfigResolver::resolve(App::make('shop')->region('us-east-1')->environment('production')->toArray(), 'production');
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
            ]]),
        );

        $resources = (new TaggedResources($mocks->clients()->taggingApi()))->list($this->config());

        self::assertSame(['GetResources', 'GetResources'], $mocks->calls);
        self::assertSame([['Key' => 'arco:app', 'Values' => ['shop']], ['Key' => 'arco:environment', 'Values' => ['production']]], $mocks->params[0]['TagFilters']);
        self::assertSame('next', $mocks->params[1]['PaginationToken']);
        $byName = [];
        foreach ($resources as $resource) {
            $byName[$resource['name']] = [$resource['type'], $resource['retained']];
        }
        $expected = [
            'shop-production-console' => ['lambda:function', false],
            'shop-production-queue-emails' => ['lambda:function', false],
            'shop-production-emails' => ['sqs', true],
            'shop-production-uploads-111111111111' => ['s3', true],
            'shop-production-cache' => ['dynamodb:table', true],
            '/shop/production/app-key' => ['ssm:parameter', true],
            '/aws/lambda/web/shop-production' => ['logs:log-group', true],
            'arco-shop-production-scheduler' => ['events:rule', false],
            'arco-shop-production-resources' => ['cloudformation:stack', false],
            'E1ABC' => ['cloudfront:distribution', false],
            'abc' => ['acm:certificate', false],
            'arco-shop-production' => ['resource-groups:group', false],
            'shop-production' => ['lambda:function', false],
        ];
        ksort($expected);
        ksort($byName);
        self::assertSame($expected, $byName);
        self::assertSame('arn:aws:sqs:us-east-1:111111111111:shop-production-emails', $resources[array_search('shop-production-emails', array_column($resources, 'name'), true)]['arn']);
        $sorted = $resources;
        usort($sorted, fn (array $a, array $b) => [$a['type'], $a['name']] <=> [$b['type'], $b['name']]);
        self::assertSame($sorted, $resources, 'sorted by type, then name');
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
        ]]));

        $resources = (new TaggedResources($mocks->clients()->taggingApi()))->list($this->config());

        self::assertSame(['shop-production'], array_column($resources, 'name'));
    }

    public function test_a_refused_listing_propagates(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(AwsMocks::error(ResourceGroupsTaggingAPIException::class, 'GetResources', 'AccessDeniedException', 400));

        $this->expectException(ResourceGroupsTaggingAPIException::class);
        (new TaggedResources($mocks->clients()->taggingApi()))->list($this->config());
    }
}
