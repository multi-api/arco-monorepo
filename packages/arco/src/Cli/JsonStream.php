<?php declare(strict_types=1);

namespace Multiapi\Arco\Cli;

use Closure;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The NDJSON stream of `deploy --json` and `remove --json` (part of the 1.x API): one JSON object per
 * line, each with "schema": 1. It takes the onStep callbacks StepPrinter prints:
 *
 * - `{"type":"step","key","status","at","params","message"}`: a bullet is `started`; it is `done`
 *   (with `elapsed_ms`) when the next bullet starts or the command succeeds. StepPrinter's warning
 *   blocks are `warning`, its WARNINGS bullets and other informational lines `note`;
 * - `{"type":"progress","key","percent","at"}` for `package.progress`: the first of a run, then at most one a
 *   second, and always 100;
 * - `{"type":"result",...}`: exactly once, always last (see result()).
 *
 * `key` and `params` are API; `message` is the human text in the current language and is not.
 * Registered secret values are replaced by [redacted] in every string written.
 */
final class JsonStream
{
    public const SCHEMA = 1;
    /** Progress key by the step that starts the progress run. */
    private const PROGRESS_KEYS = ['package.writing' => 'build.zip', 'deploy.php_cli_downloading' => 'binary.download'];
    private const PROGRESS_FALLBACK = 'progress';
    /** Values this short are not redacted: they would blank out ordinary text. */
    private const MIN_SECRET_LENGTH = 4;

    /** @var array{key: string, params: array<string, string|int>, at: float}|null the running step */
    private ?array $running = null;
    private string $progressKey = self::PROGRESS_FALLBACK;
    private ?float $progressAt = null;
    private ?int $progressPercent = null;
    /** @var list<string> */
    private array $secrets = [];
    /** @var list<string> the app and environment names: never redacted when they are a whole value */
    private array $identity = [];
    private bool $result = false;

    /**
     * @param Closure(string, array<string, string|int>): string $translate
     * @param Closure(): float $clock seconds since the epoch
     */
    public function __construct(
        private readonly OutputInterface $output,
        private readonly Closure $translate,
        private readonly Closure $clock,
    ) {
    }

    /**
     * The onStep callback.
     *
     * @param array<string, string|int> $params
     */
    public function __invoke(string $key, array $params = []): void
    {
        if ($key === StepPrinter::PROGRESS) {
            $this->progress((int) ($params['percent'] ?? 0));
            return;
        }
        $kind = StepPrinter::kind($key, $params);
        if ($kind !== 'step') {
            $this->event($key, $kind, $params);
            return;
        }
        $this->endRunning();
        $this->progressKey = self::PROGRESS_KEYS[$key] ?? self::PROGRESS_FALLBACK;
        $this->progressAt = null;
        $this->progressPercent = null;
        $now = ($this->clock)();
        $this->running = ['key' => $key, 'params' => $params, 'at' => $now];
        $this->event($key, 'started', $params, $now);
    }

    /** @param array<string, string|int> $params */
    public function warning(string $key, array $params = []): void
    {
        $this->event($key, 'warning', $params);
    }

    /** @param array<string, string|int> $params */
    public function note(string $key, array $params = []): void
    {
        $this->event($key, 'note', $params);
    }

    /** Values never written as they are (resolved secrets, tokens); null, empty and short ones are ignored. */
    public function redact(?string ...$values): void
    {
        foreach ($values as $value) {
            if ($value !== null && strlen($value) >= self::MIN_SECRET_LENGTH && ! in_array($value, $this->secrets, true)) {
                $this->secrets[] = $value;
            }
        }
        // Longest first, so a secret containing another is replaced whole.
        usort($this->secrets, fn (string $a, string $b) => strlen($b) <=> strlen($a));
    }

    /** The app and environment names and the custom domains (from arco.php, not secret): a value that is exactly one of them stays as is. */
    public function identity(?string ...$names): void
    {
        foreach ($names as $name) {
            if ($name !== null && $name !== '') {
                $this->identity[] = $name;
            }
        }
    }

