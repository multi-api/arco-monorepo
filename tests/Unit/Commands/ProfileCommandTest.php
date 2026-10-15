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
}
