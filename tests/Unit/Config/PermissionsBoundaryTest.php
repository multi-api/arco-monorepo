<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Config;

use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Config\Environment;
use Multiapi\Arco\Config\PermissionsBoundary;
use Multiapi\Arco\Config\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PermissionsBoundaryTest extends TestCase
{
    private const APP = 'arn:aws:iam::123456789012:policy/arco-boundary';
    private const ENV = 'arn:aws:iam::123456789012:policy/env-boundary';
    private const CI = 'arn:aws:iam::123456789012:policy/ci-boundary';
    private const FLAG = 'arn:aws:iam::123456789012:policy/flag-boundary';

    public function test_the_builder_records_the_setting_at_both_levels(): void
    {
        $array = App::make('a')->permissionsBoundary(self::APP)
            ->environment('qa', fn (Environment $e) => $e->permissionsBoundary(false))
            ->toArray();

        self::assertSame(['permissionsBoundary' => self::APP], $array['settings']);
        self::assertSame(['permissionsBoundary' => false], $array['environments']['qa']['settings']);
    }

    public function test_environment_replaces_the_app_value_and_false_clears_it(): void
    {
        $raw = App::make('shop')->region('us-east-1')->permissionsBoundary(self::APP)
            ->environment('staging')
            ->environment('production', fn (Environment $e) => $e->permissionsBoundary(self::ENV))
            ->environment('dev', fn (Environment $e) => $e->permissionsBoundary(false))
            ->toArray();

        $staging = ConfigResolver::resolve($raw, 'staging');
        self::assertSame(self::APP, $staging->permissionsBoundary);
        self::assertSame('app', $staging->sources['permissionsBoundary']);

        $production = ConfigResolver::resolve($raw, 'production');
        self::assertSame(self::ENV, $production->permissionsBoundary);
        self::assertSame('environment', $production->sources['permissionsBoundary']);

        $dev = ConfigResolver::resolve($raw, 'dev');
        self::assertNull($dev->permissionsBoundary);
        self::assertSame('environment', $dev->sources['permissionsBoundary']);
    }

    public function test_unset_is_null_from_the_default(): void
    {
        $config = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->environment('production')->toArray(), 'production');

        self::assertNull($config->permissionsBoundary);
        self::assertSame('default', $config->sources['permissionsBoundary']);
    }

    public function test_precedence_is_flag_then_host_environment_then_arco_php(): void
    {
        $env = [PermissionsBoundary::ENV => self::CI];

        self::assertSame(self::FLAG, PermissionsBoundary::effective(self::FLAG, $env, self::APP));
        self::assertSame(self::CI, PermissionsBoundary::effective(null, $env, self::APP));
        self::assertSame(self::APP, PermissionsBoundary::effective(null, [], self::APP));
        self::assertNull(PermissionsBoundary::effective(null, [], null));
        // Empty means not given, as for --profile and AWS_PROFILE.
        self::assertSame(self::APP, PermissionsBoundary::effective('', [PermissionsBoundary::ENV => ''], self::APP));
        self::assertSame(self::CI, PermissionsBoundary::effective(null, $env, null), 'the host value also applies without one in arco.php');
    }

    public function test_apply_sets_the_value_and_its_source(): void
    {
        $raw = App::make('shop')->region('us-east-1')->permissionsBoundary(self::APP)
            ->environment('production', fn (Environment $e) => $e->permissionsBoundary(false))
            ->toArray();
        $config = ConfigResolver::resolve($raw, 'production');

        $fromFlag = PermissionsBoundary::apply($config, self::FLAG, [PermissionsBoundary::ENV => self::CI]);
        self::assertSame(self::FLAG, $fromFlag->permissionsBoundary);
        self::assertSame('--permissions-boundary', $fromFlag->sources['permissionsBoundary']);

        $fromEnv = PermissionsBoundary::apply($config, null, [PermissionsBoundary::ENV => self::CI]);
        self::assertSame(self::CI, $fromEnv->permissionsBoundary, 'a host value wins over an environment-level false');
        self::assertSame('ARCO_PERMISSIONS_BOUNDARY', $fromEnv->sources['permissionsBoundary']);

        $untouched = PermissionsBoundary::apply($config, null, []);
        self::assertSame($config, $untouched, 'no override: the very same config');
    }

    public function test_the_boundary_survives_with_account(): void
    {
        $config = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->environment('production')->toArray(), 'production');

        $located = PermissionsBoundary::apply($config, self::FLAG, [])->withAccount('123456789012');

        self::assertSame(self::FLAG, $located->permissionsBoundary);
        self::assertSame('--permissions-boundary', $located->sources['permissionsBoundary']);
        self::assertSame('123456789012', $located->account);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function arns(): iterable
    {
        yield 'customer managed' => ['arn:aws:iam::123456789012:policy/arco-boundary', true];
        yield 'AWS managed' => ['arn:aws:iam::aws:policy/PowerUserAccess', true];
        yield 'GovCloud with a path' => ['arn:aws-us-gov:iam::123456789012:policy/path/x', true];
        yield 'China' => ['arn:aws-cn:iam::123456789012:policy/x', true];
        yield 'name characters' => ['arn:aws:iam::123456789012:policy/a_b+c=d,e.f@g-h', true];
        yield 'role ARN' => ['arn:aws:iam::123456789012:role/arco-shop-production', false];
        yield 'empty' => ['', false];
        yield 'missing account' => ['arn:aws:iam:::policy/arco-boundary', false];
        yield 'short account' => ['arn:aws:iam::12345:policy/arco-boundary', false];
        yield 'no name' => ['arn:aws:iam::123456789012:policy/', false];
        yield 'path without a name' => ['arn:aws:iam::123456789012:policy/path/', false];
        yield 'trailing line break' => ["arn:aws:iam::123456789012:policy/arco-boundary\n", false];
        yield 'inner line break' => ["arn:aws:iam::123456789012:policy/arco\r\nboundary", false];
        yield 'unknown partition' => ['arn:aws-xx:iam::123456789012:policy/x', false];
        yield 'another service' => ['arn:aws:s3:::policy/x', false];
        yield 'space' => ['arn:aws:iam::123456789012:policy/a b', false];
    }

    #[DataProvider('arns')]
    public function test_policy_arn_shape(string $arn, bool $valid): void
    {
        self::assertSame($valid, PermissionsBoundary::isPolicyArn($arn));

        $raw = App::make('shop')->region('us-east-1')->permissionsBoundary($arn)->environment('production')->toArray();
        $errors = (new Validator())->validate(ConfigResolver::resolve($raw, 'production'), sys_get_temp_dir())->errors;
        self::assertSame($valid ? [] : [['key' => 'validation.boundary_arn', 'params' => ['value' => $arn, 'source' => 'app']]], $errors);
    }

    public function test_a_boundary_from_any_source_with_a_custom_role_is_an_error(): void
    {
        $role = 'arn:aws:iam::123456789012:role/mine';
        $withRole = App::make('shop')->region('us-east-1')->role($role);
        $validate = fn ($config) => (new Validator())->validate($config, sys_get_temp_dir())->errors;

        $fromConfig = ConfigResolver::resolve((clone $withRole)->permissionsBoundary(self::APP)->environment('production')->toArray(), 'production');
        self::assertSame([['key' => 'validation.boundary_with_custom_role', 'params' => ['boundary' => self::APP, 'source' => 'app']]], $validate($fromConfig));

        $plain = ConfigResolver::resolve((clone $withRole)->environment('production')->toArray(), 'production');
        self::assertSame([], $validate($plain));
        self::assertSame(
            [['key' => 'validation.boundary_with_custom_role', 'params' => ['boundary' => self::CI, 'source' => 'ARCO_PERMISSIONS_BOUNDARY']]],
            $validate(PermissionsBoundary::apply($plain, null, [PermissionsBoundary::ENV => self::CI])),
        );
        self::assertSame(
            [['key' => 'validation.boundary_with_custom_role', 'params' => ['boundary' => self::FLAG, 'source' => '--permissions-boundary']]],
            $validate(PermissionsBoundary::apply($plain, self::FLAG, [])),
        );
    }

    public function test_an_invalid_host_value_names_its_source(): void
    {
        $config = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->environment('production')->toArray(), 'production');
        $bad = 'arn:aws:iam::123456789012:role/x';

        $errors = (new Validator())->validate(PermissionsBoundary::apply($config, null, [PermissionsBoundary::ENV => $bad]), sys_get_temp_dir())->errors;

        self::assertSame([['key' => 'validation.boundary_arn', 'params' => ['value' => $bad, 'source' => 'ARCO_PERMISSIONS_BOUNDARY']]], $errors);
    }
}
