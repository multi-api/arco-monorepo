<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Commands;

use Multiapi\Arco\Commands\QueueAddCommand;
use Multiapi\Arco\Commands\QueueRemoveCommand;
use Multiapi\Arco\Commands\QueueSetCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class QueueCommandsTest extends TestCase
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

    public function test_add_tries_help_describes_laravel_attempts_and_the_dead_letter_queue(): void
    {
        $help = (new QueueAddCommand())->getDefinition()->getOption('tries')->getDescription();

        self::assertSame('Attempts of a job without its own $tries (the dead-letter queue receives it after tries + 2 deliveries)', $help);
    }

    public function test_add_writes_queue_with_options(): void
    {
        $this->project();

        $tester = $this->run_(new QueueAddCommand(), ['name' => 'emails', '--env' => 'production', '--timeout' => '120', '--memory' => '512', '--batch-size' => '5', '--no-dlq' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString("->queue('emails', timeout: 120, memory: 512, batchSize: 5, dlq: false)", $this->source());
        self::assertStringContainsString('Queue emails added (production).', self::display($tester));
        self::assertStringContainsString('Run `arco deploy --env=production` to apply.', self::display($tester));
    }

    public function test_add_duplicate_name_fails(): void
    {
        $this->project("->concurrency(48)", "->concurrency(48)->queue('emails')");
        $before = $this->source();

        $tester = $this->run_(new QueueAddCommand(), ['name' => 'emails', '--app' => true]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('already exists', self::display($tester));
        self::assertSame($before, $this->source());
    }

    public function test_add_invalid_name_is_rejected_and_file_unchanged(): void
    {
        $this->project();
        $before = $this->source();

        $tester = $this->run_(new QueueAddCommand(), ['name' => 'Bad_Name', '--env' => 'production']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('must start with a lowercase letter', self::display($tester));
        self::assertSame($before, $this->source());
    }

    public function test_set_changes_timeout(): void
    {
        $this->project("->concurrency(48)", "->concurrency(48)->queue('emails', timeout: 30)");

        $tester = $this->run_(new QueueSetCommand(), ['name' => 'emails', 'option' => 'timeout', 'value' => '90', '--app' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString("->queue('emails', timeout: 90)", $this->source());
        self::assertStringContainsString('timeout 30 → 90', self::display($tester));
    }

    public function test_set_fifo_is_fixed(): void
    {
        $this->project("->concurrency(48)", "->concurrency(48)->queue('emails')");
        $before = $this->source();

        $tester = $this->run_(new QueueSetCommand(), ['name' => 'emails', 'option' => 'fifo', 'value' => 'on', '--app' => true]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('cannot change after the queue is created', self::display($tester));
        self::assertSame($before, $this->source());
    }

    public function test_remove_at_same_level(): void
    {
        $this->project("->concurrency(48)", "->concurrency(48)->queue('emails')");

        $tester = $this->run_(new QueueRemoveCommand(), ['name' => 'emails', '--app' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringNotContainsString("queue('emails'", $this->source());
        self::assertStringContainsString('kept in AWS', self::display($tester));
    }

    public function test_remove_inherited_queue_with_env_writes_false(): void
    {
        $this->project("->concurrency(48)", "->concurrency(48)->queue('emails')");

        $tester = $this->run_(new QueueRemoveCommand(), ['name' => 'emails', '--env' => 'production']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString("->queue('emails', false)", $this->source());
        self::assertStringContainsString("->queue('emails')", $this->source());
    }

    public function test_set_app_level_ignores_environment_override(): void
    {
        $this->project("->environment('staging')", "->environment('staging', fn (Environment \$env) => \$env->queue('emails', timeout: 30))");
        $php = str_replace("->concurrency(48)", "->concurrency(48)->queue('emails', timeout: 30)", (string) file_get_contents($this->project?->dir . '/arco.php'));
        file_put_contents($this->project?->dir . '/arco.php', $php);

        $tester = $this->run_(new QueueSetCommand(), ['name' => 'emails', 'option' => 'timeout', 'value' => '30', '--app' => true]);
        self::assertStringContainsString('already has this value', self::display($tester));

        $php = str_replace("->queue('emails', timeout: 30))", "->queue('emails', timeout: 99))", $php);
        file_put_contents($this->project?->dir . '/arco.php', $php);
        $tester = $this->run_(new QueueSetCommand(), ['name' => 'emails', 'option' => 'timeout', 'value' => '120', '--app' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('timeout 30 → 120', self::display($tester));
        self::assertStringContainsString("->queue('emails', timeout: 120)", $this->source());
    }

    public function test_add_app_refused_when_only_second_environment_has_it(): void
    {
        $this->project("->timeout(60)", "->timeout(60)->queue('emails')");
        $before = $this->source();

        $tester = $this->run_(new QueueAddCommand(), ['name' => 'emails', '--app' => true]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('already exists in environment production', self::display($tester));
        self::assertSame($before, $this->source());
    }

    public function test_set_concurrency_none_clears_it(): void
    {
        $this->project("->concurrency(48)", "->concurrency(48)->queue('emails', concurrency: 5, timeout: 30)");

        $tester = $this->run_(new QueueSetCommand(), ['name' => 'emails', 'option' => 'concurrency', 'value' => 'none', '--app' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString("->queue('emails', timeout: 30)", $this->source());
        self::assertStringNotContainsString('concurrency: 5', $this->source());
    }

    public function test_set_dlq_false(): void
    {
        $this->project("->concurrency(48)", "->concurrency(48)->queue('emails')");

        $tester = $this->run_(new QueueSetCommand(), ['name' => 'emails', 'option' => 'dlq', 'value' => 'false', '--app' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString("->queue('emails', dlq: false)", $this->source());
    }

    public function test_set_rejects_non_number(): void
    {
        $this->project("->concurrency(48)", "->concurrency(48)->queue('emails')");
        $before = $this->source();

        $tester = $this->run_(new QueueSetCommand(), ['name' => 'emails', 'option' => 'timeout', 'value' => 'abc', '--app' => true]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('needs a whole number', self::display($tester));
        self::assertSame($before, $this->source());
    }

    public function test_set_same_value_changes_nothing(): void
    {
        $this->project("->concurrency(48)", "->concurrency(48)->queue('emails', timeout: 30)");
        $before = $this->source();

        $tester = $this->run_(new QueueSetCommand(), ['name' => 'emails', 'option' => 'timeout', 'value' => '30', '--app' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('already has this value', self::display($tester));
        self::assertSame($before, $this->source());
    }

    public function test_remove_missing_queue_not_found(): void
    {
        $this->project();

        $tester = $this->run_(new QueueRemoveCommand(), ['name' => 'nope', '--env' => 'production']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('is not configured in environment production', self::display($tester));
    }
}
