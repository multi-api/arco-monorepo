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
}
