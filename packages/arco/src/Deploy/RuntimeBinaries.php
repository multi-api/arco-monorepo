<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Aws\Lambda\Exception\LambdaException;
use Aws\Lambda\LambdaClient;
use Aws\ResourceGroupsTaggingAPI\ResourceGroupsTaggingAPIClient;
use Aws\S3\S3Client;

/**
 * Deletes runtime binaries (runtime/<build>/frankenphp, runtime/custom/<sha256>/frankenphp) that no
 * published version of any Arco function in this region still downloads. The code bucket is shared by
 * every Arco app and environment of the account and region, and any published version can be rolled
 * back to, so every version of every function tagged arco:app is read. A binary uploaded less than
 * 24 hours ago is kept: another deploy may have uploaded it and not published its version yet.
 */
final class RuntimeBinaries
{
    public const PREFIX = 'runtime/';
    private const MIN_AGE_SECONDS = 86400;
    private const DELETE_BATCH = 1000;

    public function __construct(
        private readonly ResourceGroupsTaggingAPIClient $tagging,
        private readonly LambdaClient $lambda,
        private readonly S3Client $s3,
    ) {
    }

    /**
     * @param list<string> $keep keys never deleted (the binary of the deploy running this prune)
     * @return int objects deleted
     */
    public function prune(string $bucket, \DateTimeImmutable $now, array $keep = []): int
    {
        // Read before the bucket listing: a binary listed afterwards is either referenced here or too new.
        $referenced = $this->referencedKeys($bucket) + array_fill_keys($keep, true);
        $cutoff = $now->getTimestamp() - self::MIN_AGE_SECONDS;

        $delete = [];
        foreach ($this->objects($bucket) as $object) {
            $key = $object['Key'] ?? null;
            $modified = $object['LastModified'] ?? null;
            if (! is_string($key) || ! $modified instanceof \DateTimeInterface || isset($referenced[$key])) {
                continue;
            }
            if ($modified->getTimestamp() < $cutoff) {
                $delete[] = ['Key' => $key];
            }
        }
        foreach (array_chunk($delete, self::DELETE_BATCH) as $chunk) {
            $this->s3->deleteObjects(['Bucket' => $bucket, 'Delete' => ['Objects' => $chunk, 'Quiet' => true]]);
        }
        return count($delete);
    }

    /** @return array<string, true> ARCO_BINARY_KEY of every version whose ARCO_BINARY_BUCKET is $bucket */
    private function referencedKeys(string $bucket): array
    {
        $keys = [];
        $token = null;
        do {
            $params = ['ResourceTypeFilters' => ['lambda:function'], 'TagFilters' => [['Key' => 'arco:app']]];
            if ($token !== null) {
                $params['PaginationToken'] = $token;
            }
            $page = $this->tagging->getResources($params);
            $resources = $page['ResourceTagMappingList'] ?? [];
            foreach (is_array($resources) ? $resources : [] as $resource) {
                $arn = is_array($resource) ? ($resource['ResourceARN'] ?? null) : null;
                if (is_string($arn) && $arn !== '') {
                    $keys += $this->keysOf($arn, $bucket);
                }
            }
            $token = is_string($page['PaginationToken'] ?? null) && $page['PaginationToken'] !== '' ? $page['PaginationToken'] : null;
        } while ($token !== null);
        return $keys;
    }

    /** @return array<string, true> */
    private function keysOf(string $function, string $bucket): array
    {
        $keys = [];
        $marker = null;
        do {
            $params = ['FunctionName' => $function];
            if ($marker !== null) {
                $params['Marker'] = $marker;
            }
            try {
                $page = $this->lambda->listVersionsByFunction($params);
            } catch (LambdaException $e) {
                // The tag listing lags behind: a function deleted since then has no versions left.
                if ($e->getAwsErrorCode() === 'ResourceNotFoundException') {
                    return $keys;
                }
                throw $e;
            }
            $versions = $page['Versions'] ?? [];
            foreach (is_array($versions) ? $versions : [] as $version) {
                $environment = is_array($version) ? ($version['Environment'] ?? null) : null;
                $variables = is_array($environment) ? ($environment['Variables'] ?? null) : null;
                if (is_array($variables) && ($variables['ARCO_BINARY_BUCKET'] ?? null) === $bucket && is_string($variables['ARCO_BINARY_KEY'] ?? null)) {
                    $keys[$variables['ARCO_BINARY_KEY']] = true;
                }
            }
            $marker = is_string($page['NextMarker'] ?? null) && $page['NextMarker'] !== '' ? $page['NextMarker'] : null;
        } while ($marker !== null);
        return $keys;
    }

    /** @return list<array<mixed>> */
    private function objects(string $bucket): array
    {
        $objects = [];
        $token = null;
        do {
            $params = ['Bucket' => $bucket, 'Prefix' => self::PREFIX];
            if ($token !== null) {
                $params['ContinuationToken'] = $token;
            }
            $page = $this->s3->listObjectsV2($params);
            $contents = $page['Contents'] ?? [];
            foreach (is_array($contents) ? $contents : [] as $object) {
                if (is_array($object)) {
                    $objects[] = $object;
                }
            }
            $next = $page['NextContinuationToken'] ?? null;
            $token = ($page['IsTruncated'] ?? false) === true && is_string($next) && $next !== '' ? $next : null;
        } while ($token !== null);
        return $objects;
    }
}
