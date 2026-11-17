<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy\Domains;

use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Deploy\Domains\TenantDomainStack;
use PHPUnit\Framework\TestCase;

final class TenantDomainStackTest extends TestCase
{
    public function test_template_builds_a_shared_distribution_and_connection_group_without_tenants(): void
    {
        $config = ConfigResolver::resolve(App::make('shop')->region('us-east-1')
            ->tenantDomains('routing.example.com')->environment('production')->toArray(), 'production');

        $template = TenantDomainStack::template($config, 'origin.lambda-web.example.com', ['/build/*']);
        $resources = $template['Resources'];
        $distribution = $resources['Distribution']['Properties']['DistributionConfig'];

        self::assertSame('tenant-only', $distribution['ConnectionMode']);
        self::assertArrayNotHasKey('Aliases', $distribution);
        self::assertArrayNotHasKey('PriceClass', $distribution);
        self::assertArrayNotHasKey('ViewerCertificate', $distribution);
        self::assertSame('origin.lambda-web.example.com', $distribution['Origins'][0]['DomainName']);
        self::assertSame(['Fn::GetAtt' => ['OriginAccess', 'Id']], $distribution['Origins'][0]['OriginAccessControlId']);
        self::assertSame('always-amz-auth', $resources['OriginAccess']['Properties']['OriginAccessControlConfig']['SigningBehavior']);
        self::assertSame('AWS::CloudFront::ConnectionGroup', $resources['ConnectionGroup']['Type']);
        self::assertSame(['Value' => ['Fn::GetAtt' => ['ConnectionGroup', 'RoutingEndpoint']]], $template['Outputs']['RoutingEndpoint']);
        self::assertSame(['Value' => ['Fn::GetAtt' => ['ConnectionGroup', 'Id']]], $template['Outputs']['ConnectionGroupId']);
        foreach ($resources as $resource) {
            self::assertNotSame('AWS::CloudFront::DistributionTenant', $resource['Type']);
        }
    }

    public function test_request_policy_preserves_viewer_data_and_cache_is_isolated_by_host(): void
    {
        $config = ConfigResolver::resolve(App::make('shop')->region('us-east-1')
            ->tenantDomains('routing.example.com')->environment('production')->toArray(), 'production');
        $template = TenantDomainStack::template($config, 'origin.lambda-web.example.com', ['/build/*']);
        $resources = $template['Resources'];
        $distribution = $resources['Distribution']['Properties']['DistributionConfig'];
        $request = $resources['OriginRequestPolicy']['Properties']['OriginRequestPolicyConfig'];
        self::assertSame('allViewerAndWhitelistCloudFront', $request['HeadersConfig']['HeaderBehavior']);
        self::assertContains('CloudFront-Viewer-Address', $request['HeadersConfig']['Headers']);
        self::assertSame('all', $request['CookiesConfig']['CookieBehavior']);
        self::assertSame('all', $request['QueryStringsConfig']['QueryStringBehavior']);
        self::assertSame(['Fn::GetAtt' => ['OriginRequestPolicy', 'Id']], $distribution['DefaultCacheBehavior']['OriginRequestPolicyId']);
        self::assertSame(['host'], $resources['AssetCachePolicy']['Properties']['CachePolicyConfig']['ParametersInCacheKeyAndForwardedToOrigin']['HeadersConfig']['Headers']);
        self::assertSame(['Fn::GetAtt' => ['AssetCachePolicy', 'Id']], $distribution['CacheBehaviors'][0]['CachePolicyId']);
        self::assertStringContainsString('updateRequestOrigin', $resources['ForwardHost']['Properties']['FunctionCode']);
        self::assertStringContainsString('origin.lambda-web.example.com', $resources['ForwardHost']['Properties']['FunctionCode']);
    }
}