    /** $text with the registered secret values redacted, for diagnostics written elsewhere (stderr). */
    public function scrubbed(string $text): string
    {
        return $this->secrets === [] ? $text : str_replace($this->secrets, '[redacted]', $text);
    }

    /** StepPrinter's counterpart: nothing is drawn, so nothing to end. */
    public function finish(): void
    {
    }

    /**
     * The final line; later calls (and events) are ignored. A successful outcome ends the running step
     * as done; otherwise it stays started, the step that failed.
     *
     * @param 'deployed'|'removed'|'failed'|'refused' $outcome
     * @param list<string> $domains
     */
    public function result(
        string $outcome,
        ?string $app,
        ?string $environment,
        ?string $revision = null,
        ?string $url = null,
        array $domains = [],
        bool $rolledBack = false,
        ?int $coldStartMs = null,
        ?string $errorKey = null,
        ?string $errorMessage = null,
    ): void {
        if ($this->result) {
            return;
        }
        if ($outcome === 'deployed' || $outcome === 'removed') {
            $this->endRunning();
        }
        // app and environment name the target and are never redacted, even when a secret has the same value.
        $this->write([
            'type' => 'result',
            'outcome' => $outcome,
            'app' => null,
            'environment' => null,
            'revision' => $revision,
            'url' => $url,
            'domains' => $domains,
            'rolled_back' => $rolledBack,
            'cold_start_ms' => $coldStartMs,
            'error' => $errorKey === null ? null : ['key' => $errorKey, 'message' => (string) $errorMessage],
        ], ['app' => $app, 'environment' => $environment]);
        $this->result = true;
    }

    public function hasResult(): bool
    {
        return $this->result;
    }

    private function progress(int $percent): void
    {
        $percent = max(0, min(100, $percent));
        $now = ($this->clock)();
        $due = $this->progressAt === null || $now - $this->progressAt >= 1.0 || ($percent === 100 && $this->progressPercent !== 100);
        if (! $due) {
            return;
        }
        $this->progressAt = $now;
        $this->progressPercent = $percent;
        $this->write(['type' => 'progress', 'key' => $this->progressKey, 'percent' => $percent, 'at' => self::at($now)]);
    }

    private function endRunning(): void
    {
        if ($this->running === null) {
            return;
        }
        ['key' => $key, 'params' => $params, 'at' => $started] = $this->running;
        $this->running = null;
        $now = ($this->clock)();
        $this->event($key, 'done', $params, $now, (int) round(($now - $started) * 1000));
    }

    /** @param array<string, string|int> $params */
    private function event(string $key, string $status, array $params, ?float $now = null, ?int $elapsedMs = null): void
    {
        $line = [
            'type' => 'step',
            'key' => $key,
            'status' => $status,
            'at' => self::at($now ?? ($this->clock)()),
            'params' => (object) $params,
        ];
        if ($elapsedMs !== null) {
            $line['elapsed_ms'] = $elapsedMs;
        }
        $line['message'] = StepPrinter::message($this->translate, $key, $params);
        $this->write($line);
    }

    /**
     * @param array<string, mixed> $line
     * @param array<string, mixed> $raw fields of $line written as they are, not scrubbed
     */
    private function write(array $line, array $raw = []): void
    {
        if ($this->result) {
            return;
        }
        $scrubbed = $this->scrub(['schema' => self::SCHEMA] + $line);
        $json = json_encode(is_array($scrubbed) ? array_replace($scrubbed, $raw) : $scrubbed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        // Raw (no <tag> styling) and shown even with --quiet: the stream is the command's output.
        $this->output->writeln($json, OutputInterface::OUTPUT_RAW | OutputInterface::VERBOSITY_QUIET);
    }

    /** Every string in $value (arrays and objects included) with the secrets redacted. */
    private function scrub(mixed $value): mixed
    {
        return match (true) {
            $this->secrets === [] => $value,
            is_string($value) => in_array($value, $this->identity, true) ? $value : str_replace($this->secrets, '[redacted]', $value),
            is_array($value) => array_map($this->scrub(...), $value),
            $value instanceof \stdClass => (object) array_map($this->scrub(...), (array) $value),
            default => $value,
        };
    }

    private static function at(float $seconds): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', (int) floor($seconds));
    }
}
