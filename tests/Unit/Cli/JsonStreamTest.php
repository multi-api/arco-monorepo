<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Cli;

use Multiapi\Arco\Cli\JsonStream;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

final class JsonStreamTest extends TestCase
{
    private BufferedOutput $output;
    /** seconds since the epoch the stream's clock reads */
    private float $now = 1792591385.0; // 2026-10-21T14:03:05Z

    protected function setUp(): void
    {
        $this->output = new BufferedOutput();
    }

    private function stream(): JsonStream
    {
        return new JsonStream(
            $this->output,
            fn (string $key, array $params = []) => $key . ($params === [] ? '' : ' ' . json_encode($params)),
            fn () => $this->now,
        );
    }

    /** @return list<array<string, mixed>> */
    private function lines(): array
    {
        $text = $this->output->fetch();
        self::assertStringEndsWith("\n", $text);
        $lines = [];
        foreach (explode("\n", rtrim($text, "\n")) as $line) {
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            self::assertSame(1, $decoded['schema'] ?? null, $line);
            $lines[] = $decoded;
        }
        return $lines;
    }

    public function test_a_step_starts_and_is_done_with_its_duration_when_the_next_one_starts(): void
    {
        $stream = $this->stream();
        $stream('build.staging', ['app' => 'shop', 'environment' => 'production', 'preset' => 'laravel']);
        $this->now += 2.5;
        $stream('build.copying');

        $lines = $this->lines();
        self::assertSame([
            'schema' => 1, 'type' => 'step', 'key' => 'build.staging', 'status' => 'started', 'at' => '2026-10-21T14:03:05Z',
            'params' => ['app' => 'shop', 'environment' => 'production', 'preset' => 'laravel'],
            'message' => 'build.staging {"app":"shop","environment":"production","preset":"laravel"}',
        ], $lines[0]);
        self::assertSame(['schema' => 1, 'type' => 'step', 'key' => 'build.staging', 'status' => 'done', 'at' => '2026-10-21T14:03:07Z',
            'params' => ['app' => 'shop', 'environment' => 'production', 'preset' => 'laravel'], 'elapsed_ms' => 2500,
            'message' => 'build.staging {"app":"shop","environment":"production","preset":"laravel"}'], $lines[1]);
        self::assertSame(['build.copying', 'started'], [$lines[2]['key'], $lines[2]['status']]);
        self::assertCount(3, $lines);
    }

    public function test_empty_params_are_an_object(): void
    {
        $stream = $this->stream();
        $stream('build.copying');

        self::assertStringContainsString('"params":{}', $this->output->fetch());
    }

    public function test_warnings_and_notes_do_not_end_the_running_step(): void
    {
        $stream = $this->stream();
        $stream('deploy.step_upload', ['size' => 12]);
        $stream('deploy.prune_versions_failed', ['function' => 'f', 'error' => 'AccessDenied']);
        $stream('deploy.console_downloads_binary', ['reason' => 'custom_binary']);
        $stream->warning('config.some_warning', ['x' => 1]);
        $stream->note('deploy.target', ['app' => 'shop']);

        $lines = $this->lines();
        self::assertSame([
            ['deploy.step_upload', 'started'],
            ['deploy.prune_versions_failed', 'warning'],
            ['deploy.console_downloads_binary', 'note'],
            ['config.some_warning', 'warning'],
            ['deploy.target', 'note'],
        ], array_map(fn (array $l) => [$l['key'], $l['status']], $lines));
        // Params are the raw codes; only the message translates them.
        self::assertSame(['reason' => 'custom_binary'], $lines[2]['params']);
        self::assertStringContainsString('deploy.console_reason.custom_binary', $lines[2]['message']);
    }

    public function test_the_permissions_boundary_is_in_the_step_params_and_a_kept_one_is_a_note(): void
    {
        $boundary = 'arn:aws:iam::123456789012:policy/arco-boundary';
        $stream = $this->stream();
        $stream('deploy.step_role', ['role' => 'arco-shop-production', 'boundary' => $boundary]);
        $stream('deploy.boundary_set', ['role' => 'arco-shop-production', 'boundary' => $boundary]);
        $stream('deploy.boundary_kept', ['role' => 'arco-shop-production', 'boundary' => $boundary]);

        $lines = $this->lines();
        self::assertSame([
            ['deploy.step_role', 'started'],
            ['deploy.step_role', 'done'],
            ['deploy.boundary_set', 'started'],
            ['deploy.boundary_kept', 'note'],
        ], array_map(fn (array $l) => [$l['key'], $l['status']], $lines));
        foreach ($lines as $line) {
            self::assertSame(['role' => 'arco-shop-production', 'boundary' => $boundary], $line['params'], 'not a secret: never redacted');
        }
        self::assertSame('note', \Multiapi\Arco\Cli\StepPrinter::kind('deploy.boundary_kept', []));
        self::assertSame('step', \Multiapi\Arco\Cli\StepPrinter::kind('deploy.boundary_set', []));
    }

