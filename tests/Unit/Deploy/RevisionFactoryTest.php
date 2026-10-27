<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy;

use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Config\Environment;
use Multiapi\Arco\Deploy\RevisionFactory;
use PHPUnit\Framework\TestCase;

final class RevisionFactoryTest extends TestCase
{
    public function test_maps_config_to_the_api_shapes(): void
    {
        $config = ConfigResolver::resolve(
            App::make('shop')->region('us-east-1')->environment('production', fn (Environment $e) => $e
                ->timeout(60)->concurrency(16)->logLevel('debug')->auth('iam')->maxInstances(5)->rateLimit(100)->kmsKey('arn:kms'))
                ->toArray(),
            'production',
        );

        $revision = RevisionFactory::revision($config, 'bucket', 'shop/production/h.zip', 'v1', 'arn:role', ['A' => 'b']);
        self::assertSame([
            'description' => 'Deployed by Arco ' . \Multiapi\Arco\Arco::version(),
            'kmsKeyArn' => 'arn:kms',
            'buildConfig' => [
                'codeConfig' => ['s3Object' => ['bucket' => 'bucket', 'key' => 'shop/production/h.zip', 'versionId' => 'v1']],
                'runtimeConfig' => ['runtime' => 'nodejs24.x'],
            ],
            'serviceConfig' => [
                'executionRoleArn' => 'arn:role',
                'timeoutSeconds' => 60,
                'environmentVariables' => ['A' => 'b'],
                'telemetryConfig' => ['loggingConfig' => ['applicationLogLevel' => 'DEBUG', 'systemLogLevel' => 'INFO']],
                'maxConcurrencyPerEnvironment' => 16,
            ],
        ], $revision);

        self::assertSame([
            'endpointName' => 'default',
            'endpointType' => 'HomeRegion',
            'authType' => 'IamAuth',
            'autoDeploymentMode' => 'LatestRevision',
            'scalingConfig' => ['maxEnvironments' => 5],
            'throttleConfig' => ['rateLimit' => 100],
        ], RevisionFactory::initialEndpoint($config));
    }

    public function test_domain_makes_the_endpoint_iam_whatever_auth_says(): void
    {
        $config = fn (string $auth, bool $domain) => ConfigResolver::resolve(App::make('shop')->region('us-east-1')->auth($auth)
            ->environment('production', fn (Environment $e) => $domain ? $e->domain('arco.acmeapp.io') : $e)->toArray(), 'production');

        self::assertSame('ApplicationManaged', RevisionFactory::authType($config('public', false)));
        self::assertSame('IamAuth', RevisionFactory::authType($config('iam', false)));
        self::assertSame('IamAuth', RevisionFactory::authType($config('public', true)), 'only CloudFront may invoke it');
        self::assertSame('IamAuth', RevisionFactory::authType($config('iam', true)));
        self::assertSame('IamAuth', RevisionFactory::initialEndpoint($config('public', true))['authType'], 'a new environment starts locked');
    }

    public function test_omits_empty_variables_because_lambda_rejects_them(): void
    {
        $config = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->environment('production')->toArray(), 'production');

        $revision = RevisionFactory::revision($config, 'bucket', 'k.zip', null, 'arn:role', ['A' => 'b', 'EMPTY' => '']);

        self::assertSame(['A' => 'b'], $revision['serviceConfig']['environmentVariables']);
    }

    public function test_concurrency_is_sent_only_when_set(): void
    {
        $default = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->environment('production')->toArray(), 'production');
        self::assertArrayNotHasKey('maxConcurrencyPerEnvironment', RevisionFactory::revision($default, 'b', 'k.zip', null, 'arn:role', [])['serviceConfig']);

        $set = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->concurrency(16)->environment('production')->toArray(), 'production');
        self::assertSame(16, RevisionFactory::revision($set, 'b', 'k.zip', null, 'arn:role', [])['serviceConfig']['maxConcurrencyPerEnvironment']);
    }
}
