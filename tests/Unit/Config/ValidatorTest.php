<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Config;

use Multiapi\Arco\Build\PlainPhpPreset;
use Multiapi\Arco\Build\PresetRegistry;
use Multiapi\Arco\Build\RunsCommands;
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

    /** @return iterable<string, array{string, string, list<array{key: string, params: array<string, string>}>}> */
    public static function reservedNames(): iterable
    {
        foreach (['-console', '-resources', '-scheduler', '-host'] as $suffix) {
            yield "environment ending in $suffix" => ['shop', "prod$suffix", [['key' => 'validation.environment_name_suffix', 'params' => ['name' => "prod$suffix", 'suffix' => $suffix]]]];
            yield "app ending in $suffix" => ["shop$suffix", 'prod', [['key' => 'validation.app_name_suffix', 'params' => ['name' => "shop$suffix", 'suffix' => $suffix]]]];
        }
        yield 'environment containing -queue-' => ['shop', 'prod-queue-emails', [['key' => 'validation.environment_name_queue', 'params' => ['name' => 'prod-queue-emails']]]];
        yield 'app containing -queue-' => ['shop-queue-x', 'prod', [['key' => 'validation.app_name_queue', 'params' => ['name' => 'shop-queue-x']]]];
        // Neither name contains -queue-, but <app>-<env> is the worker of shop/prod's queue "emails".
        yield 'app and environment joined into a worker name' => ['shop-prod', 'queue-emails', [['key' => 'validation.function_name_queue', 'params' => ['app' => 'shop-prod', 'environment' => 'queue-emails', 'function' => 'shop-prod-queue-emails']]]];
        // Neither name ends in -console, but shop-eu-console is the console function of shop/eu.
        yield 'app and environment joined into a console name' => ['shop-eu', 'console', [['key' => 'validation.function_name_suffix', 'params' => ['app' => 'shop-eu', 'environment' => 'console', 'function' => 'shop-eu-console', 'suffix' => '-console']]]];
        yield 'app and environment joined into a resources stack name' => ['shop-eu', 'resources', [['key' => 'validation.function_name_suffix', 'params' => ['app' => 'shop-eu', 'environment' => 'resources', 'function' => 'shop-eu-resources', 'suffix' => '-resources']]]];
        yield 'hyphens elsewhere are fine' => ['shop', 'eu-prod', []];
        yield 'a suffix inside the name is fine' => ['shop', 'console-eu', []];
        yield 'an environment named like a suffix without the hyphen is fine' => ['shop', 'console', []];
    }

    /** @param list<array{key: string, params: array<string, string>}> $errors */
    #[\PHPUnit\Framework\Attributes\DataProvider('reservedNames')]
    public function test_names_that_collide_with_arcos_own_resource_names_are_rejected(string $app, string $environment, array $errors): void
    {
        $result = (new Validator())->validate(ConfigResolver::resolve(App::make($app)->region('us-east-1')->environment($environment)->toArray(), $environment), sys_get_temp_dir());
        self::assertSame($errors, $result->errors);
    }

    public function test_names_region_and_ranges(): void
    {
        $app = App::make('Shop_App')
            ->region('sa-east-1')
            ->concurrency(129)
            ->timeout(2)
            ->auth('everyone')
            ->logLevel('loud')
            ->runtime(fn (Runtime $r) => $r->workers(10)->maxThreads(5)->mode('magic')->threads(0))
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

    public function test_php_ini_keys_and_values_are_checked(): void
    {
        $app = App::make('shop')->region('us-east-1')->environment('production')
            ->runtime(fn (Runtime $r) => $r->phpIni([
                'memory_limit' => '256M',
                'opcache.jit_buffer-size' => '1',
                "bad key\nx" => '1',
                'error_log' => "a\"\nmemory_limit = 1M",
                'cr' => "a\rb",
                'nul' => "a\0b",
            ]));

        $errors = array_values(array_filter($this->errorKeys($app), fn (string $k): bool => str_starts_with($k, 'validation.php_ini')));
        self::assertSame(['validation.php_ini_key', 'validation.php_ini_value', 'validation.php_ini_value', 'validation.php_ini_value'], $errors);
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

    public function test_queue_concurrency_is_deprecated_in_favour_of_max_instances(): void
    {
        $validate = fn (App $app) => (new Validator())->validate(ConfigResolver::resolve($app->region('us-east-1')->environment('production')->toArray(), 'production'), sys_get_temp_dir());

        $new = $validate(App::make('shop')->queue('a', maxInstances: 5));
        self::assertSame([], $new->warnings);

        $old = $validate(App::make('shop')->queue('a', concurrency: 5)->queue('b', concurrency: 3));
        self::assertSame([['key' => 'deprecated.queue_concurrency', 'params' => ['queues' => 'a, b']]], $old->warnings, 'one warning for the run');
        self::assertSame([], $old->errors);

        $both = $validate(App::make('shop')->queue('a', maxInstances: 5, concurrency: 5));
        self::assertSame('validation.queue_both_concurrency', $both->errors[0]['key']);
    }

    public function test_queue_spellings_override_across_levels_and_warn_once(): void
    {
        $validate = fn (App $app, string $env) => (new Validator())->validate(ConfigResolver::resolve($app->region('us-east-1')->toArray(), $env), sys_get_temp_dir());
        $oldApp = fn () => App::make('shop')->queue('a', concurrency: 5)
            ->environment('staging')
            ->environment('production', fn (Environment $e) => $e->queue('a', maxInstances: 10));
        $result = $validate($oldApp(), 'production');
        self::assertSame([], $result->errors);
        self::assertCount(1, $result->warnings);
        self::assertSame('deprecated.queue_concurrency', $result->warnings[0]['key']);
        self::assertSame(10, ConfigResolver::resolve($oldApp()->region('us-east-1')->toArray(), 'production')->queues[0]->maxInstances);
        self::assertCount(1, $validate($oldApp(), 'staging')->warnings);

        $oldEnv = fn () => App::make('shop')->queue('a', maxInstances: 10)
            ->environment('production', fn (Environment $e) => $e->queue('a', concurrency: 3));
        $result = $validate($oldEnv(), 'production');
        self::assertSame([], $result->errors);
        self::assertCount(1, $result->warnings);
        self::assertSame(3, ConfigResolver::resolve($oldEnv()->region('us-east-1')->toArray(), 'production')->queues[0]->maxInstances);

        $same = App::make('shop')->queue('a', maxInstances: 10, concurrency: 3)->environment('production');
        self::assertSame('validation.queue_both_concurrency', $validate($same, 'production')->errors[0]['key']);
    }

    public function test_workers_min_max_is_deprecated_in_favour_of_workers_and_max_threads(): void
    {
        $validate = fn (callable $runtime) => (new Validator())->validate(ConfigResolver::resolve(App::make('shop')->region('us-east-1')->runtime($runtime)->environment('production')->toArray(), 'production'), sys_get_temp_dir());

        self::assertSame([], $validate(fn (Runtime $r) => $r->workers(4)->maxThreads(64))->warnings);
        $old = $validate(fn (Runtime $r) => $r->workers(min: 4, max: 64));
        self::assertSame([['key' => 'deprecated.workers_min_max', 'params' => []]], $old->warnings);
        self::assertSame([['key' => 'deprecated.workers_min_max', 'params' => []]], $validate(fn (Runtime $r) => $r->workers(4, 64))->warnings, 'the old positional form');
        self::assertSame([], $old->errors);
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

        $php = App::make('shop')->region('us-east-1')
            ->runtime(fn (Runtime $r) => $r->binary($arm)->php('8.5'))
            ->environment('production');
        self::assertSame(['validation.binary_overrides_build'], $warningKeys($php));

        // An environment's binary() over selectors inherited from the app (arco init's php()) does not warn.
        $inherited = App::make('shop')->region('us-east-1')
            ->runtime(fn (Runtime $r) => $r->php('8.5'))
            ->environment('production', fn (Environment $e) => $e->runtime(fn (Runtime $r) => $r->binary($arm)));
        self::assertSame([], $warningKeys($inherited));
        self::assertSame([], $this->errorKeys($inherited));
    }

    private static function manifest(): \Multiapi\Arco\Binary\RuntimeManifest
    {
        return \Multiapi\Arco\Binary\RuntimeManifest::fromArray([
            'default_php' => '8.5',
            'builds' => [
                'php8.5.11-fp1.13.0-r1' => [
                    'url' => 'https://example.test/f', 'sha256' => str_repeat('a', 64), 'size' => 1,
                    'frankenphp' => '1.13.0', 'php' => '8.5.11',
                ],
                'php8.6.1-fp1.14.0-r1' => [
                    'url' => 'https://example.test/g', 'sha256' => str_repeat('b', 64), 'size' => 1,
                    'frankenphp' => '1.14.0', 'php' => '8.6.1',
                ],
            ],
            'aliases' => ['1.13.0-r1' => 'php8.5.11-fp1.13.0-r1'],
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
        // Selectors resolve per level: an environment's frankenphp() replaces an inherited build().
        self::assertSame([], $keys($base()
            ->runtime(fn (Runtime $r) => $r->build('1.13.0-r1'))
            ->environment('production', fn (Environment $e) => $e->runtime(fn (Runtime $r) => $r->frankenphp('1.13.0')))));
        // At the same level they are still rejected.
        self::assertSame(['binary.build_and_version'], $keys($base()
            ->environment('production', fn (Environment $e) => $e->runtime(fn (Runtime $r) => $r->build('1.13.0-r1')->frankenphp('1.13.0')))));
    }

    public function test_php_selects_a_minor_and_excludes_build(): void
    {
        $result = function (App $app): \Multiapi\Arco\Config\ValidationResult {
            return (new Validator(self::manifest()))->validate(ConfigResolver::resolve($app->toArray(), 'production'), sys_get_temp_dir());
        };
        $keys = fn (App $app): array => array_map(fn (array $e) => $e['key'], $result($app)->errors);
        $base = fn () => App::make('shop')->region('us-east-1');

        self::assertSame([], $keys($base()->runtime(fn (Runtime $r) => $r->php('8.5'))->environment('production')));
        self::assertSame([], $keys($base()->runtime(fn (Runtime $r) => $r->php('8.6')->frankenphp('1.14'))->environment('production')));
        self::assertSame(['binary.unknown_version'], $keys($base()->runtime(fn (Runtime $r) => $r->php('8.6')->frankenphp('1.13'))->environment('production')));
        self::assertSame(['binary.invalid_php'], $keys($base()->runtime(fn (Runtime $r) => $r->php('8'))->environment('production')));

        // No build of that minor: the error lists the PHP minors this Arco knows.
        $missing = $result($base()->runtime(fn (Runtime $r) => $r->php('8.4'))->environment('production'))->errors;
        self::assertSame([['key' => 'binary.unknown_php', 'params' => ['php' => '8.4', 'minors' => '8.5, 8.6']]], $missing);

        // build() is an exact pin: php() next to it at the same level is an error.
        self::assertSame(['binary.build_and_version'], $keys($base()->runtime(fn (Runtime $r) => $r->php('8.5')->build('1.13.0-r1'))->environment('production')));
        self::assertSame(['binary.build_and_version'], $keys($base()
            ->environment('production', fn (Environment $e) => $e->runtime(fn (Runtime $r) => $r->php('8.5')->build('1.13.0-r1')))));
        // Across levels the environment's selector wins (arco init writes php() at app level).
        self::assertSame([], $keys($base()
            ->runtime(fn (Runtime $r) => $r->php('8.5'))
            ->environment('production', fn (Environment $e) => $e->runtime(fn (Runtime $r) => $r->build('php8.5.11-fp1.13.0-r1')))));
        self::assertSame([], $keys($base()
            ->runtime(fn (Runtime $r) => $r->build('1.13.0-r1'))
            ->environment('production', fn (Environment $e) => $e->runtime(fn (Runtime $r) => $r->php('8.5')))));
    }

    public function test_binary_with_a_php_cli_checks_both_files(): void
    {
        $arm = ElfInspectorTest::fakeElf(0xB7);
        $x86 = ElfInspectorTest::fakeElf(0x3E);
        $keys = fn (string $php): array => $this->errorKeys(App::make('shop')->region('us-east-1')
            ->runtime(fn (Runtime $r) => $r->binary(frankenphp: $arm, php: $php))
            ->environment('production'));

        self::assertSame([], $keys($arm));
        self::assertSame(['validation.php_binary_missing'], $keys('bin/no-php'));
        self::assertSame(['validation.php_binary_arch'], $keys($x86));
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

    public function test_iam_auth_with_domains_is_valid(): void
    {
        // domain() makes the endpoint IamAuth anyway: CloudFront signs through its origin access control.
        $app = App::make('shop')->region('us-east-1')->auth('iam')->environment('production', fn (Environment $e) => $e->domain('a.example.com'));
        self::assertSame([], $this->errorKeys($app));
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

    public function test_console_timeout_range(): void
    {
        self::assertSame(['validation.console_timeout'], $this->errorKeys(App::make('shop')->region('us-east-1')->console(timeout: 901)->environment('production')));
        self::assertSame(['validation.console_timeout'], $this->errorKeys(App::make('shop')->region('us-east-1')->console(timeout: 2)->environment('production')));
        self::assertSame([], $this->errorKeys(App::make('shop')->region('us-east-1')->console(timeout: 3)->environment('production')));
    }

    public function test_console_memory_range(): void
    {
        self::assertSame(['validation.console_memory'], $this->errorKeys(App::make('shop')->region('us-east-1')->console(memory: 127)->environment('production')));
        self::assertSame(['validation.console_memory'], $this->errorKeys(App::make('shop')->region('us-east-1')->console(memory: 10241)->environment('production')));
        self::assertSame([], $this->errorKeys(App::make('shop')->region('us-east-1')->console(memory: 4096)->environment('production')));
    }

    public function test_cache_driver_must_be_dynamodb(): void
    {
        self::assertSame(['validation.cache_driver'], $this->errorKeys(App::make('shop')->region('us-east-1')->cache(driver: 'redis')->environment('production')));
        self::assertSame([], $this->errorKeys(App::make('shop')->region('us-east-1')->cache(driver: 'dynamodb')->environment('production')));
    }

    public function test_every_arco_variable_is_reserved_for_arco(): void
    {
        foreach (['ARCO', 'ARCO_X', 'ARCO_TRUST_PROXIES', 'ARCO_QUEUES', 'ARCOFOO'] as $name) {
            $app = App::make('shop')->region('us-east-1')->variables([$name => '1'])->environment('production');
            $errors = (new Validator())->validate(ConfigResolver::resolve($app->toArray(), 'production'), sys_get_temp_dir())->errors;
            self::assertSame('validation.reserved_arco_variable', $errors[0]['key'] ?? null, $name);
            self::assertSame($name, $errors[0]['params']['variable'] ?? null);
        }
        $app = App::make('shop')->region('us-east-1')->variables(['MY_ARCO' => '1', 'ARC' => '1'])->environment('production');
        self::assertSame([], $this->errorKeys($app));
    }

    public function test_console_function_name_must_fit_64_characters(): void
    {
        // shop-<env>: 60 characters; shop-<env>-console: 68.
        $env = 'e' . str_repeat('x', 54);

        self::assertSame(['validation.console_function_name_length'], $this->errorKeys(App::make('shop')->region('us-east-1')->environment($env), $env));
    }

    public function test_deploy_commands_must_be_non_empty_and_well_quoted(): void
    {
        $app = App::make('shop')->region('us-east-1')->environment('production', fn (Environment $e) => $e->deployCommands(['migrate --force', ' ', "db:seed --class='X"]));

        self::assertSame(['validation.deploy_command_empty', 'validation.deploy_command'], $this->errorKeys($app));
    }

    /**
     * @param list<string> $commands
     * @return list<array{key: string, params: array<string, string|int>}>
     */
    private function deployCommandWarnings(array $commands, bool $laravel = true): array
    {
        $preset = $laravel
            ? new class extends PlainPhpPreset implements RunsCommands { public function name(): string { return 'laravel'; } public function commandEntry(): string { return 'artisan'; } }
            : new PlainPhpPreset();
        $app = App::make('shop')->region('us-east-1')->environment('production', fn (Environment $e) => $e->deployCommands($commands));
        $result = (new Validator(presets: new PresetRegistry([$preset])))->validate(ConfigResolver::resolve($app->toArray(), 'production'), sys_get_temp_dir());
        self::assertTrue($result->isValid());
        return $result->warnings;
    }

    /** Checked against Laravel's source: these write into bootstrap/cache or public/ and throw. */
    public function test_deploy_commands_that_write_into_the_read_only_code_directory_warn(): void
    {
        $warnings = $this->deployCommandWarnings(['config:cache', 'route:cache', 'event:cache', 'optimize', 'storage:link']);

        self::assertSame(array_fill(0, 5, 'validation.deploy_command_read_only'), array_column($warnings, 'key'));
        self::assertSame(['command' => 'config:cache', 'name' => 'config:cache'], $warnings[0]['params']);
    }

    /** Their deletes in the read-only code directory fail silently: they exit 0 and change nothing. */
    public function test_clear_commands_for_the_code_directory_warn_that_they_do_nothing(): void
    {
        $warnings = $this->deployCommandWarnings(['config:clear', 'route:clear', 'event:clear', 'optimize:clear']);

        self::assertSame(array_fill(0, 4, 'validation.deploy_command_no_effect'), array_column($warnings, 'key'));
        self::assertSame(['command' => 'event:clear', 'name' => 'event:clear'], $warnings[2]['params']);
    }

    /**
     * The runtime runs `php artisan <args>`: an entry written as `artisan migrate` runs
     * `php artisan artisan migrate` and fails. The command after the prefix is still checked.
     */
    public function test_an_artisan_prefix_is_a_warning_and_the_command_after_it_is_still_checked(): void
    {
        $warnings = $this->deployCommandWarnings(['artisan about', 'php artisan route:cache --quiet', 'artisan db:seed']);

        self::assertSame([
            'validation.deploy_command_artisan_prefix',
            'validation.deploy_command_artisan_prefix',
            'validation.deploy_command_read_only',
            'validation.deploy_command_artisan_prefix',
            'validation.deploy_command_needs_force',
        ], array_column($warnings, 'key'));
        self::assertSame(['command' => 'artisan about', 'entry' => 'artisan'], $warnings[0]['params']);
        self::assertSame(['command' => 'php artisan route:cache --quiet', 'name' => 'route:cache'], $warnings[2]['params']);
    }

    public function test_plain_php_deploy_commands_are_scripts_and_get_no_artisan_warnings(): void
    {
        self::assertSame([], $this->deployCommandWarnings(['artisan migrate', 'php artisan config:cache', 'migrate', 'config:cache'], laravel: false));
    }

    public function test_harmless_deploy_commands_do_not_warn(): void
    {
        // view:cache and view:clear work: compiled views live under LARAVEL_STORAGE_PATH (/tmp).
        self::assertSame([], $this->deployCommandWarnings(['view:cache', 'view:clear', 'migrate --force', 'cache:clear', 'queue:restart', 'db:seed --class=Roles --force', 'migrate:status', 'about --only=environment']));
    }

    public function test_deploy_commands_that_ask_for_confirmation_in_production_warn_without_force(): void
    {
        $warnings = $this->deployCommandWarnings(['migrate', 'migrate:rollback --step=1', 'db:seed', 'migrate --force', 'db:seed --force']);

        self::assertSame(array_fill(0, 3, 'validation.deploy_command_needs_force'), array_column($warnings, 'key'));
        self::assertSame(['command' => 'migrate', 'name' => 'migrate'], $warnings[0]['params']);
        self::assertSame(['command' => 'db:seed', 'name' => 'db:seed'], $warnings[2]['params']);
    }

    public function test_deploy_commands_that_destroy_data_warn_even_with_force(): void
    {
        $warnings = $this->deployCommandWarnings(['migrate:fresh --force', 'migrate:refresh', 'db:wipe --force']);

        self::assertSame([
            'validation.deploy_command_destroys_data',
            'validation.deploy_command_destroys_data',
            'validation.deploy_command_needs_force',
            'validation.deploy_command_destroys_data',
        ], array_column($warnings, 'key'));
    }

    public function test_every_deploy_command_warning_is_translated(): void
    {
        foreach (['en', 'es'] as $locale) {
            $messages = require __DIR__ . "/../../../packages/arco/lang/$locale.php";
            foreach (['validation.deploy_command_read_only', 'validation.deploy_command_needs_force', 'validation.deploy_command_destroys_data', 'validation.deploy_command_artisan_prefix', 'validation.deploy_command_no_effect'] as $key) {
                self::assertArrayHasKey($key, $messages, "$locale: $key");
            }
        }
        $en = require __DIR__ . '/../../../packages/arco/lang/en.php';
        self::assertStringContainsString('event:cache', $en['validation.deploy_command_read_only']);
    }

    public function test_scheduler_interval_must_divide_an_hour(): void
    {
        foreach ([7, 0] as $bad) {
            $result = (new Validator())->validate(
                ConfigResolver::resolve(App::make('shop')->region('us-east-1')->scheduler($bad)->environment('production')->toArray(), 'production'),
                sys_get_temp_dir(),
            );
            self::assertSame(
                [['key' => 'validation.scheduler_interval', 'params' => ['value' => $bad]]],
                array_map(fn (array $e) => ['key' => $e['key'], 'params' => $e['params']], $result->errors),
            );
        }
        foreach ([1, 15, 60] as $good) {
            self::assertSame([], $this->errorKeys(App::make('shop')->region('us-east-1')->scheduler($good)->environment('production')), (string) $good);
        }
    }

    public function test_log_retention_values(): void
    {
        self::assertSame([], $this->errorKeys(App::make('shop')->region('us-east-1')->logRetention(30)->environment('production')));
        self::assertSame([], $this->errorKeys(App::make('shop')->region('us-east-1')->logRetention(false)->environment('production')));
        self::assertSame(['validation.log_retention'], $this->errorKeys(App::make('shop')->region('us-east-1')->logRetention(31)->environment('production')));
    }

    public function test_scheduler_needs_console(): void
    {
        $app = App::make('shop')->region('us-east-1')->scheduler(5)
            ->environment('production', fn (Environment $e) => $e->console(false));

        self::assertSame(['validation.scheduler_needs_console'], $this->errorKeys($app));
    }

    /** @return list<string> */
    private function queueErrors(App $app): array
    {
        return $this->errorKeys($app->region('us-east-1')->environment('production'));
    }

    public function test_valid_queues(): void
    {
        $app = App::make('shop')->queue('jobs')->queue('emails', timeout: 900, memory: 128, maxInstances: 2, batchSize: 10000, tries: 1)
            ->queue('f', fifo: true, batchSize: 10)->queue('e', existing: 'arn:aws:sqs:us-east-1:123456789012:legacy');
        self::assertSame([], $this->queueErrors($app));
    }

    public function test_queue_name_and_lengths(): void
    {
        self::assertContains('validation.queue_name', $this->queueErrors(App::make('shop')->queue('Bad_Name')));
        self::assertContains('validation.queue_name_length', $this->queueErrors(App::make('shop')->queue(str_repeat('a', 70))));
        self::assertContains('validation.worker_name_length', $this->queueErrors(App::make('shop-with-a-fairly-long-name')->queue(str_repeat('a', 30))));
    }

    public function test_queue_option_ranges(): void
    {
        $cases = [
            'validation.queue_timeout' => [['timeout' => 0], ['timeout' => 9], ['timeout' => 901]],
            'validation.queue_memory' => [['memory' => 127], ['memory' => 10241]],
            'validation.queue_max_instances' => [['maxInstances' => 1]],
            'validation.queue_batch_size' => [['batchSize' => 0], ['batchSize' => 10001], ['batchSize' => 11, 'fifo' => true]],
            'validation.queue_tries' => [['tries' => 0]],
        ];
        foreach ($cases as $key => $variants) {
            foreach ($variants as $options) {
                self::assertContains($key, $this->queueErrors(App::make('shop')->queue('q', ...$options)), $key . json_encode($options));
            }
        }
    }

    public function test_a_queue_timeout_of_ten_seconds_is_the_minimum(): void
    {
        self::assertNotContains('validation.queue_timeout', $this->queueErrors(App::make('shop')->queue('q', timeout: 10)));
    }

    public function test_two_default_queues(): void
    {
        self::assertContains('validation.queue_default_twice', $this->queueErrors(App::make('shop')->queue('a', default: true)->queue('b', default: true)));
    }

    public function test_a_queue_named_like_another_queues_dlq(): void
    {
        self::assertContains('validation.queue_name_clash', $this->queueErrors(App::make('shop')->queue('emails')->queue('emails-dlq')));
        self::assertContains('validation.queue_name_clash', $this->queueErrors(App::make('shop')->queue('emails-dlq', fifo: true)->queue('emails', fifo: true)));
        self::assertNotContains('validation.queue_name_clash', $this->queueErrors(App::make('shop')->queue('emails', dlq: false)->queue('emails-dlq')));
        self::assertNotContains('validation.queue_name_clash', $this->queueErrors(App::make('shop')->queue('emails')->queue('emails-dlq', fifo: true)));
    }

    public function test_existing_queue_reference(): void
    {
        self::assertContains('validation.queue_existing', $this->queueErrors(App::make('shop')->queue('a', existing: 'not a queue!')));
        self::assertContains('validation.queue_existing_fifo', $this->queueErrors(App::make('shop')->queue('a', existing: 'legacy.fifo')));
        self::assertContains('validation.queue_existing_fifo', $this->queueErrors(App::make('shop')->queue('a', existing: 'legacy', fifo: true)));
        self::assertSame([], $this->queueErrors(App::make('shop')->queue('a', existing: 'legacy.fifo', fifo: true)));
    }

    public function test_valid_storage_and_tables(): void
    {
        $app = App::make('shop')->storage('files', public: ['public/'], expire: ['tmp/' => 1], cors: ['https://example.com', 'http://localhost:3000', '*'])
            ->storage('legacy', existing: 'my.legacy-bucket')->cache(existing: 'Legacy_Cache')->failedJobsTable();
        self::assertSame([], $this->queueErrors($app));
    }

    public function test_storage_name_and_bucket_length(): void
    {
        self::assertContains('validation.storage_name', $this->queueErrors(App::make('shop')->storage('Bad_Name')));
        // shop-production-<name>-<12-digit account> must fit 63 characters, also before the account is known.
        self::assertContains('validation.bucket_name_length', $this->queueErrors(App::make('shop')->storage(str_repeat('a', 35))));
        self::assertNotContains('validation.bucket_name_length', $this->queueErrors(App::make('shop')->storage(str_repeat('a', 34))));
        self::assertNotContains('validation.bucket_name_length', $this->queueErrors(App::make('shop')->storage(str_repeat('a', 35), existing: 'short')));
    }

    public function test_storage_options(): void
    {
        $cases = [
            'validation.storage_expire' => [['expire' => ['tmp/' => 0]], ['expire' => ['tmp' => 1]], ['expire' => ['' => 3]]],
            'validation.storage_public' => [['public' => ['']], ['public' => ['public']]],
            'validation.storage_cors' => [['cors' => ['example.com']], ['cors' => ['ftp://example.com']], ['cors' => ['']]],
            'validation.storage_existing' => [['existing' => 'Bad_Bucket'], ['existing' => 'ab']],
        ];
        foreach ($cases as $key => $variants) {
            foreach ($variants as $options) {
                self::assertContains($key, $this->queueErrors(App::make('shop')->storage('files', ...$options)), $key . ' ' . json_encode($options));
            }
        }
        self::assertContains('validation.storage_default_twice', $this->queueErrors(App::make('shop')->storage('a', default: true)->storage('b', default: true)));
    }

    public function test_laravels_own_disk_names_are_not_storage_names(): void
    {
        foreach (['s3', 'public', 'local'] as $name) {
            self::assertContains('validation.storage_reserved', $this->queueErrors(App::make('shop')->storage($name)), $name);
            self::assertContains('validation.storage_reserved', $this->queueErrors(App::make('shop')->storage($name, existing: 'legacy-bucket')), $name);
        }
        self::assertNotContains('validation.storage_reserved', $this->queueErrors(App::make('shop')->storage('uploads')));
    }

    public function test_wildcards_in_public_and_expire_prefixes_are_errors(): void
    {
        foreach (['*/', 'a*/', 'img?/', 'a/*/'] as $prefix) {
            self::assertContains('validation.storage_public_wildcard', $this->queueErrors(App::make('shop')->storage('files', public: [$prefix])), $prefix);
            self::assertContains('validation.storage_expire_wildcard', $this->queueErrors(App::make('shop')->storage('files', expire: [$prefix => 3])), $prefix);
        }
        self::assertSame([], $this->queueErrors(App::make('shop')->storage('files', public: ['img/a-b.c/'], expire: ['tmp/' => 1])));
    }

    public function test_options_on_an_existing_bucket_are_a_warning_not_an_error(): void
    {
        $warnings = function (App $app): array {
            $result = (new Validator())->validate(ConfigResolver::resolve($app->region('us-east-1')->environment('production')->toArray(), 'production'), sys_get_temp_dir());
            self::assertSame([], array_map(fn (array $e) => $e['key'], $result->errors));
            return $result->warnings;
        };

        self::assertSame(
            [['key' => 'validation.storage_existing_options', 'params' => ['storage' => 'media', 'options' => 'public, expire, cors', 'bucket' => 'legacy-bucket']]],
            $warnings(App::make('shop')->storage('media', existing: 'legacy-bucket', public: ['img/'], expire: ['tmp/' => 1], cors: ['*'])),
        );
        self::assertSame(
            [['key' => 'validation.storage_existing_options', 'params' => ['storage' => 'media', 'options' => 'public', 'bucket' => 'legacy-bucket']]],
            $warnings(App::make('shop')->storage('media', existing: 'legacy-bucket', public: ['img/'])),
        );
        self::assertSame([], $warnings(App::make('shop')->storage('media', existing: 'legacy-bucket')));
        self::assertSame([], $warnings(App::make('shop')->storage('media', public: ['img/'])));
    }

    public function test_existing_table_names(): void
    {
        self::assertContains('validation.table_existing', $this->queueErrors(App::make('shop')->cache(existing: 'a b')));
        self::assertContains('validation.table_existing', $this->queueErrors(App::make('shop')->failedJobsTable(existing: 'x')));
    }

    public function test_the_suffix_message_names_each_suffix_resource_not_only_the_console(): void
    {
        foreach (['en' => ['the console function', 'the resources stack', 'the scheduler rule', 'the CloudFront host function'], 'es' => ['la función de consola', 'el stack de recursos', 'la regla del scheduler', 'la función host de CloudFront']] as $lang => $kinds) {
            $text = (new \Multiapi\Arco\Cli\Translator($lang, \Multiapi\Arco\Arco::packageRoot() . '/lang'))
                ->trans('validation.function_name_suffix', ['app' => 'shop-eu', 'environment' => 'scheduler', 'function' => 'shop-eu-scheduler', 'suffix' => '-scheduler']);
            foreach ($kinds as $kind) {
                self::assertStringContainsString($kind, $text, $lang);
            }
            self::assertStringNotContainsString('is also the console function', $text);
            self::assertStringNotContainsString('también es la función de consola', $text);
        }
    }

    public function test_dns_cloudflare_without_domains_warns(): void
    {
        $app = App::make('shop')->region('us-east-1')->dns('cloudflare')->environment('production');
        $result = (new Validator())->validate(ConfigResolver::resolve($app->toArray(), 'production'), sys_get_temp_dir());

        self::assertTrue($result->isValid());
        self::assertSame(['validation.domain_settings_unused'], array_map(fn (array $w) => $w['key'], $result->warnings));
    }

    /** @return list<array{key: string, params: array<string, string|int>}> */
    private function vpcErrors(App $app): array
    {
        $config = ConfigResolver::resolve($app->region('us-east-1')->environment('production')->toArray(), 'production');
        return array_values(array_filter((new Validator())->validate($config, sys_get_temp_dir())->errors, fn (array $e) => str_starts_with($e['key'], 'validation.vpc_')));
    }

    public function test_vpc_with_one_subnet_and_one_security_group_is_valid(): void
    {
        self::assertSame([], $this->vpcErrors(App::make('shop')->vpc(subnets: ['subnet-a1'], securityGroups: ['sg-b2'])));
        self::assertSame([], $this->vpcErrors(App::make('shop')->vpc(subnets: array_map(fn (int $i) => "subnet-$i", range(1, 16)), securityGroups: ['sg-1', 'sg-2', 'sg-3', 'sg-4', 'sg-5'])));
    }

    public function test_vpc_subnet_count_must_be_1_to_16(): void
    {
        self::assertSame([['key' => 'validation.vpc_subnets_count', 'params' => ['count' => 0]]], $this->vpcErrors(App::make('shop')->vpc(subnets: [], securityGroups: ['sg-b2'])));
        self::assertSame([['key' => 'validation.vpc_subnets_count', 'params' => ['count' => 17]]], $this->vpcErrors(App::make('shop')->vpc(subnets: array_map(fn (int $i) => "subnet-$i", range(1, 17)), securityGroups: ['sg-b2'])));
    }

    public function test_vpc_security_group_count_must_be_1_to_5(): void
    {
        self::assertSame([['key' => 'validation.vpc_security_groups_count', 'params' => ['count' => 0]]], $this->vpcErrors(App::make('shop')->vpc(subnets: ['subnet-a1'])));
        self::assertSame([['key' => 'validation.vpc_security_groups_count', 'params' => ['count' => 6]]], $this->vpcErrors(App::make('shop')->vpc(subnets: ['subnet-a1'], securityGroups: array_map(fn (int $i) => "sg-$i", range(1, 6)))));
    }

    public function test_vpc_ids_must_look_like_subnet_and_security_group_ids(): void
    {
        self::assertSame([['key' => 'validation.vpc_id', 'params' => ['value' => 'subnet_x']]], $this->vpcErrors(App::make('shop')->vpc(subnets: ['subnet_x'], securityGroups: ['sg-b2'])));
        self::assertSame([['key' => 'validation.vpc_id', 'params' => ['value' => 'sg_1']]], $this->vpcErrors(App::make('shop')->vpc(subnets: ['subnet-a1'], securityGroups: ['sg_1'])));
        self::assertSame([['key' => 'validation.vpc_id', 'params' => ['value' => "subnet-a1\n"]]], $this->vpcErrors(App::make('shop')->vpc(subnets: ["subnet-a1\n"], securityGroups: ['sg-b2'])), 'no trailing newline');
    }

    public function test_vpc_endpoints_accept_s3_and_dynamodb(): void
    {
        self::assertSame([], $this->vpcErrors(App::make('shop')->vpc(['subnet-a1'], ['sg-b2'], endpoints: ['s3', 'dynamodb'])));
        self::assertSame([], $this->vpcErrors(App::make('shop')->vpc(['subnet-a1'], ['sg-b2'], endpoints: ['s3' => 'vpce-0abc'])));
    }

    public function test_vpc_endpoint_services_are_limited_to_s3_and_dynamodb(): void
    {
        self::assertSame([['key' => 'validation.vpc_endpoint_service', 'params' => ['value' => 'sqs']]], $this->vpcErrors(App::make('shop')->vpc(['subnet-a1'], ['sg-b2'], endpoints: ['sqs'])));
    }

    public function test_vpc_endpoint_ids_must_look_like_vpce_ids(): void
    {
        self::assertSame([['key' => 'validation.vpc_endpoint_id', 'params' => ['value' => 'vpce-XYZ']]], $this->vpcErrors(App::make('shop')->vpc(['subnet-a1'], ['sg-b2'], endpoints: ['s3' => 'vpce-XYZ'])));
    }

    public function test_vpc_endpoint_services_are_reported_once_when_repeated(): void
    {
        self::assertSame([['key' => 'validation.vpc_endpoint_duplicate', 'params' => ['service' => 's3']]], $this->vpcErrors(App::make('shop')->vpc(['subnet-a1'], ['sg-b2'], endpoints: ['s3', 's3' => 'vpce-0abc'])));
    }

    public function test_vpc_repeated_ids_are_reported_once(): void
    {
        self::assertSame(
            [['key' => 'validation.vpc_duplicate', 'params' => ['value' => 'subnet-a1']], ['key' => 'validation.vpc_duplicate', 'params' => ['value' => 'sg-b2']]],
            $this->vpcErrors(App::make('shop')->vpc(subnets: ['subnet-a1', 'subnet-a1', 'subnet-a1'], securityGroups: ['sg-b2', 'sg-b2'])),
        );
    }
}
