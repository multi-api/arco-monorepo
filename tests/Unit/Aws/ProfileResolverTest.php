<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Aws;

use Multiapi\Arco\Aws\LocalState;
use Multiapi\Arco\Aws\ProfileResolver;
use Multiapi\Arco\Aws\ProfileSource;
use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Config\Environment;
use PHPUnit\Framework\TestCase;

final class ProfileResolverTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/arco-proj-' . bin2hex(random_bytes(4));
        mkdir($this->project);
    }

    private function config(?string $profile = null): AppConfig
    {
        return ConfigResolver::resolve(
            App::make('shop')->region('us-east-1')
                ->environment('production', fn (Environment $e) => $profile ? $e->profile($profile) : $e)
                ->toArray(),
            'production',
        );
    }

    public function test_precedence(): void
    {
        $state = new LocalState($this->project);
        $state->rememberProfile('production', 'remembered');

        $choice = ProfileResolver::resolve('flag', ['AWS_PROFILE' => 'env'], $this->config('config'), $state);
        self::assertSame(['flag', ProfileSource::Flag], [$choice?->profile, $choice?->source]);

        $choice = ProfileResolver::resolve(null, ['AWS_PROFILE' => 'env'], $this->config('config'), $state);
        self::assertSame(['env', ProfileSource::Environment], [$choice?->profile, $choice?->source]);

        $choice = ProfileResolver::resolve(null, [], $this->config('config'), $state);
        self::assertSame(['config', ProfileSource::Config], [$choice?->profile, $choice?->source]);

        $choice = ProfileResolver::resolve(null, [], $this->config(), $state);
        self::assertSame(['remembered', ProfileSource::Remembered], [$choice?->profile, $choice?->source]);

        self::assertNull(ProfileResolver::resolve(null, [], $this->config(), new LocalState($this->project . '/other')));
    }

    public function test_ci_environment_credentials_win_over_remembered_profile(): void
    {
        $state = new LocalState($this->project);
        $state->rememberProfile('production', 'my-laptop-profile');

        $choice = ProfileResolver::resolve(null, ['AWS_ACCESS_KEY_ID' => 'AKIA', 'AWS_SECRET_ACCESS_KEY' => 's'], $this->config(), $state);

        self::assertNotNull($choice);
        self::assertNull($choice->profile);
        self::assertSame(ProfileSource::Environment, $choice->source);
    }

    public function test_remembered_profiles_are_per_environment_and_persisted(): void
    {
        (new LocalState($this->project))->rememberProfile('staging', 'dev');
        (new LocalState($this->project))->rememberProfile('production', 'prod');

        $state = new LocalState($this->project);
        self::assertSame('dev', $state->profile('staging'));
        self::assertSame('prod', $state->profile('production'));
        self::assertNull($state->profile('qa'));
        self::assertFileExists($this->project . '/.arco/local.php');
    }
}
