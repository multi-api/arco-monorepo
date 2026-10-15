<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Build;

use Multiapi\Arco\Build\Builder;
use Multiapi\Arco\Build\PathMatcher;
use PHPUnit\Framework\TestCase;

final class PathMatcherTest extends TestCase
{
    public function test_glob_semantics(): void
    {
        $m = new PathMatcher(['.env', '.env.*', 'tests/**', 'storage/logs/*.log', '/node_modules/**', 'docs/**/*.md'], []);

        self::assertTrue($m->excludes('.env', false));
        self::assertTrue($m->excludes('.env.production', false));
        self::assertFalse($m->excludes('config/.env.php', false));
        self::assertTrue($m->excludes('tests', true));
        self::assertTrue($m->excludes('tests/Feature/HomeTest.php', false));
        self::assertTrue($m->excludes('storage/logs/laravel.log', false));
        self::assertFalse($m->excludes('storage/logs/nested/a.log', false));
        self::assertTrue($m->excludes('node_modules', true));
        self::assertTrue($m->excludes('docs/a/b/readme.md', false));
        self::assertTrue($m->excludes('docs/readme.md', false));
        self::assertFalse($m->excludes('app/Models/User.php', false));
    }

    public function test_include_wins(): void
    {
        $m = new PathMatcher(['storage/**'], ['storage/app/keys/**']);

        self::assertFalse($m->excludes('storage', true), 'parent of an included path must be traversed');
        self::assertFalse($m->excludes('storage/app', true));
        self::assertFalse($m->excludes('storage/app/keys/oauth.key', false));
        self::assertTrue($m->excludes('storage/logs', true));
    }

    public function test_editor_and_ai_assistant_files_are_never_packaged(): void
    {
        $m = new PathMatcher(Builder::ALWAYS_EXCLUDED, []);

        foreach (['.idea', '.vscode', '.claude', '.ai', '.junie', '.cursor', '.windsurf', '.gemini', '.codex'] as $dir) {
            self::assertTrue($m->excludes($dir, true), $dir);
        }
        foreach (['.mcp.json', '.aider.conf.yml', '.aider.chat.history.md', 'app/.DS_Store'] as $file) {
            self::assertTrue($m->excludes($file, false), $file);
        }
        self::assertFalse($m->excludes('app/Ai/Agent.php', false));
        self::assertFalse($m->excludes('config/mcp.php', false));
    }
}
