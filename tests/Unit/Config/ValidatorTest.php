<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Config;

use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Config\Environment;
use Multiapi\Arco\Config\Runtime;
use Multiapi\Arco\Config\Validator;
use Multiapi\Arco\Tests\Unit\Binary\ElfInspectorTest;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    /** @return list<string> */
    private function errorKeys(App $app, string $env = 'production'): array
    {
        $result = (new Validator())->validate(ConfigResolver::resolve($app->toArray(), $env), sys_get_temp_dir());
        return array_map(fn (array $e) => $e['key'], $result->errors);
    }

    public function test_valid_config(): void
    {
        $result = (new Validator())->validate(
            ConfigResolver::resolve(App::make('shop')->region('us-east-1')->environment('production')->toArray(), 'production'),
            sys_get_temp_dir(),
        );
        self::assertTrue($result->isValid());
    }

    public function test_names_region_and_ranges(): void
    {
        $app = App::make('Shop_App')
            ->region('sa-east-1')
            ->concurrency(129)
            ->timeout(2)
            ->auth('everyone')
            ->logLevel('loud')
            ->runtime(fn (Runtime $r) => $r->workers(min: 10, max: 5)->mode('magic')->threads(0))
            ->environment('production', fn (Environment $e) => $e->account('123'));

        self::assertSame([
            'validation.app_name',
            'validation.region',
            'validation.concurrency',
            'validation.timeout',
            'validation.auth',
            'validation.log_level',
            'validation.account',
            'validation.workers',
            'validation.threads',
            'validation.mode',
        ], $this->errorKeys($app));
    }

    public function test_missing_region(): void
    {
        self::assertSame(['validation.region_missing'], $this->errorKeys(App::make('shop')->environment('production')));
    }

    public function test_function_name_length(): void
    {
        $app = App::make(str_repeat('a', 50))->region('us-east-1')->environment(str_repeat('b', 20));
        self::assertSame(['validation.function_name_length'], $this->errorKeys($app, str_repeat('b', 20)));
    }

    public function test_variables(): void
    {
        $app = App::make('shop')->region('us-east-1')->environment('production', fn (Environment $e) => $e->variables([
            'AWS_LAMBDA_FOO' => 'x',
            '1BAD' => 'x',
            'DB_PASSWORD' => 'arco-secret:#broken',
        ]));
        self::assertSame(['validation.reserved_variable', 'validation.variable_name', 'validation.invalid_reference'], $this->errorKeys($app));
    }

    public function test_lambda_reserved_variables_are_rejected(): void
    {
        foreach (['AWS_SECRET_ACCESS_KEY', 'AWS_XRAY_X', 'AWS_REGION', '_HANDLER', 'LAMBDA_TASK_ROOT', 'AWS_LAMBDA_FUNCTION_NAME'] as $name) {
            $app = App::make('shop')->region('us-east-1')->environment('production', fn (Environment $e) => $e->variables([$name => 'x']));
            self::assertSame(['validation.reserved_variable'], $this->errorKeys($app), $name);
        }
        $app = App::make('shop')->region('us-east-1')->environment('production', fn (Environment $e) => $e->variables(['AWS_BUCKET' => 'assets']));
        self::assertSame([], $this->errorKeys($app));
    }

    public function test_plain_text_secret_is_a_warning(): void
    {
        $app = App::make('shop')->region('us-east-1')->environment('production', fn (Environment $e) => $e->variables([
            'APP_KEY' => 'base64:plaintext',
            'API_TOKEN' => 'arco-ssm:/shop/token',
            'APP_NAME' => 'Shop',
        ]));
        $result = (new Validator())->validate(ConfigResolver::resolve($app->toArray(), 'production'), sys_get_temp_dir());

        self::assertTrue($result->isValid());
        self::assertSame([['key' => 'validation.plaintext_secret', 'params' => ['variable' => 'APP_KEY']]], $result->warnings);
    }

    public function test_custom_binary(): void
    {
        $x86 = ElfInspectorTest::fakeElf(0x3E);
        $app = App::make('shop')->region('us-east-1')
            ->runtime(fn (Runtime $r) => $r->binary($x86))
            ->environment('production');
        self::assertSame(['validation.binary_arch'], $this->errorKeys($app));

        $missing = App::make('shop')->region('us-east-1')
            ->runtime(fn (Runtime $r) => $r->binary('bin/nope'))
            ->environment('production');
        self::assertSame(['validation.binary_missing'], $this->errorKeys($missing));
    }

    public function test_binary_alongside_build_or_frankenphp_warns(): void
    {
        $arm = ElfInspectorTest::fakeElf(0xB7);
        $warningKeys = fn (App $app): array => array_map(
            fn (array $w) => $w['key'],
            (new Validator())->validate(ConfigResolver::resolve($app->toArray(), 'production'), sys_get_temp_dir())->warnings,
        );

        $both = App::make('shop')->region('us-east-1')
            ->runtime(fn (Runtime $r) => $r->binary($arm)->build('1.13.0-r1'))
            ->environment('production');
        self::assertSame(['validation.binary_overrides_build'], $warningKeys($both));
        self::assertSame([], $this->errorKeys($both));

        $version = App::make('shop')->region('us-east-1')
            ->runtime(fn (Runtime $r) => $r->binary($arm)->frankenphp('1.13.0'))
            ->environment('production');
        self::assertSame(['validation.binary_overrides_build'], $warningKeys($version));

        $only = App::make('shop')->region('us-east-1')
            ->runtime(fn (Runtime $r) => $r->binary($arm))
            ->environment('production');
        self::assertSame([], $warningKeys($only));
    }

    private static function manifest(): \Multiapi\Arco\Binary\RuntimeManifest
    {
        return \Multiapi\Arco\Binary\RuntimeManifest::fromArray([
            'default' => '1.13.0-r1',
            'builds' => ['1.13.0-r1' => [
                'url' => 'https://example.test/f', 'sha256' => str_repeat('a', 64), 'size' => 1,
                'frankenphp' => '1.13.0', 'php' => '8.5.11',
            ]],
        ]);
    }

    public function test_runtime_build_selection(): void
    {
        $keys = function (App $app): array {
            $result = (new Validator(self::manifest()))->validate(ConfigResolver::resolve($app->toArray(), 'production'), sys_get_temp_dir());
            return array_map(fn (array $e) => $e['key'], $result->errors);
        };
        $base = fn () => App::make('shop')->region('us-east-1');

        self::assertSame([], $keys($base()->environment('production')));
        self::assertSame([], $keys($base()->runtime(fn (Runtime $r) => $r->frankenphp('1.13.0'))->environment('production')));
        self::assertSame(['binary.unknown_build'], $keys($base()->runtime(fn (Runtime $r) => $r->build('1.13.0-r9'))->environment('production')));
        // Review focus 4: unknown version fails at validation.
        self::assertSame(['binary.unknown_version'], $keys($base()->runtime(fn (Runtime $r) => $r->frankenphp('1.12.0'))->environment('production')));
        // Review focus 3: mixed levels are still rejected.
        self::assertSame(['binary.build_and_version'], $keys($base()
            ->runtime(fn (Runtime $r) => $r->build('1.13.0-r1'))
            ->environment('production', fn (Environment $e) => $e->runtime(fn (Runtime $r) => $r->frankenphp('1.13.0')))));
    }

    public function test_permissions_statements_are_validated(): void
    {
        $valid = ['Effect' => 'Allow', 'Action' => ['s3:GetObject'], 'Resource' => 'arn:aws:s3:::files/*'];
        $app = fn (array $statements): App => App::make('shop')->region('us-east-1')
            ->permissions($statements)
            ->environment('production');

        self::assertSame([], $this->errorKeys($app([$valid, ['Sid' => 'Ses', 'Effect' => 'Deny', 'NotAction' => 'ses:*', 'Resource' => '*']])));
        foreach ([
            'Effect' => ['Effect' => 'allow', 'Action' => 's3:*', 'Resource' => '*'],
            'Action' => ['Effect' => 'Allow', 'Action' => [], 'Resource' => '*'],
            'Resource' => ['Effect' => 'Allow', 'Action' => 's3:*'],
            'Sid' => ['Sid' => 'ArcoLogs', 'Effect' => 'Allow', 'Action' => 's3:*', 'Resource' => '*'],
        ] as $field => $statement) {
            $result = (new Validator())->validate(ConfigResolver::resolve($app([$valid, $statement])->toArray(), 'production'), sys_get_temp_dir());
            self::assertSame([['key' => 'validation.permission', 'params' => ['index' => 1, 'field' => $field]]], $result->errors, $field);
        }
    }

    public function test_permissions_merge_app_and_environment_and_warn_with_custom_role(): void
    {
        $a = ['Effect' => 'Allow', 'Action' => 's3:GetObject', 'Resource' => '*'];
        $b = ['Effect' => 'Allow', 'Action' => 'ses:SendRawEmail', 'Resource' => '*'];
        $app = App::make('shop')->region('us-east-1')->permissions([$a])
            ->environment('production', fn (Environment $e) => $e->permissions([$b]))
            ->environment('staging', fn (Environment $e) => $e->role('arn:aws:iam::111111111111:role/mine'));

        self::assertSame([$a, $b], ConfigResolver::resolve($app->toArray(), 'production')->permissions);

        $warnings = (new Validator())->validate(ConfigResolver::resolve($app->toArray(), 'staging'), sys_get_temp_dir())->warnings;
        self::assertSame(['validation.permissions_with_role'], array_map(fn (array $w) => $w['key'], $warnings));
    }

    public function test_domains_are_validated(): void
    {
        $app = fn (array|string $domains): App => App::make('shop')->region('us-east-1')->environment('production', fn (Environment $e) => $e->domain($domains));

        self::assertSame([], $this->errorKeys($app(['arco.acmeapp.io', 'api-arco.acmeapp.io', 'a.b.c.example.co'])));
        self::assertSame(['validation.domain'], $this->errorKeys($app('Arco.acmeapp.io')));
        self::assertSame(['validation.domain'], $this->errorKeys($app('localhost')));
        self::assertSame(['validation.domain'], $this->errorKeys($app('-a.example.com')));
        self::assertSame(['validation.domain'], $this->errorKeys($app('https://a.example.com')));
        self::assertSame(['validation.domain_wildcard'], $this->errorKeys($app('*.example.com')));
        self::assertSame(['validation.domain_duplicate'], $this->errorKeys($app(['a.example.com', 'b.example.com', 'a.example.com'])));
        self::assertSame(['validation.domain_count'], $this->errorKeys($app(array_map(fn (int $i) => "d$i.example.com", range(1, 11)))));
    }

    public function test_is_hostname_uses_the_domain_rules(): void
    {
        self::assertTrue(Validator::isHostname('api-arco.acmeapp.io'));
        foreach (['Arco.acmeapp.io', 'localhost', '-a.example.com', 'https://a.example.com', '*.example.com', ''] as $bad) {
            self::assertFalse(Validator::isHostname($bad), $bad);
        }
    }

    public function test_iam_auth_cannot_use_domains(): void
    {
        $app = App::make('shop')->region('us-east-1')->auth('iam')->environment('production', fn (Environment $e) => $e->domain('a.example.com'));
        self::assertSame(['validation.domain_iam'], $this->errorKeys($app));
    }

    public function test_certificate_must_be_an_acm_arn_in_us_east_1(): void
    {
        $app = fn (string $arn): App => App::make('shop')->region('us-east-2')
            ->environment('production', fn (Environment $e) => $e->domain('a.example.com')->certificate($arn));

        self::assertSame([], $this->errorKeys($app('arn:aws:acm:us-east-1:111111111111:certificate/0a1b2c3d-1111-2222-3333-444455556666')));
        self::assertSame(['validation.certificate'], $this->errorKeys($app('arn:aws:acm:us-east-2:111111111111:certificate/0a1b2c3d')));
        self::assertSame(['validation.certificate'], $this->errorKeys($app('0a1b2c3d-1111')));
    }

    public function test_dns_or_certificate_without_domains_warns(): void
    {
        $app = App::make('shop')->region('us-east-1')->dns('common')->environment('production');
        $result = (new Validator())->validate(ConfigResolver::resolve($app->toArray(), 'production'), sys_get_temp_dir());

        self::assertTrue($result->isValid());
        self::assertSame(['validation.domain_settings_unused'], array_map(fn (array $w) => $w['key'], $result->warnings));
    }

    public function test_empty_variable_is_a_warning(): void
    {
        $result = (new Validator())->validate(
            ConfigResolver::resolve(App::make('shop')->region('us-east-1')->variables(['ROLE_ARN' => '', 'MAIL_PASSWORD' => ''])->environment('production')->toArray(), 'production'),
            sys_get_temp_dir(),
        );

        self::assertTrue($result->isValid());
        self::assertSame(['validation.empty_variable', 'validation.empty_variable'], array_map(fn (array $w) => $w['key'], $result->warnings));
    }

    public function test_max_instances_and_rate_limit_must_be_positive(): void
    {
        $app = App::make('shop')->region('us-east-1')->maxInstances(0)->rateLimit(0)->environment('production');

        self::assertSame(['validation.max_instances', 'validation.rate_limit'], $this->errorKeys($app));
    }

    public function test_checks_one_setting_value_with_the_same_rules(): void
    {
        self::assertNull(Validator::settingError('concurrency', 16));
        self::assertSame(['key' => 'validation.concurrency', 'params' => ['value' => 129]], Validator::settingError('concurrency', 129));
        self::assertSame(['key' => 'validation.concurrency', 'params' => ['value' => 'many']], Validator::settingError('concurrency', 'many'));
        self::assertNull(Validator::settingError('timeout', 3));
        self::assertSame('validation.timeout', Validator::settingError('timeout', 901)['key'] ?? null);
        self::assertNull(Validator::settingError('maxInstances', 2));
        self::assertNull(Validator::settingError('maxInstances', 10000));
        foreach ([0, 1, 10001] as $bad) {
            self::assertSame('validation.max_instances', Validator::settingError('maxInstances', $bad)['key'] ?? null, (string) $bad);
        }
        // AWS: multiples of 100 up to 1,000, then multiples of 1,000 up to 10,000; 0 (block all) is refused.
        foreach ([100, 500, 1000, 2000, 10000] as $good) {
            self::assertNull(Validator::settingError('rateLimit', $good), (string) $good);
        }
        foreach ([-5, 0, 50, 150, 1500, 11000] as $bad) {
            self::assertSame('validation.rate_limit', Validator::settingError('rateLimit', $bad)['key'] ?? null, (string) $bad);
        }
        self::assertNull(Validator::settingError('logLevel', 'debug'));
        self::assertSame('validation.log_level', Validator::settingError('logLevel', 'loud')['key'] ?? null);
        self::assertSame('validation.log_level', Validator::settingError('logLevel', 3)['key'] ?? null);
    }
}
