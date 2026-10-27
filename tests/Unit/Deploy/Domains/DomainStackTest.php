<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy\Domains;

use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Config\Environment;
use Multiapi\Arco\Deploy\Domains\DomainStack;
use PHPUnit\Framework\TestCase;

final class DomainStackTest extends TestCase
{
    private const CERT = 'arn:aws:acm:us-east-1:111111111111:certificate/abc';

    private function config(int $timeout = 30, string $app = 'shop', string $env = 'production'): AppConfig
    {
        return ConfigResolver::resolve(
            App::make($app)->region('us-east-2')->environment($env, fn (Environment $e) => $e
                ->timeout($timeout)
                ->domain(['arco.acmeapp.io', 'api-arco.acmeapp.io']))->toArray(),
            $env,
        );
    }

    /** @return array<string, mixed> */
    private function template(?AppConfig $config = null, array $cachedPaths = []): array
    {
        return DomainStack::template($config ?? $this->config(), 'abc.xpt.lambda-web.us-east-2.on.aws', self::CERT, $cachedPaths);
    }

    public function test_names(): void
    {
        self::assertSame('arco-shop-production', DomainStack::stackName($this->config()));
        self::assertSame('arco-shop-production-host', DomainStack::functionName($this->config()));

        $long = $this->config(app: str_repeat('a', 40), env: str_repeat('b', 23));
        $name = DomainStack::functionName($long);
        self::assertSame(64, strlen($name));
        self::assertMatchesRegularExpression('/^arco-a+-b+-[0-9a-f]{8}$/', $name);
        self::assertNotSame($name, DomainStack::functionName($this->config(app: str_repeat('a', 40), env: str_repeat('b', 22) . 'c')));
    }

    public function test_origin_access_control_signs_every_origin_request(): void
    {
        $template = $this->template();
        self::assertSame([
            'Type' => 'AWS::CloudFront::OriginAccessControl',
            'Properties' => ['OriginAccessControlConfig' => [
                'Name' => 'arco-shop-production-oac',
                'Description' => 'Arco: signs requests to shop/production',
                'OriginAccessControlOriginType' => 'lambda',
                'SigningBehavior' => 'always-amz-auth',
                'SigningProtocol' => 'sigv4',
            ]],
        ], $template['Resources']['OriginAccess']);
        self::assertSame(['Fn::GetAtt' => ['OriginAccess', 'Id']], $template['Resources']['Distribution']['Properties']['DistributionConfig']['Origins'][0]['OriginAccessControlId']);
        self::assertSame(['Value' => ['Ref' => 'Distribution']], $template['Outputs']['DistributionId'], 'the id the resource policy names');
    }

    public function test_without_origin_access_the_template_has_no_access_control(): void
    {
        $template = DomainStack::template($this->config(), 'abc.xpt.lambda-web.us-east-2.on.aws', self::CERT, [], false);
        self::assertSame(['ForwardHost', 'Distribution'], array_keys($template['Resources']));
        self::assertArrayNotHasKey('OriginAccessControlId', $template['Resources']['Distribution']['Properties']['DistributionConfig']['Origins'][0]);
        self::assertSame(['ForwardHost', 'OriginAccess', 'Distribution'], array_keys($this->template()['Resources']), 'the default signs');
    }

    public function test_origin_access_control_names_fit_64_characters_and_stay_unique(): void
    {
        $long = $this->config(app: str_repeat('a', 40), env: str_repeat('b', 23));
        $name = DomainStack::originAccessName($long);
        self::assertSame(64, strlen($name));
        self::assertMatchesRegularExpression('/^arco-a+-b+-[0-9a-f]{8}$/', $name);
        self::assertNotSame(DomainStack::functionName($long), $name);
        self::assertNotSame($name, DomainStack::originAccessName($this->config(app: str_repeat('a', 40), env: str_repeat('b', 22) . 'c')));
    }

