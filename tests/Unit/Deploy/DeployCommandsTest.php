<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy;

use Multiapi\Arco\Deploy\DeployCommands;
use PHPUnit\Framework\TestCase;

final class DeployCommandsTest extends TestCase
{
    public function test_configured_commands_run_as_listed(): void
    {
        self::assertSame(['config:cache'], DeployCommands::resolve(['config:cache'], false, false));
    }

    public function test_migrate_is_prepended_once(): void
    {
        self::assertSame(['migrate --force', 'config:cache'], DeployCommands::resolve(['config:cache'], true, false));
        self::assertSame(['config:cache', 'migrate --force --seed'], DeployCommands::resolve(['config:cache', 'migrate --force --seed'], true, false));
        self::assertSame(['migrate --force', 'migrate:status'], DeployCommands::resolve(['migrate:status'], true, false), 'migrate:status is not a migration');
    }

    public function test_no_deploy_commands_skips_everything(): void
    {
        self::assertSame([], DeployCommands::resolve(['migrate --force'], false, true));
    }

    public function test_has_migrate_only_matches_the_migrate_command_itself(): void
    {
        self::assertTrue(DeployCommands::hasMigrate(['config:cache', 'migrate --force']));
        self::assertFalse(DeployCommands::hasMigrate(['migrate:status', 'migrate:rollback', 'scripts/migrate.php']));
        self::assertFalse(DeployCommands::hasMigrate(['"unterminated']));
    }
}
