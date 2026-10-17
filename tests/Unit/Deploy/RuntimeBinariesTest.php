<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy;

use Aws\Lambda\Exception\LambdaException;
use Aws\Result;
use Multiapi\Arco\Deploy\RuntimeBinaries;
use PHPUnit\Framework\TestCase;

final class RuntimeBinariesTest extends TestCase
{
    private const BUCKET = 'arco-111111111111-us-east-1';

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-16 12:00:00 UTC');
    }

    private static function object(string $key, string $age): array
    {
        return ['Key' => $key, 'LastModified' => self::now()->modify("-$age")];
    }

    /** @param array<string, string> $variables */
    private static function version(string $version, array $variables = []): array
    {
        return ['Version' => $version, 'Environment' => ['Variables' => $variables]];
    }

    private static function binary(string $key, string $bucket = self::BUCKET): array
    {
        return ['ARCO_BINARY_BUCKET' => $bucket, 'ARCO_BINARY_KEY' => $key, 'ARCO_BINARY_SHA256' => str_repeat('a', 64)];
    }

    private static function prune(AwsMocks $mocks): int
    {
        $aws = $mocks->clients();
        return (new RuntimeBinaries($aws->taggingApi(), $aws->lambda(), $aws->s3()))->prune(self::BUCKET, self::now());
    }

    /** @return list<string> */
    private static function deletedKeys(AwsMocks $mocks): array
    {
        $keys = [];
        foreach ($mocks->calls as $i => $call) {
            if ($call === 'DeleteObjects') {
                self::assertSame(self::BUCKET, $mocks->params[$i]['Bucket']);
                array_push($keys, ...array_column($mocks->params[$i]['Delete']['Objects'], 'Key'));
            }
        }
        return $keys;
    }

    public function test_deletes_only_unreferenced_binaries_older_than_a_day(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['ResourceTagMappingList' => [['ResourceARN' => 'arn:aws:lambda:us-east-1:111111111111:function:shop-production-console']]]),
            new Result(['Versions' => [self::version('$LATEST', self::binary('runtime/1.13.0-r1/frankenphp')), self::version('1', self::binary('runtime/1.12.0-r1/frankenphp')), self::version('2')]]),
            new Result(['Contents' => [
                self::object('runtime/1.12.0-r1/frankenphp', '30 days'),
                self::object('runtime/1.13.0-r1/frankenphp', '30 days'),
                self::object('runtime/1.11.0-r1/frankenphp', '25 hours'),
                self::object('runtime/custom/' . str_repeat('b', 64) . '/frankenphp', '1 hour'),
            ]]),
            new Result([]),
        );

        $deleted = self::prune($mocks);

        self::assertSame(['GetResources', 'ListVersionsByFunction', 'ListObjectsV2', 'DeleteObjects'], $mocks->calls);
        self::assertSame(['lambda:function'], $mocks->params[0]['ResourceTypeFilters']);
        self::assertSame([['Key' => 'arco:app']], $mocks->params[0]['TagFilters']);
        self::assertSame('arn:aws:lambda:us-east-1:111111111111:function:shop-production-console', $mocks->params[1]['FunctionName']);
        self::assertSame(['Bucket' => self::BUCKET, 'Prefix' => 'runtime/'], array_intersect_key($mocks->params[2], ['Bucket' => 1, 'Prefix' => 1]));
        self::assertSame(['runtime/1.11.0-r1/frankenphp'], self::deletedKeys($mocks), 'referenced binaries and one uploaded an hour ago stay');
        self::assertTrue($mocks->params[3]['Delete']['Quiet']);
        self::assertSame(1, $deleted);
    }

    public function test_a_binary_referenced_only_in_another_bucket_is_not_protected(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['ResourceTagMappingList' => [['ResourceARN' => 'arn:other']]]),
            new Result(['Versions' => [self::version('1', self::binary('runtime/1.12.0-r1/frankenphp', 'arco-222222222222-us-east-1'))]]),
            new Result(['Contents' => [self::object('runtime/1.12.0-r1/frankenphp', '2 days')]]),
            new Result([]),
        );

        self::assertSame(1, self::prune($mocks));
        self::assertSame(['runtime/1.12.0-r1/frankenphp'], self::deletedKeys($mocks));
    }

    public function test_follows_every_page_of_the_three_listings(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['ResourceTagMappingList' => [['ResourceARN' => 'arn:a']], 'PaginationToken' => 't1']),
            new Result(['Versions' => [self::version('1', self::binary('runtime/a/frankenphp'))], 'NextMarker' => 'm1']),
            new Result(['Versions' => [self::version('2', self::binary('runtime/b/frankenphp'))]]),
            new Result(['ResourceTagMappingList' => [['ResourceARN' => 'arn:b']], 'PaginationToken' => '']),
            new Result(['Versions' => [self::version('1', self::binary('runtime/c/frankenphp'))]]),
            new Result(['Contents' => [self::object('runtime/a/frankenphp', '9 days'), self::object('runtime/x/frankenphp', '9 days')], 'IsTruncated' => true, 'NextContinuationToken' => 'c1']),
            new Result(['Contents' => [self::object('runtime/b/frankenphp', '9 days'), self::object('runtime/c/frankenphp', '9 days'), self::object('runtime/y/frankenphp', '9 days')], 'IsTruncated' => false]),
            new Result([]),
        );

        self::assertSame(2, self::prune($mocks));

        self::assertSame(['GetResources', 'ListVersionsByFunction', 'ListVersionsByFunction', 'GetResources', 'ListVersionsByFunction', 'ListObjectsV2', 'ListObjectsV2', 'DeleteObjects'], $mocks->calls);
        self::assertSame('t1', $mocks->params[3]['PaginationToken']);
        self::assertSame('m1', $mocks->params[2]['Marker']);
        self::assertSame('arn:b', $mocks->params[4]['FunctionName']);
        self::assertSame('c1', $mocks->params[6]['ContinuationToken']);
        self::assertSame(['runtime/x/frankenphp', 'runtime/y/frankenphp'], self::deletedKeys($mocks));
        self::assertCount(0, $mocks->handler);
    }

    public function test_no_candidates_means_no_delete_call(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['ResourceTagMappingList' => []]),
            new Result(['Contents' => [self::object('runtime/a/frankenphp', '2 hours')]]),
        );
        self::assertSame(0, self::prune($mocks));
        self::assertSame(['GetResources', 'ListObjectsV2'], $mocks->calls);

        $mocks = new AwsMocks();
        $mocks->handler->append(new Result(['ResourceTagMappingList' => []]), new Result([]));
        self::assertSame(0, self::prune($mocks));
        self::assertSame(['GetResources', 'ListObjectsV2'], $mocks->calls);
    }

    public function test_deletes_in_chunks_of_a_thousand(): void
    {
        $objects = array_map(fn (int $i) => self::object("runtime/old-$i/frankenphp", '3 days'), range(1, 1001));
        $mocks = new AwsMocks();
        $mocks->handler->append(new Result(['ResourceTagMappingList' => []]), new Result(['Contents' => $objects]), new Result([]), new Result([]));

        self::assertSame(1001, self::prune($mocks));

        self::assertSame(['GetResources', 'ListObjectsV2', 'DeleteObjects', 'DeleteObjects'], $mocks->calls);
        self::assertCount(1000, $mocks->params[2]['Delete']['Objects']);
        self::assertCount(1, $mocks->params[3]['Delete']['Objects']);
    }

    public function test_a_function_deleted_since_the_tag_listing_is_skipped(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['ResourceTagMappingList' => [['ResourceARN' => 'arn:gone'], ['ResourceARN' => 'arn:kept']]]),
            AwsMocks::error(LambdaException::class, 'ListVersionsByFunction', 'ResourceNotFoundException', 404),
            new Result(['Versions' => [self::version('1', self::binary('runtime/a/frankenphp'))]]),
            new Result(['Contents' => [self::object('runtime/a/frankenphp', '9 days')]]),
        );

        self::assertSame(0, self::prune($mocks));
        self::assertSame(['GetResources', 'ListVersionsByFunction', 'ListVersionsByFunction', 'ListObjectsV2'], $mocks->calls);
    }

    public function test_any_other_lambda_error_stops_before_deleting(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            new Result(['ResourceTagMappingList' => [['ResourceARN' => 'arn:a']]]),
            AwsMocks::error(LambdaException::class, 'ListVersionsByFunction', 'AccessDeniedException', 403),
        );

        try {
            self::prune($mocks);
            self::fail('expected the error to propagate');
        } catch (LambdaException $e) {
            self::assertSame('AccessDeniedException', $e->getAwsErrorCode());
        }
        self::assertNotContains('DeleteObjects', $mocks->calls);
    }
}
