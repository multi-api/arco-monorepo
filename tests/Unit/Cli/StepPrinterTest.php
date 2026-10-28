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
    /** seconds the printer's clock reads */
    private float $now = 1000.0;

    private function printer(BufferedOutput $output, bool $interactive): StepPrinter
    {
        $input = new ArrayInput([]);
        $input->setInteractive($interactive);
        return new StepPrinter(
            new SymfonyStyle($input, $output),
            fn (string $key, array $params = []) => $key . ($params === [] ? '' : ' ' . json_encode($params)),
            $interactive && $output->isDecorated(),
            fn (): float => $this->now,
        );
    }

    public function test_upload_progress_without_a_terminal_names_the_megabytes(): void
    {
        $output = new BufferedOutput();
        $printer = $this->printer($output, false);

        $printer('deploy.step_upload', ['size' => 48]);
        foreach ([[10, 5], [25, 12], [60, 29], [100, 48]] as [$percent, $sent]) {
            $printer('package.progress', ['percent' => $percent, 'sent' => $sent, 'total' => 48]);
        }

        self::assertSame(
            "  • deploy.step_upload {\"size\":48}\n"
            . "    25% (step.progress_mb {\"sent\":12,\"total\":48})\n"
            . "    50% (step.progress_mb {\"sent\":29,\"total\":48})\n"
            . "    75% (step.progress_mb {\"sent\":48,\"total\":48})\n"
            . "    100% (step.progress_mb {\"sent\":48,\"total\":48})\n",
            $output->fetch(),
        );
    }

    public function test_upload_progress_in_a_terminal_shows_the_megabytes_next_to_the_bar(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $printer = $this->printer($output, true);

        $printer('deploy.step_upload', ['size' => 48]);
        $printer('package.progress', ['percent' => 50, 'sent' => 24, 'total' => 48]);
        $printer('deploy.step_role');

        $text = $output->fetch();
        self::assertMatchesRegularExpression('/ 50% step\.progress_mb \{"sent":24,"total":48\}[^\n]*\n  • deploy\.step_role/', $text);
    }

    public function test_a_note_during_an_upload_keeps_its_progress_and_its_start(): void
    {
        $output = new BufferedOutput();
        $printer = $this->printer($output, false);

        $printer('deploy.step_upload', ['size' => 48]);
        $printer('package.progress', ['percent' => 30, 'sent' => '14', 'total' => '48']);
        $printer('deploy.upload_retry', ['key' => 'k', 'error' => 'reset', 'seconds' => 5]);
        $printer('package.progress', ['percent' => 30, 'sent' => '14', 'total' => '48']);
        $printer('package.progress', ['percent' => 55, 'sent' => '26', 'total' => '48']);

        self::assertSame(
            "  • deploy.step_upload {\"size\":48}\n"
            . "    25% (step.progress_mb {\"sent\":\"14\",\"total\":\"48\"})\n"
            . "  • deploy.upload_retry {\"key\":\"k\",\"error\":\"reset\",\"seconds\":5}\n"
            . "    50% (step.progress_mb {\"sent\":\"26\",\"total\":\"48\"})\n",
            $output->fetch(),
            'no 25% again after the retry note',
        );
    }

    public function test_a_note_does_not_restart_the_elapsed_time(): void
    {
        $output = new BufferedOutput();
        $printer = $this->printer($output, false);

        $printer('deploy.network_waiting');
        $this->now += 50;
        $printer('deploy.boundary_kept');
        $this->now += 15;
        $printer->tick();

        self::assertStringContainsString('    step.elapsed {"time":"1 min"}', $output->fetch());
    }

    public function test_tick_shows_the_elapsed_time_once_the_progress_reached_100(): void
    {
        foreach ([true, false] as $live) {
            $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, $live);
            $printer = $this->printer($output, $live);

            $printer('deploy.step_upload', ['size' => 48]);
            $printer('package.progress', ['percent' => 100, 'sent' => '48', 'total' => '48']);
            $this->now += 61;
            $printer->tick();
            $printer('deploy.step_role');

            $text = $output->fetch();
            self::assertMatchesRegularExpression($live ? '/100%[^\n]*\n    step\.elapsed \{"time":"1 min 01 s"\}[^\n]*\n  • deploy\.step_role/' : '/100%[^\n]*\n    step\.elapsed \{"time":"1 min"\}\n  • deploy\.step_role/', $text, $live ? 'live' : 'plain');
        }
    }

    public function test_finish_stops_the_elapsed_time_of_the_last_step(): void
    {
        $output = new BufferedOutput();
        $printer = $this->printer($output, false);

        $printer('deploy.step_commands');
        $printer->finish();
        $this->now += 120;
        $printer->tick();

        self::assertSame("  • deploy.step_commands\n", $output->fetch(), 'nothing under the raw output of a deploy command');
    }

    public function test_tick_prints_nothing_before_a_step_or_in_the_first_ten_seconds(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $printer = $this->printer($output, true);

        $this->now += 100;
        $printer->tick();
        $printer('deploy.network_waiting');
        $this->now += 9.5;
        $printer->tick();

        self::assertSame("  • deploy.network_waiting\n", $output->fetch());
    }

    public function test_tick_in_a_terminal_redraws_the_elapsed_time_under_the_step(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $printer = $this->printer($output, true);

        $printer('deploy.network_waiting');
        $this->now += 12;
        $printer->tick();
        $this->now += 173;
        $printer->tick();
        $printer('deploy.network_updated');

        $text = $output->fetch();
        self::assertStringContainsString('    step.elapsed {"time":"12 s"}', $text);
        self::assertStringContainsString("\x1B[", $text, 'redrawn in place');
        self::assertMatchesRegularExpression('/    step\.elapsed \{"time":"3 min 05 s"\}[^\n]*\n  • deploy\.network_updated/', $text, 'ended before the next step');
    }

    public function test_finish_ends_the_elapsed_line(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $printer = $this->printer($output, true);

        $printer('deploy.network_waiting');
        $this->now += 30;
        $printer->tick();
        $printer->finish();
        $output->writeln('ERROR');

        self::assertMatchesRegularExpression('/"30 s"\}[^\n]*\nERROR/', $output->fetch());
    }

    public function test_tick_without_a_terminal_prints_one_line_per_whole_minute_and_restarts_with_each_step(): void
    {
        $output = new BufferedOutput();
        $printer = $this->printer($output, false);

        $printer('deploy.network_waiting');
        foreach ([15, 30, 20, 5, 10, 200] as $seconds) { // 15, 45, 65, 70, 80, 280 s
            $this->now += $seconds;
            $printer->tick();
        }
        $printer('deploy.network_updated');
        $this->now += 59;
        $printer->tick();
        $this->now += 2;
        $printer->tick();

        self::assertSame(
            "  • deploy.network_waiting\n"
            . "    step.elapsed {\"time\":\"1 min\"}\n"
            . "    step.elapsed {\"time\":\"4 min\"}\n"
            . "  • deploy.network_updated\n"
            . "    step.elapsed {\"time\":\"1 min\"}\n",
            $output->fetch(),
        );
    }

    public function test_tick_draws_nothing_while_a_progress_bar_is_open(): void
    {
        foreach ([true, false] as $live) {
            $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, $live);
            $printer = $this->printer($output, $live);

            $printer('deploy.step_upload', ['size' => 48]);
            $printer('package.progress', ['percent' => 40, 'sent' => 19, 'total' => 48]);
            $this->now += 120;
            $printer->tick();

            self::assertStringNotContainsString('step.elapsed', $output->fetch(), $live ? 'live' : 'plain');
        }
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

    public function test_a_custom_binary_console_note_is_a_plain_bullet_and_the_other_reasons_warn(): void
    {
        $output = new BufferedOutput();
        $this->printer($output, false)('deploy.console_downloads_binary', ['reason' => 'custom_binary']);
        $text = $output->fetch();
        self::assertStringStartsWith('  • deploy.console_downloads_binary {"reason":"deploy.console_reason.custom_binary', $text, 'a deliberate choice: nothing to act on');
        self::assertStringNotContainsString('WARNING', $text);

        foreach (['unknown_build', 'no_php_cli', 'php_cli_too_large', 'php_cli_unavailable'] as $reason) {
            $this->printer($output, false)('deploy.console_downloads_binary', ['reason' => $reason]);
            $text = $output->fetch();
            self::assertStringContainsString('[WARNING]', $text, $reason);
            self::assertStringContainsString("deploy.console_reason.$reason", $text);
        }
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
        self::assertContains('deploy.console_remove_failed', StepPrinter::WARNINGS);
        self::assertNotContains('deploy.console_removed', StepPrinter::WARNINGS);
    }

    public function test_a_kept_bucket_is_a_warning_that_says_whether_files_are_left(): void
    {
        $translator = new \Multiapi\Arco\Cli\Translator('en', dirname(__DIR__, 3) . '/packages/arco/lang');
        $output = new BufferedOutput();
        $printer = new StepPrinter(new SymfonyStyle(new ArrayInput([]), $output), $translator->trans(...), false);

        $printer('deploy.storage_kept', ['bucket' => 'b-1', 'objects' => 'some']);
        $printer('deploy.storage_kept', ['bucket' => 'b-2', 'objects' => 'none']);
        $printer('remove.storage_kept', ['bucket' => 'b-3', 'objects' => 'unknown']);

        $text = (string) preg_replace('/\s+/', ' ', $output->fetch());
        self::assertStringContainsString('[WARNING] The bucket b-1 is no longer in arco.php. Arco kept it in AWS: it still has files.', $text);
        self::assertStringContainsString('The bucket b-2 is no longer in arco.php. Arco kept it in AWS: it is empty.', $text);
        self::assertStringContainsString('The bucket b-3 was kept in AWS: its files could not be listed.', $text);
        self::assertStringNotContainsString('with its files (it is empty)', $text);
    }

    public function test_table_kinds_are_translated(): void
    {
        foreach (['en' => ['Created the cache table t-1', 'The failed-jobs table t-2 is up to date', 'its existing cache table'], 'es' => ['Creada la tabla de caché t-1', 'La tabla de trabajos fallidos t-2 está al día', 'su tabla de caché existente']] as $locale => $expected) {
            $translator = new \Multiapi\Arco\Cli\Translator($locale, dirname(__DIR__, 3) . '/packages/arco/lang');
            $output = new BufferedOutput();
            $printer = new StepPrinter(new SymfonyStyle(new ArrayInput([]), $output), $translator->trans(...), false);

            $printer('deploy.table_created', ['kind' => 'cache', 'name' => 't-1']);
            $printer('deploy.table_updated', ['kind' => 'failed-jobs', 'name' => 't-2']);
            $printer('deploy.table_now_existing', ['table' => 't-3', 'as' => 'cache']);

            $text = (string) preg_replace('/\s+/', ' ', $output->fetch());
            foreach ($expected as $line) {
                self::assertStringContainsString($line, $text, $locale);
            }
            self::assertStringNotContainsString('{', $text);
        }
    }

    public function test_the_log_retention_lines_read_as_spanish(): void
    {
        $translator = new \Multiapi\Arco\Cli\Translator('es', dirname(__DIR__, 3) . '/packages/arco/lang');
        $output = new BufferedOutput();
        $printer = new StepPrinter(new SymfonyStyle(new ArrayInput([]), $output), $translator->trans(...), false);

        $printer('deploy.log_retention', ['days' => 30, 'groups' => 'web']);
        $printer('deploy.log_retention_never', ['groups' => 'all']);
        $printer('deploy.log_retention_one_day', ['groups' => 'web']);

        self::assertSame(
            "  • Retención de logs: 30 días en los grupos de logs de web\n"
            . "  • Retención de logs: no expiran, en los grupos de logs de web y consola\n"
            . "  • Retención de logs: 1 día en los grupos de logs de web\n",
            $output->fetch(),
        );
    }

    public function test_the_log_retention_lines_in_english_name_only_the_groups_touched(): void
    {
        $translator = new \Multiapi\Arco\Cli\Translator('en', dirname(__DIR__, 3) . '/packages/arco/lang');
        $output = new BufferedOutput();
        $printer = new StepPrinter(new SymfonyStyle(new ArrayInput([]), $output), $translator->trans(...), false);

        $printer('deploy.log_retention', ['days' => 30, 'groups' => 'all']);
        $printer('deploy.log_retention_never', ['groups' => 'web']);

        self::assertSame(
            "  • Log retention: 30 days on the web and console log groups\n"
            . "  • Log retention: never expire, on the web log groups\n",
            $output->fetch(),
        );
    }

    public function test_the_default_retention_and_changed_resource_lines_are_plain_bullets_in_both_languages(): void
    {
        foreach (['en' => ['Log retention: 30 days by default on the web log groups', 'Storage files: bucket b updated', 'The cache table t was updated'], 'es' => ['Retención de logs: 30 días por defecto en los grupos de logs de web', 'Almacenamiento files: bucket b actualizado', 'La tabla de caché t se actualizó']] as $locale => $expected) {
            $translator = new \Multiapi\Arco\Cli\Translator($locale, dirname(__DIR__, 3) . '/packages/arco/lang');
            $output = new BufferedOutput();
            $printer = new StepPrinter(new SymfonyStyle(new ArrayInput([]), $output), $translator->trans(...), false);

            $printer('deploy.log_retention_default', ['groups' => 'web']);
            $printer('deploy.storage_changed', ['storage' => 'files', 'bucket' => 'b']);
            $printer('deploy.table_changed', ['kind' => 'cache', 'name' => 't']);
            $printer('deploy.log_tags_not_added', ['groups' => 'g', 'error' => 'denied']);

            $text = $output->fetch();
            foreach ($expected as $line) {
                self::assertStringContainsString("  • $line", $text, $locale);
            }
            self::assertStringContainsString('  • ', substr($text, (int) strrpos($text, "\n", -2)), 'the tag note is a bullet, not a warning block');
        }
    }
}
