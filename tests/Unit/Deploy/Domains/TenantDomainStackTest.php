<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy\Domains;

use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Deploy\Domains\DomainStack;
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
        // Real CloudFront refuses a tenant-only distribution without this block ("configured for SNI and the minimum
        // SSL protocol version is specified as SSLv3"): no certificate, SNI, an explicit security policy.
        self::assertSame(['CloudFrontDefaultCertificate' => true, 'SslSupportMethod' => 'sni-only', 'MinimumProtocolVersion' => 'TLSv1.2_2021'], $distribution['ViewerCertificate']);
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

    public function test_requests_reach_the_origin_as_with_domain_and_assets_are_cached_per_customer_host(): void
    {
        $config = ConfigResolver::resolve(App::make('shop')->region('us-east-1')
            ->tenantDomains('routing.example.com')->environment('production')->toArray(), 'production');
        $template = TenantDomainStack::template($config, 'origin.lambda-web.example.com', ['/build/*']);
        $resources = $template['Resources'];
        $distribution = $resources['Distribution']['Properties']['DistributionConfig'];

        // Real CloudFront: forwarding the viewer's Host breaks the signed origin request, and rewriting the origin
        // from the function is refused ("The CloudFront function returned an invalid value for origin rewrite").
        // So exactly what domain() does: every viewer header, cookie and query string except Host.
        self::assertSame(DomainStack::ALL_VIEWER_EXCEPT_HOST_HEADER, $distribution['DefaultCacheBehavior']['OriginRequestPolicyId']);
        self::assertSame(DomainStack::CACHING_DISABLED, $distribution['DefaultCacheBehavior']['CachePolicyId']);
        self::assertArrayNotHasKey('OriginRequestPolicy', $resources);
        self::assertSame(DomainStack::functionCode(), $resources['ForwardHost']['Properties']['FunctionCode'], 'the same viewer-request function as domain()');
        self::assertStringNotContainsString('updateRequestOrigin', $resources['ForwardHost']['Properties']['FunctionCode']);

        // Assets are cached per customer host through the function's X-Forwarded-Host, never through Host.
        $cache = $resources['AssetCachePolicy']['Properties']['CachePolicyConfig']['ParametersInCacheKeyAndForwardedToOrigin'];
        self::assertSame(['x-forwarded-host'], $cache['HeadersConfig']['Headers']);
        self::assertSame(['Fn::GetAtt' => ['AssetCachePolicy', 'Id']], $distribution['CacheBehaviors'][0]['CachePolicyId']);
        self::assertArrayNotHasKey('OriginRequestPolicyId', $distribution['CacheBehaviors'][0]);
        self::assertStringNotContainsString('"host"', strtolower(json_encode($cache, JSON_THROW_ON_ERROR)));
    }
}