    public function test_progress_is_sent_first_then_at_most_once_a_second_and_always_at_100(): void
    {
        $stream = $this->stream();
        $stream('package.writing');
        foreach ([1, 2, 3] as $percent) {
            $stream('package.progress', ['percent' => $percent]);
        }
        $this->now += 0.75;
        $stream('package.progress', ['percent' => 40]);
        $this->now += 0.25;
        $stream('package.progress', ['percent' => 41]);
        $stream('package.progress', ['percent' => 99]);
        $this->now += 0.25;
        $stream('package.progress', ['percent' => 100]);
        $stream('package.ready', ['size' => 3]);

        $progress = array_values(array_filter($this->lines(), fn (array $l) => $l['type'] === 'progress'));
        self::assertSame([[1, '2026-10-21T14:03:05Z'], [41, '2026-10-21T14:03:06Z'], [100, '2026-10-21T14:03:06Z']], array_map(fn (array $l) => [$l['percent'], $l['at']], $progress));
        self::assertSame(['schema' => 1, 'type' => 'progress', 'key' => 'build.zip', 'percent' => 1, 'at' => '2026-10-21T14:03:05Z'], $progress[0]);
    }

    public function test_the_php_cli_download_is_its_own_progress_key_and_restarts_the_throttle(): void
    {
        $stream = $this->stream();
        $stream('package.writing');
        $stream('package.progress', ['percent' => 100]);
        $stream('deploy.php_cli_downloading', ['build' => 'b', 'size' => 9]);
        $stream('package.progress', ['percent' => 0]);

        $progress = array_values(array_filter($this->lines(), fn (array $l) => $l['type'] === 'progress'));
        self::assertSame([['build.zip', 100], ['binary.download', 0]], array_map(fn (array $l) => [$l['key'], $l['percent']], $progress));
    }

    public function test_the_uploads_are_their_own_progress_key_with_the_event_shape_unchanged(): void
    {
        $stream = $this->stream();
        $stream('deploy.step_upload', ['size' => 48]);
        $stream('package.progress', ['percent' => 100, 'sent' => 48, 'total' => 48]);
        $stream('deploy.step_upload_console', ['size' => 180]);
        $stream('package.progress', ['percent' => 100, 'sent' => 180, 'total' => 180]);
        $stream('package.progress', ['percent' => 0, 'sent' => 0, 'total' => 90]);   // the runtime binary
        $stream('package.progress', ['percent' => 100, 'sent' => 90, 'total' => 90]);

        $progress = array_values(array_filter($this->lines(), fn (array $l) => $l['type'] === 'progress'));
        self::assertSame([['deploy.upload', 100], ['deploy.upload', 100], ['deploy.upload', 0], ['deploy.upload', 100]], array_map(fn (array $l) => [$l['key'], $l['percent']], $progress), 'a second run in one step starts over and still ends at 100');
        self::assertSame(['schema', 'type', 'key', 'percent', 'at'], array_keys($progress[0]));
    }

    public function test_tick_writes_nothing(): void
    {
        $stream = $this->stream();
        $stream('deploy.network_waiting');
        $this->output->fetch();
        $this->now += 600;
        $stream->tick();

        self::assertSame('', $this->output->fetch());
    }

    public function test_the_result_is_last_ends_the_running_step_on_success_and_is_sent_once(): void
    {
        $stream = $this->stream();
        $stream('deploy.step_traffic', ['revision' => 'rev-2']);
        $this->now += 1;
        $stream->result('deployed', 'shop', 'production', 'rev-2', 'https://abc.on.aws', ['shop.example.com'], false, 812);
        $stream('deploy.late', []);
        $stream->result('failed', 'shop', 'production');

        $lines = $this->lines();
        self::assertCount(3, $lines);
        self::assertSame(['done', 1000], [$lines[1]['status'], $lines[1]['elapsed_ms']]);
        self::assertSame([
            'schema' => 1, 'type' => 'result', 'outcome' => 'deployed', 'app' => 'shop', 'environment' => 'production',
            'revision' => 'rev-2', 'url' => 'https://abc.on.aws', 'domains' => ['shop.example.com'], 'rolled_back' => false,
            'cold_start_ms' => 812, 'error' => null,
        ], $lines[2]);
        self::assertTrue($stream->hasResult());
    }

    public function test_a_failed_result_leaves_the_failing_step_open_and_carries_the_error(): void
    {
        $stream = $this->stream();
        $stream('deploy.step_upload', ['size' => 1]);
        $stream->result('failed', 'shop', 'production', rolledBack: true, errorKey: 'aws.request_failed', errorMessage: 'PutObject failed');

        $lines = $this->lines();
        self::assertCount(2, $lines);
        self::assertSame(['key' => 'aws.request_failed', 'message' => 'PutObject failed'], $lines[1]['error']);
        self::assertTrue($lines[1]['rolled_back']);
        self::assertSame([], $lines[1]['domains']);
    }

