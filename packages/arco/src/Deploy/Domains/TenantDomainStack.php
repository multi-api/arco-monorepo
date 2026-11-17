<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy\Domains;

use Multiapi\Arco\Config\AppConfig;

/** Shared tenant distribution and connection group; tenants are managed outside the stack. */
final class TenantDomainStack
{
    public static function stackName(AppConfig $config): string
    {
        return substr('arco-' . $config->functionName(), 0, 115) . '-tenants';
    }

    private static function name(AppConfig $config, string $suffix): string
    {
        $name = 'arco-' . $config->functionName() . '-tenant-' . $suffix;
        return strlen($name) <= 64 ? $name : substr($name, 0, 55) . '-' . substr(md5($name), 0, 8);
    }

    /**
     * @param list<string> $cachedPaths
     * @param bool $originAccess sign origin requests (RevisionFactory::protectsEndpoint()); false only behind an emulator
     * @return array<string, mixed>
     */
    public static function template(AppConfig $config, string $endpointDomain, array $cachedPaths, bool $originAccess = true): array
    {
        $function = ['EventType' => 'viewer-request', 'FunctionARN' => ['Fn::GetAtt' => ['ForwardHost', 'FunctionARN']]];
        $policy = ['Fn::GetAtt' => ['OriginRequestPolicy', 'Id']];
        $distribution = [
            'ConnectionMode' => 'tenant-only',
            'Enabled' => true,
            'Comment' => "Arco tenant domains {$config->app}/{$config->environment}",
            'HttpVersion' => 'http2and3',
            'Origins' => [[
                'Id' => 'arco-endpoint',
                'DomainName' => $endpointDomain,
                'OriginAccessControlId' => ['Fn::GetAtt' => ['OriginAccess', 'Id']],
                'CustomOriginConfig' => [
                    'OriginProtocolPolicy' => 'https-only',
                    'HTTPSPort' => 443,
                    'OriginSSLProtocols' => ['TLSv1.2'],
                    'OriginReadTimeout' => min($config->timeout + 2, 60),
                    'OriginKeepaliveTimeout' => 5,
                ],
            ]],
            'DefaultCacheBehavior' => [
                'TargetOriginId' => 'arco-endpoint',
                'ViewerProtocolPolicy' => 'redirect-to-https',
                'AllowedMethods' => ['GET', 'HEAD', 'OPTIONS', 'PUT', 'PATCH', 'POST', 'DELETE'],
                'CachedMethods' => ['GET', 'HEAD'],
                'CachePolicyId' => DomainStack::CACHING_DISABLED,
                'OriginRequestPolicyId' => $policy,
                'Compress' => true,
                'FunctionAssociations' => [$function],
            ],
        ];
        if ($cachedPaths !== []) {
            $distribution['CacheBehaviors'] = array_map(fn (string $path) => [
                'PathPattern' => $path,
                'TargetOriginId' => 'arco-endpoint',
                'ViewerProtocolPolicy' => 'redirect-to-https',
                'AllowedMethods' => ['GET', 'HEAD'],
                'CachedMethods' => ['GET', 'HEAD'],
                'CachePolicyId' => ['Fn::GetAtt' => ['AssetCachePolicy', 'Id']],
                'OriginRequestPolicyId' => $policy,
                'Compress' => true,
                'FunctionAssociations' => [$function],
            ], $cachedPaths);
        }

        $code = "import cf from 'cloudfront';\n" . str_replace(
            '  return request;',
            '  cf.updateRequestOrigin({hostHeader: ' . json_encode($endpointDomain, JSON_THROW_ON_ERROR) . "});\n  return request;",
            DomainStack::functionCode(),
        );

        if (! $originAccess) {
            unset($distribution['Origins'][0]['OriginAccessControlId']);
        }

        $template = [
            'AWSTemplateFormatVersion' => '2010-09-09',
            'Description' => "Arco tenant domains for {$config->app}/{$config->environment}",
            'Resources' => [
                'ForwardHost' => [
                    'Type' => 'AWS::CloudFront::Function',
                    'Properties' => [
                        'Name' => self::name($config, 'host'),
                        'AutoPublish' => true,
                        'FunctionCode' => $code,
                        'FunctionConfig' => ['Comment' => 'Arco tenant host and query forwarding', 'Runtime' => 'cloudfront-js-2.0'],
                    ],
                ],
                'OriginAccess' => [
                    'Type' => 'AWS::CloudFront::OriginAccessControl',
                    'Properties' => ['OriginAccessControlConfig' => [
                        'Name' => self::name($config, 'oac'),
                        'Description' => "Arco tenant domains: signs requests to {$config->app}/{$config->environment}",
                        'OriginAccessControlOriginType' => 'lambda',
                        'SigningBehavior' => DomainStack::SIGNING_BEHAVIOR,
                        'SigningProtocol' => 'sigv4',
                    ]],
                ],
                'OriginRequestPolicy' => [
                    'Type' => 'AWS::CloudFront::OriginRequestPolicy',
                    'Properties' => ['OriginRequestPolicyConfig' => [
                        'Name' => self::name($config, 'origin'),
                        'HeadersConfig' => ['HeaderBehavior' => 'allViewerAndWhitelistCloudFront', 'Headers' => ['CloudFront-Viewer-Address']],
                        'CookiesConfig' => ['CookieBehavior' => 'all'],
                        'QueryStringsConfig' => ['QueryStringBehavior' => 'all'],
                    ]],
                ],
                'AssetCachePolicy' => [
                    'Type' => 'AWS::CloudFront::CachePolicy',
                    'Properties' => ['CachePolicyConfig' => [
                        'Name' => self::name($config, 'assets'),
                        'MinTTL' => 1,
                        'DefaultTTL' => 86400,
                        'MaxTTL' => 31536000,
                        'ParametersInCacheKeyAndForwardedToOrigin' => [
                            'EnableAcceptEncodingBrotli' => true,
                            'EnableAcceptEncodingGzip' => true,
                            'HeadersConfig' => ['HeaderBehavior' => 'whitelist', 'Headers' => ['host']],
                            'CookiesConfig' => ['CookieBehavior' => 'none'],
                            'QueryStringsConfig' => ['QueryStringBehavior' => 'none'],
                        ],
                    ]],
                ],
                'Distribution' => ['Type' => 'AWS::CloudFront::Distribution', 'Properties' => ['DistributionConfig' => $distribution]],
                'ConnectionGroup' => [
                    'Type' => 'AWS::CloudFront::ConnectionGroup',
                    'Properties' => ['Name' => self::name($config, 'connection'), 'Enabled' => true, 'Ipv6Enabled' => true],
                ],
            ],
            'Outputs' => [
                'DistributionId' => ['Value' => ['Ref' => 'Distribution']],
                'ConnectionGroupId' => ['Value' => ['Fn::GetAtt' => ['ConnectionGroup', 'Id']]],
                'RoutingEndpoint' => ['Value' => ['Fn::GetAtt' => ['ConnectionGroup', 'RoutingEndpoint']]],
                // Lets a later deploy refuse a changed target, and remove find the record.
                'Target' => ['Value' => $config->tenantDomainTarget],
            ],
        ];
        if (! $originAccess) {
            unset($template['Resources']['OriginAccess']);
        }
        return $template;
    }
}