    public function test_distribution(): void
    {
        $template = $this->template();
        $distribution = $template['Resources']['Distribution'];
        self::assertSame('AWS::CloudFront::Distribution', $distribution['Type']);
        $config = $distribution['Properties']['DistributionConfig'];

        self::assertTrue($config['Enabled']);
        self::assertSame(['arco.acmeapp.io', 'api-arco.acmeapp.io'], $config['Aliases']);
        self::assertSame(['AcmCertificateArn' => self::CERT, 'SslSupportMethod' => 'sni-only', 'MinimumProtocolVersion' => 'TLSv1.2_2021'], $config['ViewerCertificate']);
        self::assertSame('http2and3', $config['HttpVersion']);
        self::assertTrue($config['IPV6Enabled']);
        self::assertSame('PriceClass_All', $config['PriceClass']);
        self::assertSame('Arco shop/production', $config['Comment']);

        $origin = $config['Origins'][0];
        self::assertSame('abc.xpt.lambda-web.us-east-2.on.aws', $origin['DomainName']);
        self::assertSame('https-only', $origin['CustomOriginConfig']['OriginProtocolPolicy']);
        self::assertSame(['TLSv1.2'], $origin['CustomOriginConfig']['OriginSSLProtocols']);
        self::assertSame(32, $origin['CustomOriginConfig']['OriginReadTimeout']);
        self::assertSame(5, $origin['CustomOriginConfig']['OriginKeepaliveTimeout']);

        $default = $config['DefaultCacheBehavior'];
        self::assertSame($origin['Id'], $default['TargetOriginId']);
        self::assertSame('redirect-to-https', $default['ViewerProtocolPolicy']);
        self::assertSame(['GET', 'HEAD', 'OPTIONS', 'PUT', 'PATCH', 'POST', 'DELETE'], $default['AllowedMethods']);
        self::assertSame('4135ea2d-6df8-44a3-9df3-4b5a84be39ad', $default['CachePolicyId']);
        self::assertSame('b689b0a8-53d0-40ab-baf2-68738e2966ac', $default['OriginRequestPolicyId']);
        self::assertTrue($default['Compress']);
        self::assertSame([['EventType' => 'viewer-request', 'FunctionARN' => ['Fn::GetAtt' => ['ForwardHost', 'FunctionARN']]]], $default['FunctionAssociations']);
        self::assertArrayNotHasKey('CacheBehaviors', $config, 'no asset behavior without cached paths');

        self::assertSame(['Value' => ['Ref' => 'Distribution']], $template['Outputs']['DistributionId']);
        self::assertSame(['Value' => ['Fn::GetAtt' => ['Distribution', 'DomainName']]], $template['Outputs']['DistributionDomain']);
        self::assertSame(['Value' => 'arco.acmeapp.io,api-arco.acmeapp.io'], $template['Outputs']['Aliases']);
    }

    public function test_origin_read_timeout_is_clamped_to_60(): void
    {
        $origin = $this->template($this->config(timeout: 300))['Resources']['Distribution']['Properties']['DistributionConfig']['Origins'][0];
        self::assertSame(60, $origin['CustomOriginConfig']['OriginReadTimeout']);
        $origin = $this->template($this->config(timeout: 3))['Resources']['Distribution']['Properties']['DistributionConfig']['Origins'][0];
        self::assertSame(5, $origin['CustomOriginConfig']['OriginReadTimeout']);
    }

    public function test_cached_paths_get_an_asset_behavior(): void
    {
        $behaviors = $this->template(cachedPaths: ['/build/*'])['Resources']['Distribution']['Properties']['DistributionConfig']['CacheBehaviors'];

        self::assertCount(1, $behaviors);
        self::assertSame('/build/*', $behaviors[0]['PathPattern']);
        self::assertSame(['GET', 'HEAD'], $behaviors[0]['AllowedMethods']);
        self::assertSame('658327ea-f89d-4fab-a63d-7e88639e58f6', $behaviors[0]['CachePolicyId']);
        self::assertArrayNotHasKey('OriginRequestPolicyId', $behaviors[0]);
        self::assertTrue($behaviors[0]['Compress']);
        self::assertSame('redirect-to-https', $behaviors[0]['ViewerProtocolPolicy']);
        self::assertSame([['EventType' => 'viewer-request', 'FunctionARN' => ['Fn::GetAtt' => ['ForwardHost', 'FunctionARN']]]], $behaviors[0]['FunctionAssociations']);
    }

    public function test_function_copies_the_host_header(): void
    {
        $function = $this->template()['Resources']['ForwardHost'];

        self::assertSame('AWS::CloudFront::Function', $function['Type']);
        self::assertSame('arco-shop-production-host', $function['Properties']['Name']);
        self::assertTrue($function['Properties']['AutoPublish']);
        self::assertSame('cloudfront-js-2.0', $function['Properties']['FunctionConfig']['Runtime']);
        $code = $function['Properties']['FunctionCode'];
        self::assertStringContainsString("request.headers['x-forwarded-host'] = { value: request.headers.host.value }", $code);
        self::assertStringContainsString("delete request.headers['x-forwarded-host'];", $code, 'a client-supplied value never reaches the app');
        self::assertStringContainsString('return request;', $code);
    }

    public function test_template_is_valid_json(): void
    {
        $json = DomainStack::body($this->template());
        self::assertIsArray(json_decode($json, true));
        self::assertStringContainsString('"/build/*"', DomainStack::body($this->template(cachedPaths: ['/build/*'])), 'slashes are not escaped');
    }
}
