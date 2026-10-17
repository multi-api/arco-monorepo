<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Commands;

use Multiapi\Arco\Commands\StorageAddCommand;
use Multiapi\Arco\Commands\StorageCorsCommand;
use Multiapi\Arco\Commands\StorageExpireCommand;
use Multiapi\Arco\Commands\StoragePublicCommand;
use Multiapi\Arco\Commands\StorageRemoveCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class StorageCommandsTest extends TestCase
{
    private ?ProjectFixture $project = null;

    protected function tearDown(): void
    {
        $this->project?->restore();
    }

    private function project(string $replace = '', string $with = ''): ProjectFixture
    {
        $php = ProjectFixture::standardConfig();
        return $this->project = new ProjectFixture($replace === '' ? $php : str_replace($replace, $with, $php));
    }

    /** standardConfig() with these app-level storage() calls. */
    private function withStorage(string $calls): ProjectFixture
    {
        return $this->project('->concurrency(48)', '->concurrency(48)' . $calls);
    }

    /** @param array<string, mixed> $input */
    private function run_(Command $command, array $input): CommandTester
    {
        $tester = $this->project?->tester($command) ?? throw new \LogicException('No project.');
        $tester->execute($input, ['interactive' => false]);
        return $tester;
    }

    private function source(): string
    {
        return (string) file_get_contents($this->project?->dir . '/arco.php');
    }

    private static function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    public function test_add_writes_storage_in_environment(): void
    {
        $this->project();

        $tester = $this->run_(new StorageAddCommand(), ['name' => 'uploads', '--env' => 'production']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString("->storage('uploads')", $this->source());
        self::assertStringContainsString('Storage uploads added (production).', self::display($tester));
        self::assertStringContainsString('Run `arco deploy --env=production` to apply.', self::display($tester));
    }

    public function test_add_existing_default_at_app_level(): void
    {
        $this->project();

        $tester = $this->run_(new StorageAddCommand(), ['name' => 'uploads', '--existing' => 'my-bucket', '--default' => true, '--app' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString("->storage('uploads', existing: 'my-bucket', default: true)", $this->source());
        self::assertStringContainsString('Deploy each environment to apply', self::display($tester));
    }

    public function test_add_duplicate_name_fails(): void
    {
        $this->withStorage("->storage('uploads')");
        $before = $this->source();

        $tester = $this->run_(new StorageAddCommand(), ['name' => 'uploads', '--app' => true]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Storage "uploads" already exists in the app (all environments).', self::display($tester));
        self::assertSame($before, $this->source());
    }

    public function test_add_invalid_existing_bucket_is_rejected(): void
    {
        $this->project();
        $before = $this->source();

        $tester = $this->run_(new StorageAddCommand(), ['name' => 'uploads', '--existing' => 'Bad_Bucket', '--app' => true]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('existing must be an S3 bucket name', self::display($tester));
        self::assertSame($before, $this->source());
    }

    public function test_remove_at_same_level_keeps_bucket(): void
    {
        $this->withStorage("->storage('uploads')");

        $tester = $this->run_(new StorageRemoveCommand(), ['name' => 'uploads', '--app' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringNotContainsString("storage('uploads'", $this->source());
        self::assertStringContainsString('Storage uploads removed (app).', self::display($tester));
        self::assertStringContainsString('its files are kept in AWS', self::display($tester));
    }

    public function test_remove_inherited_storage_with_env_writes_false(): void
    {
        $this->withStorage("->storage('uploads')");

        $tester = $this->run_(new StorageRemoveCommand(), ['name' => 'uploads', '--env' => 'production']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString("->storage('uploads', false)", $this->source());
        self::assertStringContainsString("->storage('uploads')", $this->source());
    }

    public function test_remove_missing_storage_not_found(): void
    {
        $this->project();

        $tester = $this->run_(new StorageRemoveCommand(), ['name' => 'nope', '--env' => 'production']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Storage "nope" is not configured in environment production.', self::display($tester));
    }

    public function test_expire_adds_rule(): void
    {
        $this->withStorage("->storage('uploads')");

        $tester = $this->run_(new StorageExpireCommand(), ['name' => 'uploads', 'prefix' => 'tmp/', 'days' => '7', '--app' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString("->storage('uploads', expire: ['tmp/' => 7])", $this->source());
        self::assertStringContainsString('Storage uploads (app): expire none → tmp/ after 7 days', self::display($tester));
        self::assertStringContainsString('Deploy each environment to apply', self::display($tester));
    }

    public function test_expire_zero_days_is_rejected_and_file_unchanged(): void
    {
        $this->withStorage("->storage('uploads')");
        $before = $this->source();

        $tester = $this->run_(new StorageExpireCommand(), ['name' => 'uploads', 'prefix' => 'tmp/', 'days' => '0', '--app' => true]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('its days must be at least 1, got 0', self::display($tester));
        self::assertSame($before, $this->source());
    }

    public function test_expire_needs_days_unless_removing(): void
    {
        $this->withStorage("->storage('uploads')");
        $before = $this->source();

        $tester = $this->run_(new StorageExpireCommand(), ['name' => 'uploads', 'prefix' => 'tmp/', '--app' => true]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Give the number of days', self::display($tester));
        self::assertSame($before, $this->source());
    }

    public function test_expire_remove_drops_one_rule(): void
    {
        $this->withStorage("->storage('uploads', expire: ['tmp/' => 7, 'cache/' => 1])");

        $tester = $this->run_(new StorageExpireCommand(), ['name' => 'uploads', 'prefix' => 'tmp/', '--remove' => true, '--app' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString("->storage('uploads', expire: ['cache/' => 1])", $this->source());
    }

    public function test_expire_with_env_on_inherited_storage_writes_override(): void
    {
        $this->withStorage("->storage('uploads')");

        $tester = $this->run_(new StorageExpireCommand(), ['name' => 'uploads', 'prefix' => 'tmp/', 'days' => '3', '--env' => 'production']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString("->storage('uploads')", $this->source());
        self::assertStringContainsString("->storage('uploads', expire: ['tmp/' => 3])", $this->source());
        self::assertStringContainsString('Run `arco deploy --env=production` to apply.', self::display($tester));
    }

    public function test_public_adds_and_removes_prefix(): void
    {
        $this->withStorage("->storage('uploads')");

        $tester = $this->run_(new StoragePublicCommand(), ['name' => 'uploads', 'prefix' => 'avatars/', '--app' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString("->storage('uploads', public: ['avatars/'])", $this->source());
        self::assertStringContainsString('public none → avatars/', self::display($tester));

        $tester = $this->run_(new StoragePublicCommand(), ['name' => 'uploads', 'prefix' => 'avatars/', '--remove' => true, '--app' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString("->storage('uploads')", $this->source());
        self::assertStringNotContainsString('avatars/', $this->source());
    }

    public function test_public_same_prefix_changes_nothing(): void
    {
        $this->withStorage("->storage('uploads', public: ['avatars/'])");
        $before = $this->source();

        $tester = $this->run_(new StoragePublicCommand(), ['name' => 'uploads', 'prefix' => 'avatars/', '--app' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('already has this value', self::display($tester));
        self::assertSame($before, $this->source());
    }

    public function test_cors_adds_origins_and_removes_one(): void
    {
        $this->withStorage("->storage('uploads')");

        $tester = $this->run_(new StorageCorsCommand(), ['name' => 'uploads', 'origins' => ['https://a.example', 'https://b.example'], '--app' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString("->storage('uploads', cors: ['https://a.example', 'https://b.example'])", $this->source());

        $tester = $this->run_(new StorageCorsCommand(), ['name' => 'uploads', 'origins' => ['https://a.example'], '--remove' => true, '--app' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString("->storage('uploads', cors: ['https://b.example'])", $this->source());
    }

    public function test_cors_invalid_origin_is_rejected(): void
    {
        $this->withStorage("->storage('uploads')");
        $before = $this->source();

        $tester = $this->run_(new StorageCorsCommand(), ['name' => 'uploads', 'origins' => ['example.com'], '--app' => true]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('CORS origin "example.com"', self::display($tester));
        self::assertSame($before, $this->source());
    }

    public function test_add_with_a_laravel_disk_name_is_rejected(): void
    {
        $this->project();
        $before = $this->source();

        $tester = $this->run_(new StorageAddCommand(), ['name' => 'public', '--env' => 'production']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Storage cannot be named "public"', self::display($tester));
        self::assertSame($before, $this->source());
    }

    public function test_public_prefix_with_a_wildcard_is_rejected(): void
    {
        $this->withStorage("->storage('uploads')");
        $before = $this->source();

        $tester = $this->run_(new StoragePublicCommand(), ['name' => 'uploads', 'prefix' => '*/', '--app' => true]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('cannot contain * or ?', self::display($tester));
        self::assertSame($before, $this->source());
    }

    public function test_an_option_on_an_existing_bucket_is_written_with_a_warning(): void
    {
        $this->withStorage("->storage('media', existing: 'legacy-bucket')");

        $tester = $this->run_(new StoragePublicCommand(), ['name' => 'media', 'prefix' => 'img/', '--app' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString("->storage('media', existing: 'legacy-bucket', public: ['img/'])", $this->source());
        self::assertStringContainsString('public is ignored for the existing bucket legacy-bucket', self::display($tester));
    }

    public function test_option_of_missing_storage_not_found(): void
    {
        $this->project();

        $tester = $this->run_(new StoragePublicCommand(), ['name' => 'nope', 'prefix' => 'a/', '--env' => 'production']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Storage "nope" is not configured in environment production.', self::display($tester));
    }

    public function test_app_remove_warns_about_environment_overrides(): void
    {
        $this->project('->concurrency(48)', "->concurrency(48)->storage('uploads')");
        $php = str_replace('->timeout(60)', "->timeout(60)->storage('uploads', expire: ['tmp/' => 7])", $this->source());
        file_put_contents($this->project?->dir . '/arco.php', $php);

        $tester = $this->run_(new StorageRemoveCommand(), ['name' => 'uploads', '--app' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString("->storage('uploads', expire: ['tmp/' => 7])", $this->source());
        self::assertStringContainsString('Environment production still has its own', self::display($tester));
        self::assertStringContainsString('arco storage:remove uploads --env=production', self::display($tester));
        self::assertStringNotContainsString('storage:remove uploads --env=staging', self::display($tester));
    }

    public function test_add_second_default_is_rejected(): void
    {
        $this->withStorage("->storage('files', default: true)");
        $before = $this->source();

        $tester = $this->run_(new StorageAddCommand(), ['name' => 'uploads', '--default' => true, '--app' => true]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Only one storage can be the default', self::display($tester));
        self::assertSame($before, $this->source());
    }

    public function test_expire_days_with_remove_is_a_usage_error(): void
    {
        $this->withStorage("->storage('uploads', expire: ['tmp/' => 7])");
        $before = $this->source();

        $tester = $this->run_(new StorageExpireCommand(), ['name' => 'uploads', 'prefix' => 'tmp/', 'days' => '7', '--remove' => true, '--app' => true]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Do not give days with --remove', self::display($tester));
        self::assertSame($before, $this->source());
    }

    public function test_env_override_back_to_app_value_drops_the_override(): void
    {
        $this->withStorage("->storage('uploads', public: ['a/'])");
        $php = str_replace('->timeout(60)', "->timeout(60)->storage('uploads', public: ['a/', 'b/'])", $this->source());
        file_put_contents($this->project?->dir . '/arco.php', $php);

        $tester = $this->run_(new StoragePublicCommand(), ['name' => 'uploads', 'prefix' => 'b/', '--remove' => true, '--env' => 'production']);

        $tester->assertCommandIsSuccessful();
        self::assertStringNotContainsString("'b/'", $this->source());
        self::assertSame(1, substr_count($this->source(), "->storage('uploads'"));
        self::assertStringContainsString('public a/, b/ → a/', self::display($tester));
    }

    public function test_cors_remove_of_unknown_origin_changes_nothing(): void
    {
        $this->withStorage("->storage('uploads', cors: ['https://a.example'])");
        $before = $this->source();

        $tester = $this->run_(new StorageCorsCommand(), ['name' => 'uploads', 'origins' => ['https://z.example'], '--remove' => true, '--app' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('has no cors entry "https://z.example"', self::display($tester));
        self::assertSame($before, $this->source());
    }
}
