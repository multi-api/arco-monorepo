<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy;

use Aws\Iam\Exception\IamException;
use Aws\Lambda\Exception\LambdaException;
use Aws\Result;
use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Config\Environment;
use Multiapi\Arco\Deploy\DeployException;
use Multiapi\Arco\Deploy\InlineZip;
use Multiapi\Arco\Deploy\TenantMediator;
use Multiapi\Arco\Tests\Support\TempPaths;
use PHPUnit\Framework\TestCase;

final class TenantMediatorTest extends TestCase
{
    private const INFRA = ['target' => 'routing.acmeapp.io', 'endpoint' => 'd222.cloudfront.net', 'distributionId' => 'ETENANT', 'connectionGroupId' => 'cg_1'];
    private const TAGS = ['arco:app' => 'shop', 'arco:environment' => 'production'];
    private const ROLE_ARN = 'arn:aws:iam::111111111111:role/arco-shop-production-tenants';
    private const ACTIVE = ['State' => 'Active', 'LastUpdateStatus' => 'Successful'];

    private function config(?string $boundary = null, string $app = 'shop'): AppConfig
    {
        return ConfigResolver::resolve(App::make($app)->region('us-east-1')->environment('production', function (Environment $e) use ($boundary): void {
            $e->domain(['Arco.acmeapp.io', 'www.acmeapp.io'])->tenantDomains('routing.acmeapp.io');
            if ($boundary !== null) {
                $e->permissionsBoundary($boundary);
            }
        })->toArray(), 'production');
    }

    /** A runtime directory with the mediator's code, a file that must stay out of the zip and the suffix list. */
    private function runtime(bool $entry = true, bool $suffixes = true): string
    {
        $dir = TempPaths::path('tenant-runtime-');
        mkdir($dir . '/lib/tenants/nested', 0777, true);
        if ($entry) {
            file_put_contents($dir . '/tenant-domains.js', "exports.handler = async () => ({});\n");
        }
        file_put_contents($dir . '/lib/tenants/mediator.js', "module.exports = {};\n");
        file_put_contents($dir . '/lib/tenants/nested/deep.js', "// deep\n");
        file_put_contents($dir . '/lib/boot.js', "// the web runtime: not shipped\n");
        if ($suffixes) {
            file_put_contents($dir . '/lib/tenants/public_suffix_list.dat', "com\nio\n");
        }
        return $dir;
    }

    private static function noRole(): IamException
    {
        return AwsMocks::error(IamException::class, 'GetRole', 'NoSuchEntity', 404);
    }

    private static function noFunction(string $operation = 'GetFunctionConfiguration'): LambdaException
    {
        return AwsMocks::error(LambdaException::class, $operation, 'ResourceNotFoundException', 404);
    }

    /** @param array<string, string> $tags */
    private static function role(array $tags = self::TAGS): Result
    {
        $tags += ['arco:region' => 'us-east-1']; // a role this deploy made carries the region too
        return new Result(['Role' => ['Arn' => self::ROLE_ARN, 'Tags' => array_map(fn ($k, $v) => ['Key' => $k, 'Value' => $v], array_keys($tags), $tags)]]);
    }

    /** @param array<string, mixed> $extra */
    private static function existing(array $extra = []): Result
    {
        return new Result([...self::ACTIVE, 'FunctionName' => 'shop-production-tenants', 'FunctionArn' => 'arn:aws:lambda:us-east-1:111111111111:function:shop-production-tenants', ...$extra]);
    }

    private function mediator(AwsMocks $mocks, ?string $runtime = null): TenantMediator
    {
        return new TenantMediator($mocks->clients(), AwsMocks::noSleep(), $runtime ?? $this->runtime());
    }

    /** @return list<array{string, array<string, mixed>}> */
    private function deploy(TenantMediator $mediator, ?AppConfig $config = null): array
    {
        $steps = [];
        $mediator->deploy($config ?? $this->config(), self::INFRA, self::TAGS, function (string $key, array $params = []) use (&$steps): void {
            $steps[] = [$key, $params];
        });
        return $steps;
    }