    public function test_registered_secrets_are_redacted_from_params_messages_and_the_error(): void
    {
        $stream = $this->stream();
        $stream->redact('s3cr3t-value-123', null, '', 'ab');
        $stream('deploy.command', ['command' => 'echo s3cr3t-value-123 tab']);
        $stream->result('failed', 'shop', 'production', errorKey: 'error.unexpected', errorMessage: 'boom s3cr3t-value-123');

        $text = $this->output->fetch();
        self::assertStringNotContainsString('s3cr3t-value-123', $text);
        self::assertSame(3, substr_count($text, '[redacted]'), 'params, message and error');
        self::assertStringContainsString('[redacted] tab', $text, 'a short secret ("ab") is not redacted inside text');
    }

    public function test_lines_are_written_raw_even_when_the_output_is_quiet(): void
    {
        $this->output->setVerbosity(OutputInterface::VERBOSITY_QUIET);
        $stream = $this->stream();
        $stream('deploy.command', ['command' => '<info>migrate</info>']);

        self::assertStringContainsString('<info>migrate</info>', $this->output->fetch());
    }

    public function test_the_app_and_environment_names_are_never_redacted(): void
    {
        $stream = $this->stream();
        $stream->redact('production', 'shop-secret');
        $stream->identity('shop', 'production');
        $stream('build.staging', ['app' => 'shop', 'environment' => 'production', 'preset' => 'plain']);
        $stream('deploy.command', ['command' => 'echo shop-secret']);
        $stream->result('deployed', 'shop', 'production');
        $stream = $this->stream();

        $lines = $this->lines();
        self::assertSame('production', $lines[0]['params']['environment'], 'a param that is exactly the environment name');
        self::assertSame('echo [redacted]', $lines[2]['params']['command']);
        self::assertSame(['shop', 'production'], [$lines[4]['app'], $lines[4]['environment']]);
    }

    public function test_a_short_secret_is_redacted_only_as_a_whole_value(): void
    {
        // Floci's AWS secret key is "test": as a substring it would mangle every *.test name.
        $stream = $this->stream();
        $stream->redact('test');
        $stream->identity('shop', 'production', 'app.arco-e2e.test');
        $stream('deploy.dns_target', ['zone' => 'arco-e2e.test', 'record' => '_x1.app.arco-e2e.test.', 'url' => 'https://app.arco-e2e.test']);
        $stream('deploy.command', ['command' => 'test']);
        $stream->result('failed', 'shop', 'production', 'rev-1', 'https://app.arco-e2e.test', ['app.arco-e2e.test'], errorKey: 'error.unexpected', errorMessage: 'test');

        $lines = $this->lines();
        self::assertSame(['zone' => 'arco-e2e.test', 'record' => '_x1.app.arco-e2e.test.', 'url' => 'https://app.arco-e2e.test'], $lines[0]['params']);
        self::assertStringContainsString('arco-e2e.test', $lines[0]['message']);
        self::assertSame(['command' => '[redacted]'], $lines[2]['params'], 'a whole param equal to the short secret');
        $result = $lines[count($lines) - 1];
        self::assertSame(['https://app.arco-e2e.test', ['app.arco-e2e.test'], '[redacted]'], [$result['url'], $result['domains'], $result['error']['message']]);
    }

    public function test_a_secret_of_8_characters_or_more_is_redacted_inside_any_text(): void
    {
        $stream = $this->stream();
        $stream->redact('k3y-8chr', 'test');
        $stream('deploy.command', ['command' => 'echo k3y-8chr && echo test']);
        $stream->result('failed', 'shop', 'production', errorKey: 'error.unexpected', errorMessage: 'bad key k3y-8chr in config');

        $lines = $this->lines();
        self::assertSame('echo [redacted] && echo test', $lines[0]['params']['command']);
        self::assertStringNotContainsString('k3y-8chr', $lines[0]['message']);
        self::assertSame('bad key [redacted] in config', $lines[1]['error']['message']);
        self::assertSame('bad key [redacted] in config', $stream->scrubbed('bad key k3y-8chr in config'), 'stderr text too');
        self::assertSame('[redacted]', $stream->scrubbed('test'));
    }

    public function test_the_result_identity_is_not_redacted_even_without_registered_names(): void
    {
        $stream = $this->stream();
        // 8+ characters, so the secret is also redacted inside the message.
        $stream->redact('staging-eu');
        $stream->result('refused', null, 'staging-eu', errorKey: 'aws.no_profile_non_interactive', errorMessage: 'No profile for staging-eu');

        $line = $this->lines()[0];
        self::assertSame('staging-eu', $line['environment']);
        self::assertSame('No profile for [redacted]', $line['error']['message']);
    }
}
