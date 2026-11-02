<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy;

use Aws\Result;
use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Config\Environment;
use Multiapi\Arco\Deploy\VariableBundle;
use PHPUnit\Framework\TestCase;

final class VariableBundleTest extends TestCase
{
    private const BUCKET = 'arco-111111111111-us-east-1';

    private static function config(?string $kmsKey = null): AppConfig
    {
        $app = App::make('shop')->region('us-east-1');
        if ($kmsKey !== null) {
            $app = $app->kmsKey($kmsKey);
        }
        return ConfigResolver::resolve($app->environment('staging', fn (Environment $e) => $e->bundleVariables())->toArray(), 'staging');
    }

    public function test_arco_variables_stay_in_lambda_and_the_rest_goes_to_the_bundle(): void
    {
        $split = VariableBundle::split([
            'APP_KEY' => 'arco-ssm:/shop/staging/app-key',
            'ARCO' => '1',
            'ARCO_APP' => 'shop',
            'ARCOLIKE' => 'bundled: only ARCO and ARCO_* are Arco\'s',
            'QUEUE_CONNECTION' => 'sqs',
            'EMPTY' => '',
        ]);

        self::assertSame(['ARCO' => '1', 'ARCO_APP' => 'shop'], $split['environment']);
        self::assertSame([
            'APP_KEY' => 'arco-ssm:/shop/staging/app-key',
            'ARCOLIKE' => 'bundled: only ARCO and ARCO_* are Arco\'s',
            'QUEUE_CONNECTION' => 'sqs',
        ], $split['bundle'], 'an empty value is left out, as Lambda leaves it out of the environment');
    }

    public function test_the_body_is_stable_and_the_key_is_its_hash(): void
    {
        $a = VariableBundle::body(['B' => '2', 'A' => "line\nbreak \"quoted\" ñ /path"]);
        $b = VariableBundle::body(['A' => "line\nbreak \"quoted\" ñ /path", 'B' => '2']);

        self::assertSame($a, $b, 'the order of the variables does not change the object');
        self::assertSame(['schema' => 1, 'variables' => ['A' => "line\nbreak \"quoted\" ñ /path", 'B' => '2']], json_decode($a, true));
        self::assertSame('variables/shop/staging/' . hash('sha256', $a) . '.json', VariableBundle::key(self::config(), $a));
    }

    public function test_store_puts_the_object_and_leaves_arco_variables_and_the_pointer(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(new Result(['VersionId' => 'v1']));
        $steps = [];

        $stored = (new VariableBundle($mocks->clients()->s3()))->store(self::config(), self::BUCKET, [
            'APP_NAME' => 'Shop',
            'APP_KEY' => 'arco-ssm:/shop/staging/app-key',
            'ARCO' => '1',
            'ARCO_ENV' => 'staging',
        ], function (string $key, array $params) use (&$steps): void { $steps[] = [$key, $params]; });

        $body = VariableBundle::body(['APP_NAME' => 'Shop', 'APP_KEY' => 'arco-ssm:/shop/staging/app-key']);
        $key = 'variables/shop/staging/' . hash('sha256', $body) . '.json';
        self::assertSame(['PutObject'], $mocks->calls, 'always put, so LastModified is fresh for the prune');
        self::assertSame(self::BUCKET, $mocks->params[0]['Bucket']);
        self::assertSame($key, $mocks->params[0]['Key']);
        self::assertSame($body, (string) $mocks->params[0]['Body']);
        self::assertSame('application/json', $mocks->params[0]['ContentType']);
        self::assertArrayNotHasKey('ServerSideEncryption', $mocks->params[0], 'the bucket default (SSE-S3) without kmsKey()');
        self::assertSame($key, $stored['key']);
        self::assertSame(['ARCO' => '1', 'ARCO_ENV' => 'staging', 'ARCO_VARIABLES' => 's3://' . self::BUCKET . '/' . $key], $stored['variables']);
        self::assertSame([['deploy.step_variables', ['count' => 2, 'bucket' => self::BUCKET, 'key' => $key]]], $steps);
    }

    public function test_with_kms_key_the_object_is_encrypted_with_it(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(new Result([]));

        (new VariableBundle($mocks->clients()->s3()))->store(self::config('arn:aws:kms:us-east-1:111111111111:key/k'), self::BUCKET, ['A' => '1']);

        self::assertSame('aws:kms', $mocks->params[0]['ServerSideEncryption']);
        self::assertSame('arn:aws:kms:us-east-1:111111111111:key/k', $mocks->params[0]['SSEKMSKeyId']);
    }

    public function test_prune_keeps_the_newest_and_the_current_object(): void
    {
        $objects = [];
        for ($i = 0; $i < 22; $i++) {
            $objects[] = ['Key' => "variables/shop/staging/$i.json", 'LastModified' => new \DateTimeImmutable("2026-01-01 +$i minutes")];
        }
        $mocks = new AwsMocks();
        $mocks->handler->append(new Result(['Contents' => $objects]), new Result([]));

        (new VariableBundle($mocks->clients()->s3()))->prune(self::config(), self::BUCKET, 'variables/shop/staging/0.json');

        self::assertSame(['ListObjectsV2', 'DeleteObjects'], $mocks->calls);
        self::assertSame('variables/shop/staging/', $mocks->params[0]['Prefix']);
        self::assertSame([['Key' => 'variables/shop/staging/1.json']], $mocks->params[1]['Delete']['Objects'], 'the 20 newest stay, and the one this deploy uses');
    }
}
