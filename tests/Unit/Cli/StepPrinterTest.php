<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Cli;

use Multiapi\Arco\Cli\StepPrinter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class StepPrinterTest extends TestCase
{
    private function printer(BufferedOutput $output, bool $interactive): StepPrinter
    {
        $input = new ArrayInput([]);
        $input->setInteractive($interactive);
        return new StepPrinter(
            new SymfonyStyle($input, $output),
            fn (string $key, array $params = []) => $key . ($params === [] ? '' : ' ' . json_encode($params)),
            $interactive && $output->isDecorated(),
        );
    }

    public function test_steps_are_printed_as_bullets(): void
    {
        $output = new BufferedOutput();
        $printer = $this->printer($output, false);

        $printer('build.copying');
        $printer('build.step', ['command' => 'php artisan event:cache']);

        self::assertSame("  • build.copying\n  • build.step {\"command\":\"php artisan event:cache\"}\n", $output->fetch());
    }

    public function test_the_console_download_note_is_a_warning_with_its_reason_translated(): void
    {
        $output = new BufferedOutput();

        $this->printer($output, false)('deploy.console_downloads_binary', ['reason' => 'php_cli_too_large']);

        $text = $output->fetch();
        self::assertStringContainsString('[WARNING]', $text);
        self::assertStringContainsString('deploy.console_reason.php_cli_too_large', $text);
    }

    public function test_the_console_download_reason_gets_the_step_params(): void
    {
        $output = new BufferedOutput();
        $printer = new StepPrinter(
            new SymfonyStyle(new ArrayInput([]), $output),
            fn (string $key, array $params = []) => strtr($key === 'deploy.console_downloads_binary' ? 'note ({reason})' : 'from {url}: {message}', array_combine(array_map(fn ($k) => '{' . $k . '}', array_keys($params)), array_map('strval', $params)) ?: []),
            false,
        );

        $printer('deploy.console_downloads_binary', ['reason' => 'php_cli_unavailable', 'url' => 'https://x.test/php', 'message' => 'timed out']);

        self::assertStringContainsString('note (from https://x.test/php: timed out)', $output->fetch());
    }

    public function test_progress_without_a_terminal_prints_a_few_plain_lines(): void
    {
        $output = new BufferedOutput();
        $printer = $this->printer($output, false);

        $printer('package.writing');
        foreach ([0, 3, 10, 24, 25, 26, 49, 51, 74, 80, 99, 100, 100] as $percent) {
            $printer('package.progress', ['percent' => $percent]);
        }
        $printer('package.ready', ['size' => 3]);

        self::assertSame(
            "  • package.writing\n    25%\n    50%\n    75%\n    100%\n  • package.ready {\"size\":3}\n",
            $output->fetch(),
        );
    }

    public function test_progress_in_a_terminal_draws_a_bar_that_is_closed_before_the_next_step(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $printer = $this->printer($output, true);

        $printer('package.writing');
        foreach ([10, 60, 100] as $percent) {
            $printer('package.progress', ['percent' => $percent]);
        }
        $printer('package.ready', ['size' => 3]);

        $text = $output->fetch();
        self::assertStringContainsString('100%', $text);
        self::assertStringContainsString("\x1B[", $text, 'the bar redraws in place with ANSI sequences');
        self::assertStringNotContainsString("    25%\n", $text);
        self::assertMatchesRegularExpression('/100%[^\n]*\n  • package\.ready/', $text, 'the bar ends its line before the next step');
    }

    public function test_finish_ends_a_bar_left_open_by_an_error(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $printer = $this->printer($output, true);

        $printer('package.progress', ['percent' => 40]);
        $printer->finish();
        $printer->finish();
        $output->writeln('ERROR');

        self::assertMatchesRegularExpression('/40%[^\n]*\nERROR/', $output->fetch());
    }

    public function test_without_progress_reports_only_the_phase_lines_are_printed(): void
    {
        $output = new BufferedOutput();
        $printer = $this->printer($output, false);

        $printer('package.writing');
        $printer->finish();
        $printer('package.ready', ['size' => 1]);

        self::assertSame("  • package.writing\n  • package.ready {\"size\":1}\n", $output->fetch());
    }

    public function test_warning_steps_are_printed_as_warnings(): void
    {
        $output = new BufferedOutput();
        $printer = $this->printer($output, false);

        $printer('deploy.console_skipped_size', ['size' => 300, 'max' => 250]);

        $text = $output->fetch();
        self::assertStringContainsString('[WARNING] deploy.console_skipped_size {"size":300,"max":250}', $text);
        self::assertStringNotContainsString('•', $text);
        self::assertContains('deploy.prune_versions_failed', StepPrinter::WARNINGS);
        self::assertContains('deploy.prune_packages_failed', StepPrinter::WARNINGS);
    }
}
