<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Commands;

use Multiapi\Arco\Commands\CacheAddCommand;
use Multiapi\Arco\Commands\CacheRemoveCommand;
use Multiapi\Arco\Commands\FailedJobsAddCommand;
use Multiapi\Arco\Commands\FailedJobsRemoveCommand;
use Multiapi\Arco\Commands\QueueFailedTableCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class TableCommandsTest extends TestCase
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

    public function test_cache_add_in_environment(): void
    {
        $this->project();

        $tester = $this->run_(new CacheAddCommand(), ['--env' => 'production']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('->cache()', $this->source());
        self::assertStringContainsString('Cache table (production): off → on', self::display($tester));
        self::assertStringContainsString('Run `arco deploy --env=production` to apply.', self::display($tester));
    }

    public function test_cache_add_existing_at_app_level(): void
    {
        $this->project();

        $tester = $this->run_(new CacheAddCommand(), ['--existing' => 'my-cache', '--app' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString("->cache(existing: 'my-cache')", $this->source());
        self::assertStringContainsString('Cache table (app): off → existing table my-cache', self::display($tester));
    }

    public function test_cache_add_when_already_on_changes_nothing(): void
    {
        $this->project('->concurrency(48)', '->concurrency(48)->cache()');
        $before = $this->source();

        $tester = $this->run_(new CacheAddCommand(), ['--env' => 'production']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('already has this value', self::display($tester));
        self::assertSame($before, $this->source());
    }

    public function test_cache_add_invalid_existing_is_rejected(): void
    {
        $this->project();
        $before = $this->source();

        $tester = $this->run_(new CacheAddCommand(), ['--existing' => 'a b', '--app' => true]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('existing must be a DynamoDB table name', self::display($tester));
        self::assertSame($before, $this->source());
    }

    public function test_cache_remove_inherited_with_env_writes_false(): void
    {
        $this->project('->concurrency(48)', '->concurrency(48)->cache()');

        $tester = $this->run_(new CacheRemoveCommand(), ['--env' => 'production']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('->cache(false)', $this->source());
        self::assertStringContainsString('->cache()', $this->source());
        self::assertStringContainsString('Cache table (production): on → off', self::display($tester));
        self::assertStringContainsString('kept in AWS', self::display($tester));
    }

    public function test_cache_remove_at_its_level_removes_the_call(): void
    {
        $this->project('->concurrency(48)', '->concurrency(48)->cache()');

        $tester = $this->run_(new CacheRemoveCommand(), ['--app' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringNotContainsString('->cache(', $this->source());
    }

    public function test_cache_remove_when_off_changes_nothing(): void
    {
        $this->project();
        $before = $this->source();

        $tester = $this->run_(new CacheRemoveCommand(), ['--env' => 'production']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('already has this value', self::display($tester));
        self::assertSame($before, $this->source());
    }

    public function test_failed_jobs_add_and_remove(): void
    {
        $this->project();

        $tester = $this->run_(new FailedJobsAddCommand(), ['--app' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('->failedJobsTable()', $this->source());
        self::assertStringContainsString('Failed-jobs table (app): off → on', self::display($tester));
        self::assertStringNotContainsString('deprecated', self::display($tester));

        $tester = $this->run_(new FailedJobsRemoveCommand(), ['--app' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringNotContainsString('failedJobsTable', $this->source());
    }

    public function test_failed_jobs_add_existing(): void
    {
        $this->project();

        $tester = $this->run_(new FailedJobsAddCommand(), ['--existing' => 'shop-failed', '--env' => 'production']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString("->failedJobsTable(existing: 'shop-failed')", $this->source());
    }

    public function test_queue_failed_table_is_a_hidden_deprecated_alias(): void
    {
        $this->project();
        self::assertTrue((new QueueFailedTableCommand())->isHidden());

        $tester = $this->run_(new QueueFailedTableCommand(), ['--app' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('->failedJobsTable()', $this->source());
        self::assertSame(1, substr_count(self::display($tester), 'queue:failed-table is deprecated'));
        self::assertStringContainsString('failed-jobs:add', self::display($tester));
    }

    public function test_cache_driver_dynamodb_round_trips_through_the_editor(): void
    {
        $this->project('->concurrency(48)', "->concurrency(48)->cache(driver: 'dynamodb')");

        // Already on: nothing to change.
        $before = $this->source();
        $tester = $this->run_(new CacheAddCommand(), ['--app' => true]);
        $tester->assertCommandIsSuccessful();
        self::assertSame($before, $this->source());

        // Rewriting the call drops the default driver; nothing else changes.
        $tester = $this->run_(new CacheAddCommand(), ['--existing' => 'shop-cache', '--app' => true]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString("->cache(existing: 'shop-cache')", $this->source());
        self::assertStringNotContainsString('driver:', $this->source());
    }

    public function test_cache_remove_with_an_explicit_driver(): void
    {
        $this->project('->concurrency(48)', "->concurrency(48)->cache(driver: 'dynamodb')");

        $tester = $this->run_(new CacheRemoveCommand(), ['--app' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringNotContainsString('->cache(', $this->source());
    }

    public function test_failed_table_add_and_remove(): void
    {
        $this->project();

        $tester = $this->run_(new QueueFailedTableCommand(), ['--app' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('->failedJobsTable()', $this->source());
        self::assertStringContainsString('Failed-jobs table (app): off → on', self::display($tester));

        $tester = $this->run_(new QueueFailedTableCommand(), ['--remove' => true, '--app' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringNotContainsString('failedJobsTable', $this->source());
    }

    public function test_failed_table_existing(): void
    {
        $this->project();

        $tester = $this->run_(new QueueFailedTableCommand(), ['--existing' => 'shop-failed', '--env' => 'production']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString("->failedJobsTable(existing: 'shop-failed')", $this->source());
    }

    public function test_failed_table_existing_with_remove_is_rejected(): void
    {
        $this->project('->concurrency(48)', '->concurrency(48)->failedJobsTable()');
        $before = $this->source();

        $tester = $this->run_(new QueueFailedTableCommand(), ['--existing' => 'shop-failed', '--remove' => true, '--app' => true]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Pass either --existing or --remove, not both.', self::display($tester));
        self::assertSame($before, $this->source());
    }

    public function test_empty_existing_counts_as_not_given(): void
    {
        $this->project('->concurrency(48)', '->concurrency(48)->failedJobsTable()');

        $tester = $this->run_(new QueueFailedTableCommand(), ['--existing' => '', '--remove' => true, '--app' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringNotContainsString('failedJobsTable', $this->source());

        $tester = $this->run_(new CacheAddCommand(), ['--existing' => '', '--app' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('->cache()', $this->source());
    }
}
