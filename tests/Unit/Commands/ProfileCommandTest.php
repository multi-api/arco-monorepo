<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Commands;

use Multiapi\Arco\Aws\LocalState;
use Multiapi\Arco\Commands\ProfileCommand;
use PHPUnit\Framework\TestCase;

final class ProfileCommandTest extends TestCase
{
    public function test_sets_and_lists_remembered_profiles(): void
    {
        $project = new ProjectFixture(ProjectFixture::standardConfig());

        $tester = $project->tester(new ProfileCommand());
        $tester->execute(['--env' => 'production', '--set' => 'acme-prod'], ['interactive' => false]);
        $tester->assertCommandIsSuccessful();
        self::assertSame('acme-prod', (new LocalState($project->dir))->profile('production'));

        $tester = $project->tester(new ProfileCommand());
        $tester->execute([], ['interactive' => false]);
        self::assertMatchesRegularExpression('/production\s+acme-prod/', $tester->getDisplay());
        self::assertMatchesRegularExpression('/staging\s+-/', $tester->getDisplay());
        $project->restore();
    }

    public function test_json_lists_the_remembered_profiles_with_schema_1(): void
    {
        $project = new ProjectFixture(ProjectFixture::standardConfig());
        (new LocalState($project->dir))->rememberProfile('production', 'acme-prod');

        $tester = $project->tester(new ProfileCommand());
        $tester->execute(['--json' => true], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        self::assertSame(
            ['schema' => 1, 'profiles' => [['environment' => 'staging', 'profile' => null], ['environment' => 'production', 'profile' => 'acme-prod']]],
            json_decode($tester->getDisplay(), true),
        );

        $tester->execute(['--json' => true, '--env' => 'production'], ['interactive' => false]);
        self::assertSame([['environment' => 'production', 'profile' => 'acme-prod']], json_decode($tester->getDisplay(), true)['profiles']);
        $project->restore();
    }
}
