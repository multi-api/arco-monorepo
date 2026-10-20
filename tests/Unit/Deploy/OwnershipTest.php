<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy;

use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Deploy\DeployException;
use Multiapi\Arco\Deploy\Ownership;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OwnershipTest extends TestCase
{
    private function config(): AppConfig
    {
        return ConfigResolver::resolve(App::make('shop')->region('us-east-1')->environment('eu-prod')->toArray(), 'eu-prod');
    }

    public function test_a_resource_tagged_for_this_app_and_environment_is_owned(): void
    {
        $tags = ['arco:app' => 'shop', 'arco:environment' => 'eu-prod', 'team' => 'x'];
        Ownership::check($tags, $this->config(), 'shop-eu-prod', false);
        self::assertTrue(Ownership::tagged($tags, $this->config()));
    }

    /** @return iterable<string, array{array<string, string>, string, string}> */
    public static function foreign(): iterable
    {
        yield 'another app with the same function name' => [['arco:app' => 'shop-eu', 'arco:environment' => 'prod'], 'shop-eu', 'prod'];
        yield 'another environment' => [['arco:app' => 'shop', 'arco:environment' => 'eu-staging'], 'shop', 'eu-staging'];
        yield 'only the app tag, and it differs' => [['arco:app' => 'other'], 'other', '-'];
        yield 'only the environment tag, and it differs' => [['arco:environment' => 'prod'], '-', 'prod'];
    }

    /** @param array<string, string> $tags */
    #[DataProvider('foreign')]
    public function test_tags_that_exist_and_differ_are_refused_naming_the_owner(array $tags, string $app, string $environment): void
    {
        try {
            Ownership::check($tags, $this->config(), 'shop-eu-prod', false);
            self::fail('expected a refusal');
        } catch (DeployException $e) {
            self::assertSame('deploy.not_owned', $e->key);
            self::assertSame(['resource' => 'shop-eu-prod', 'app' => $app, 'environment' => $environment], $e->params);
        }
        self::assertFalse(Ownership::tagged($tags, $this->config()));
    }

    public function test_remove_refuses_with_its_own_key(): void
    {
        $this->expectExceptionObject(DeployException::make('remove.not_owned', ['resource' => 'shop-eu-prod-console', 'app' => 'shop', 'environment' => 'prod']));
        Ownership::check(['arco:app' => 'shop', 'arco:environment' => 'prod'], $this->config(), 'shop-eu-prod-console', false, false, 'remove');
    }

    public function test_force_overrides_tags_of_another_owner(): void
    {
        Ownership::check(['arco:app' => 'shop-eu', 'arco:environment' => 'prod'], $this->config(), 'shop-eu-prod', true);
        $this->addToAssertionCount(1);
    }

    public function test_an_untagged_resource_without_arco_markers_is_refused_unless_forced(): void
    {
        try {
            Ownership::check(['team' => 'x'], $this->config(), 'shop-eu-prod', false);
            self::fail('expected a refusal');
        } catch (DeployException $e) {
            self::assertSame('deploy.untagged_resource', $e->key);
            self::assertSame(['resource' => 'shop-eu-prod'], $e->params);
        }
        Ownership::check([], $this->config(), 'shop-eu-prod', true);
        self::assertFalse(Ownership::tagged([], $this->config()));
    }

    public function test_an_untagged_resource_arco_made_is_owned(): void
    {
        Ownership::check([], $this->config(), 'shop-eu-prod-console', false, true);
        $this->addToAssertionCount(1);
    }

    public function test_one_matching_tag_with_the_other_missing_counts_as_untagged(): void
    {
        $this->expectExceptionObject(DeployException::make('deploy.untagged_resource', ['resource' => 'shop-eu-prod']));
        Ownership::check(['arco:app' => 'shop'], $this->config(), 'shop-eu-prod', false);
    }
}
