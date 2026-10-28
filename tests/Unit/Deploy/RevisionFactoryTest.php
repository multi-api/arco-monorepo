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

    public function test_behind_an_emulator_endpoint_domain_keeps_the_configured_auth(): void
    {
        $config = fn (string $auth) => ConfigResolver::resolve(App::make('shop')->region('us-east-1')->auth($auth)
            ->environment('production', fn (Environment $e) => $e->domain('arco.acmeapp.io'))->toArray(), 'production');
        $saved = [getenv('AWS_ENDPOINT_URL'), getenv('AWS_IGNORE_CONFIGURED_ENDPOINT_URLS')];
        try {
            putenv('AWS_ENDPOINT_URL=http://localhost:4566');
            putenv('AWS_IGNORE_CONFIGURED_ENDPOINT_URLS');
            self::assertFalse(RevisionFactory::protectsEndpoint($config('public')));
            self::assertSame('ApplicationManaged', RevisionFactory::authType($config('public')));
            self::assertSame('IamAuth', RevisionFactory::authType($config('iam')));

            // Anything the emulator detection does not accept is real AWS: the protection stays.
            putenv('AWS_IGNORE_CONFIGURED_ENDPOINT_URLS=true');
            self::assertTrue(RevisionFactory::protectsEndpoint($config('public')));
            putenv('AWS_IGNORE_CONFIGURED_ENDPOINT_URLS');
            putenv('AWS_ENDPOINT_URL=not a url');
            self::assertTrue(RevisionFactory::protectsEndpoint($config('public')));
            // A service the protection involves sent elsewhere (real AWS, say) by its own override keeps it.
            putenv('AWS_ENDPOINT_URL=http://localhost:4566');
            foreach (['LAMBDA', 'LAMBDA_WEB', 'CLOUDFRONT', 'CLOUDFORMATION'] as $service) {
                putenv("AWS_ENDPOINT_URL_$service=https://example.amazonaws.com");
                self::assertTrue(RevisionFactory::protectsEndpoint($config('public')), $service);
                putenv("AWS_ENDPOINT_URL_$service=http://localhost:4566/");
                self::assertFalse(RevisionFactory::protectsEndpoint($config('public')), "$service: the same emulator");
                putenv("AWS_ENDPOINT_URL_$service");
            }
            putenv('AWS_ENDPOINT_URL');
            self::assertTrue(RevisionFactory::protectsEndpoint($config('public')));
            self::assertSame('IamAuth', RevisionFactory::authType($config('public')));
        } finally {
            foreach (['LAMBDA', 'LAMBDA_WEB', 'CLOUDFRONT', 'CLOUDFORMATION'] as $service) {
                putenv("AWS_ENDPOINT_URL_$service");
            }
            putenv($saved[0] === false ? 'AWS_ENDPOINT_URL' : "AWS_ENDPOINT_URL=$saved[0]");
            putenv($saved[1] === false ? 'AWS_IGNORE_CONFIGURED_ENDPOINT_URLS' : "AWS_IGNORE_CONFIGURED_ENDPOINT_URLS=$saved[1]");
        }
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

    private static function vpcConfig(bool $vpc = true): \Multiapi\Arco\Config\AppConfig
    {
        return ConfigResolver::resolve(App::make('shop')->region('us-east-1')->environment('production', fn (Environment $e) => $vpc ? $e->vpc(subnets: ['subnet-a'], securityGroups: ['sg-1']) : $e)->toArray(), 'production');
    }

    public function test_the_connector_arn_is_pinned_only_when_given(): void
    {
        $with = RevisionFactory::revision(self::vpcConfig(), 'b', 'k.zip', null, 'arn:role', [], 'arn:connector:3');
        self::assertSame('arn:connector:3', $with['serviceConfig']['egressNetworkConnectorArn']);

        $without = RevisionFactory::revision(self::vpcConfig(), 'b', 'k.zip', null, 'arn:role', []);
        self::assertArrayNotHasKey('egressNetworkConnectorArn', $without['serviceConfig']);
    }

    public function test_uses_vpc_needs_vpc_and_no_emulator_serving_lambda(): void
    {
        $saved = [getenv('AWS_ENDPOINT_URL'), getenv('AWS_ENDPOINT_URL_LAMBDA_CORE')];
        try {
            putenv('AWS_ENDPOINT_URL');
            self::assertTrue(RevisionFactory::usesVpc(self::vpcConfig()));
            self::assertFalse(RevisionFactory::usesVpc(self::vpcConfig(false)));

            putenv('AWS_ENDPOINT_URL=http://localhost:4566');
            self::assertFalse(RevisionFactory::usesVpc(self::vpcConfig()), 'the emulator serves lambda and lambda_web');
            putenv('AWS_ENDPOINT_URL_LAMBDA_CORE=https://example.amazonaws.com');
            self::assertTrue(RevisionFactory::usesVpc(self::vpcConfig()), 'lambda-core sent to real AWS keeps the VPC');
        } finally {
            putenv($saved[0] === false ? 'AWS_ENDPOINT_URL' : "AWS_ENDPOINT_URL=$saved[0]");
            putenv($saved[1] === false ? 'AWS_ENDPOINT_URL_LAMBDA_CORE' : "AWS_ENDPOINT_URL_LAMBDA_CORE=$saved[1]");
        }
    }
}