    /** @return array<string, string> the zip's entries by name */
    private static function unzip(string $bytes): array
    {
        $path = TempPaths::file('tenant-zip');
        file_put_contents($path, $bytes);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::RDONLY | \ZipArchive::CHECKCONS));
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[(string) $zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        return $entries;
    }

    public function test_first_deploy_creates_the_role_then_the_function(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::noRole(), self::noFunction(),                                      // preflight
            new Result(['Account' => '111111111111']),                              // GetCallerIdentity
            self::noRole(), new Result(['Role' => ['Arn' => self::ROLE_ARN]]), new Result([]), // GetRole, CreateRole, PutRolePolicy
            self::noFunction(),                                                       // exists?
            new Result(['FunctionName' => 'shop-production-tenants', 'State' => 'Pending']),
            self::existing(),                                                         // ready
        );

        $steps = $this->deploy($this->mediator($mocks));

        self::assertSame(['GetRole', 'GetFunctionConfiguration', 'GetCallerIdentity', 'GetRole', 'CreateRole', 'PutRolePolicy',
            'GetFunctionConfiguration', 'CreateFunction', 'GetFunctionConfiguration'], $mocks->calls);
        self::assertCount(0, $mocks->handler);
        self::assertSame([['deploy.step_tenant_mediator', ['function' => 'shop-production-tenants', 'role' => 'arco-shop-production-tenants']]], $steps);

        $role = $mocks->params[4];
        self::assertSame('arco-shop-production-tenants', $role['RoleName']);
        self::assertStringContainsString('lambda.amazonaws.com', $role['AssumeRolePolicyDocument']);
        self::assertSame(['arco:app' => 'shop', 'arco:environment' => 'production', 'arco:region' => 'us-east-1'], array_column($role['Tags'], 'Value', 'Key'));
        self::assertArrayNotHasKey('PermissionsBoundary', $role);
        self::assertSame(TenantMediator::policy('shop', 'production', 'us-east-1', '111111111111', 'shop-production-tenants'), json_decode($mocks->params[5]['PolicyDocument'], true));

        $create = $mocks->params[7];
        self::assertSame('shop-production-tenants', $create['FunctionName']);
        self::assertSame('nodejs24.x', $create['Runtime']);
        self::assertSame(['arm64'], $create['Architectures']);
        self::assertSame('tenant-domains.handler', $create['Handler']);
        self::assertSame(30, $create['Timeout']);
        self::assertSame(256, $create['MemorySize']);
        self::assertSame(self::ROLE_ARN, $create['Role']);
        self::assertSame(self::TAGS, $create['Tags']);
        self::assertArrayNotHasKey('VpcConfig', $create);
        self::assertArrayNotHasKey('KMSKeyArn', $create);
        self::assertSame([
            'ARCO_TENANT_DISTRIBUTION_ID' => 'ETENANT',
            'ARCO_TENANT_CONNECTION_GROUP_ID' => 'cg_1',
            'ARCO_TENANT_TARGET' => 'routing.acmeapp.io',
            'ARCO_TENANT_ENDPOINT' => 'd222.cloudfront.net',
            'ARCO_TENANT_RESERVED' => 'arco.acmeapp.io,www.acmeapp.io',
            'ARCO_APP' => 'shop',
            'ARCO_ENVIRONMENT' => 'production',
        ], $create['Environment']['Variables']);
        self::assertSame(['ZipFile'], array_keys($create['Code']));
        self::assertNotContains('PublishVersion', $mocks->calls, 'no version, no alias');
    }

    public function test_the_zip_holds_the_entry_and_lib_tenants_only_and_is_the_same_every_time(): void
    {
        $runtime = $this->runtime();
        $zips = [];
        foreach ([1, 2] as $_) {
            $mocks = new AwsMocks();
            $mocks->handler->append(self::noRole(), self::noFunction(), new Result(['Account' => '111111111111']), self::noRole(), new Result(['Role' => ['Arn' => self::ROLE_ARN]]), new Result([]), self::noFunction(), new Result([]), self::existing());
            $this->deploy($this->mediator($mocks, $runtime));
            $zips[] = $mocks->params[7]['Code']['ZipFile'];
        }

        self::assertSame($zips[0], $zips[1], 'an unchanged runtime is an unchanged zip');
        self::assertSame([
            'lib/tenants/mediator.js' => "module.exports = {};\n",
            'lib/tenants/nested/deep.js' => "// deep\n",
            'lib/tenants/public_suffix_list.dat' => "com\nio\n",
            'tenant-domains.js' => "exports.handler = async () => ({});\n",
        ], self::unzip($zips[0]));
        self::assertSame(['lib/tenants/mediator.js', 'lib/tenants/nested/deep.js', 'lib/tenants/public_suffix_list.dat', 'tenant-domains.js'], array_keys(self::unzip($zips[0])), 'sorted');
    }

    public function test_the_zip_is_byte_for_byte_independent_of_the_time_zone_and_marks_files_readable(): void
    {
        $a = InlineZip::build(['b.js' => 'two', 'a.js' => 'one']);
        $previous = date_default_timezone_get();
        date_default_timezone_set('Pacific/Auckland');
        try {
            $b = InlineZip::build(['a.js' => 'one', 'b.js' => 'two']);
        } finally {
            date_default_timezone_set($previous);
        }
        self::assertSame($a, $b);

        $path = TempPaths::file('tenant-zip');
        file_put_contents($path, $a);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::RDONLY));
        $opsys = 0;
        $attributes = 0;
        self::assertTrue($zip->getExternalAttributesIndex(0, $opsys, $attributes));
        self::assertSame(\ZipArchive::OPSYS_UNIX, $opsys);
        self::assertSame(0100644, $attributes >> 16);
    }

    public function test_a_zip_refuses_names_that_escape(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        InlineZip::build(['../x.js' => 'x']);
    }

    public function test_redeploy_updates_the_code_and_leaves_matching_settings_and_concurrency_alone(): void
    {
        $config = $this->config();
        $current = self::existing(TenantMediator::settings($config, self::ROLE_ARN, self::INFRA));
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::role(), $current, new Result(['Tags' => self::TAGS]), new Result([]),     // preflight: no reserved concurrency
            new Result(['Account' => '111111111111']),
            self::role(), new Result([]),                                                    // GetRole, PutRolePolicy
            $current, $current,                                                              // read, idle before the code update
            new Result([]),                                                                  // UpdateFunctionCode
            $current,
        );

        $this->deploy($this->mediator($mocks), $config);

        self::assertSame(['GetRole', 'GetFunctionConfiguration', 'ListTags', 'GetFunctionConcurrency', 'GetCallerIdentity',
            'GetRole', 'PutRolePolicy', 'GetFunctionConfiguration', 'GetFunctionConfiguration', 'UpdateFunctionCode', 'GetFunctionConfiguration'], $mocks->calls);
        self::assertCount(0, $mocks->handler);
        self::assertSame('shop-production-tenants', $mocks->params[9]['FunctionName']);
        self::assertIsString($mocks->params[9]['ZipFile']);
        self::assertSame([], array_values(array_intersect($mocks->calls, ['UpdateFunctionConfiguration', 'PutFunctionConcurrency', 'DeleteFunctionConcurrency', 'PublishVersion', 'CreateRole', 'CreateFunction'])));
    }

    public function test_redeploy_reconfigures_when_the_reserved_domains_change_and_never_touches_concurrency(): void
    {
        $config = $this->config();
        $settings = TenantMediator::settings($config, self::ROLE_ARN, self::INFRA);
        $settings['Environment']['Variables']['ARCO_TENANT_RESERVED'] = 'old.acmeapp.io';
        $old = self::existing($settings);
        $ready = self::existing(TenantMediator::settings($config, self::ROLE_ARN, self::INFRA));
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::role(), $old, new Result(['Tags' => self::TAGS]), new Result(['ReservedConcurrentExecutions' => 5]),
            new Result(['Account' => '111111111111']),
            self::role(), new Result([]),
            $old, $old, new Result([]), $old,                                                // code update
            new Result([]),                                                                  // UpdateFunctionConfiguration
            $ready,
        );

        $this->deploy($this->mediator($mocks), $config);

        $update = $mocks->params[11];
        self::assertSame('UpdateFunctionConfiguration', $mocks->calls[11]);
        self::assertSame('arco.acmeapp.io,www.acmeapp.io', $update['Environment']['Variables']['ARCO_TENANT_RESERVED']);
        self::assertNotContains('PutFunctionConcurrency', $mocks->calls);
        self::assertNotContains('DeleteFunctionConcurrency', $mocks->calls);
    }

    public function test_the_permissions_boundary_is_set_on_the_role_as_for_the_apps_own(): void
    {
        $boundary = 'arn:aws:iam::111111111111:policy/arco-boundary';
        $mocks = new AwsMocks();
        $mocks->handler->append(
            self::noRole(), self::noFunction(), new Result(['Account' => '111111111111']),
            self::noRole(), new Result(['Role' => ['Arn' => self::ROLE_ARN]]), new Result([]),
            self::noFunction(), new Result([]), self::existing(),
        );

        $this->deploy($this->mediator($mocks), $this->config($boundary));

        self::assertSame($boundary, $mocks->params[4]['PermissionsBoundary']);
    }

    public function test_the_policy_is_exactly_the_mediators(): void
    {
        $tenants = 'arn:aws:cloudfront::111111111111:distribution-tenant/*';
        $request = ['StringEquals' => ['aws:RequestTag/arco:app' => 'shop', 'aws:RequestTag/arco:environment' => 'production']];
        $resource = ['StringEquals' => ['aws:ResourceTag/arco:app' => 'shop', 'aws:ResourceTag/arco:environment' => 'production']];

        self::assertSame([
            'Version' => '2012-10-17',
            'Statement' => [
                ['Sid' => 'ArcoTenantLogs', 'Effect' => 'Allow', 'Action' => ['logs:CreateLogGroup', 'logs:CreateLogStream', 'logs:PutLogEvents'], 'Resource' => 'arn:aws:logs:us-east-1:111111111111:log-group:/aws/lambda/shop-production-tenants:*'],
                ['Sid' => 'ArcoTenantCreate', 'Effect' => 'Allow', 'Action' => 'cloudfront:CreateDistributionTenant', 'Resource' => '*', 'Condition' => $request],
                ['Sid' => 'ArcoTenantTagOnCreate', 'Effect' => 'Allow', 'Action' => 'cloudfront:TagResource', 'Resource' => $tenants, 'Condition' => $request],
                ['Sid' => 'ArcoTenantWrite', 'Effect' => 'Allow', 'Action' => ['cloudfront:UpdateDistributionTenant', 'cloudfront:DeleteDistributionTenant'], 'Resource' => $tenants, 'Condition' => $resource],
                ['Sid' => 'ArcoTenantRead', 'Effect' => 'Allow', 'Action' => ['cloudfront:GetDistributionTenant', 'cloudfront:GetDistributionTenantByDomain', 'cloudfront:GetManagedCertificateDetails'], 'Resource' => $tenants],
                ['Sid' => 'ArcoTenantList', 'Effect' => 'Allow', 'Action' => 'cloudfront:ListDistributionTenants', 'Resource' => '*'],
            ],
        ], TenantMediator::policy('shop', 'production', 'us-east-1', '111111111111', 'shop-production-tenants'));
    }

    public function test_names(): void
    {
        $config = $this->config();
        self::assertSame('shop-production-tenants', $config->tenantMediatorFunctionName());
        self::assertSame('arco-shop-production-tenants', TenantMediator::roleName($config));
        self::assertSame('arn:aws:lambda:us-east-1:111111111111:function:shop-production-tenants', TenantMediator::arn($config, 'us-east-1', '111111111111'));

        $long = $this->config(app: str_repeat('a', 45));
        $name = TenantMediator::roleName($long);
        self::assertSame(64, strlen($name));
        self::assertStringEndsWith('-tenants', $name);
        self::assertNotSame($name, TenantMediator::roleName($this->config(app: str_repeat('a', 44) . 'b')), 'two long names do not share a role');
        self::assertSame($name, TenantMediator::roleName($long), 'stable');
    }

    /** @return array<string, array{list<mixed>, string}> */
    public static function refusals(): array
    {
        $function = self::existing();
        $foreign = ['arco:app' => 'shop', 'arco:environment' => 'staging'];
        return [
            'role of another environment' => [[self::role($foreign)], 'deploy.tenant_mediator_not_owned'],
            'untagged role' => [[self::role([])], 'deploy.tenant_mediator_untagged'],
            'function of another environment' => [[self::noRole(), $function, new Result(['Tags' => $foreign])], 'deploy.tenant_mediator_not_owned'],
            'untagged function' => [[self::noRole(), $function, new Result(['Tags' => []])], 'deploy.tenant_mediator_untagged'],
            'function with only the app tag' => [[self::noRole(), $function, new Result(['Tags' => ['arco:app' => 'shop']])], 'deploy.tenant_mediator_not_owned'],
            'unreadable function tags' => [[self::noRole(), $function, AwsMocks::error(LambdaException::class, 'ListTags', 'AccessDeniedException', 403, 'no')], 'deploy.tenant_mediator_tags_unreadable'],
            'drained by arco remove' => [[self::role(), $function, new Result(['Tags' => self::TAGS]), new Result(['ReservedConcurrentExecutions' => 0])], 'deploy.tenant_mediator_draining'],
        ];
    }

    /** @param list<mixed> $answers */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function test_a_resource_that_is_not_this_environments_or_is_draining_stops_the_deploy_before_any_change(array $answers, string $key): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(...$answers);

        try {
            $this->deploy($this->mediator($mocks));
            self::fail("expected $key");
        } catch (DeployException $e) {
            self::assertSame($key, $e->key);
        }
        self::assertCount(0, $mocks->handler);
        $writes = array_filter($mocks->calls, fn (string $call) => ! str_starts_with($call, 'Get') && $call !== 'ListTags');
        self::assertSame([], array_values($writes), 'nothing was written');
    }

    public function test_the_draining_refusal_names_the_function(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(self::noRole(), self::existing(), new Result(['Tags' => self::TAGS]), new Result(['ReservedConcurrentExecutions' => 0]));

        try {
            $this->mediator($mocks)->preflight($this->config());
            self::fail('draining');
        } catch (DeployException $e) {
            self::assertSame(['function' => 'shop-production-tenants', 'environment' => 'production'], $e->params);
        }
    }

    public function test_a_refused_concurrency_read_stops_the_deploy(): void
    {
        $mocks = new AwsMocks();
        $mocks->handler->append(self::noRole(), self::existing(), new Result(['Tags' => self::TAGS]), AwsMocks::error(LambdaException::class, 'GetFunctionConcurrency', 'AccessDeniedException', 403, 'no'));

        $this->expectException(LambdaException::class);
        $this->deploy($this->mediator($mocks));
    }

    public function test_missing_code_stops_the_deploy_before_anything_is_read_or_written(): void
    {
        foreach ([[false, true, 'deploy.tenant_mediator_runtime_missing'], [true, false, 'deploy.tenant_mediator_suffix_list_missing']] as [$entry, $suffixes, $key]) {
            $mocks = new AwsMocks();
            try {
                $this->deploy($this->mediator($mocks, $this->runtime($entry, $suffixes)));
                self::fail("expected $key");
            } catch (DeployException $e) {
                self::assertSame($key, $e->key);
                self::assertStringContainsString($suffixes ? 'tenant-domains.js' : 'public_suffix_list.dat', (string) $e->params['path']);
            }
            self::assertSame([], $mocks->calls, 'not even a read');
        }
    }

    public function test_a_missing_runtime_directory_is_refused_too(): void
    {
        $mocks = new AwsMocks();
        $this->expectException(DeployException::class);
        $this->mediator($mocks, TempPaths::path('tenant-absent-'))->preflight($this->config());
    }
}
