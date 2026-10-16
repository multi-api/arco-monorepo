<?php declare(strict_types=1);

namespace Multiapi\Arco\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Multiapi\Arco\Laravel\Tinker\StatementRunner;
use Psy\Shell;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs one statement sent by `arco tinker` on the console function. Reads
 * {"code": base64, "context": base64|null} from stdin and prints one line:
 * ARCO-TINKER:<base64 JSON {output, presented, context, dropped, exception}>.
 */
final class TinkerCommand extends Command
{
    /** Must match Multiapi\Arco\Tinker\TinkerSession::MARKER (multiapi/arco). */
    public const MARKER = 'ARCO-TINKER:';
    /**
     * Exit code when the statement threw: 1 also means Laravel failed to boot or this command refused
     * the request, which the console runtime must still log as a failure. Must match
     * Multiapi\Arco\Tinker\TinkerSession::STATEMENT_THREW and the runtime's Handler::TINKER_STATEMENT_THREW.
     */
    public const STATEMENT_THREW = 3;

    /**
     * The console runtime keeps only the last 5 MiB (5,242,880 bytes) of a command's output; a cut
     * line loses its marker. This leaves room for the newline and for warnings printed after it.
     */
    public const MAX_LINE_BYTES = 5_000_000;

    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    protected $signature = 'arco:tinker';
    protected $description = 'Run one statement sent by arco tinker (internal)';
    protected $hidden = true;

    public function handle(): int
    {
        $stdin = fopen('php://stdin', 'rb');
        $input = $stdin === false ? '' : (string) stream_get_contents($stdin);
        $config = $this->laravel->bound('config') ? $this->laravel->make('config') : null;
        $casters = $config instanceof Repository ? $config->get('tinker.casters', []) : [];
        [$exitCode, $line] = self::reply($input, new StatementRunner($this->laravel->basePath('vendor/composer/autoload_classmap.php'), is_array($casters) ? $casters : []));
        $this->getOutput()->writeln($line, OutputInterface::OUTPUT_RAW);
        return $exitCode;
    }

    /** @return array{0: int, 1: string} exit code and the result line */
    public static function reply(string $input, ?StatementRunner $runner = null): array
    {
        $request = json_decode($input, true);
        $code = is_array($request) && is_string($request['code'] ?? null) ? base64_decode($request['code'], true) : false;
        $context = is_array($request) && is_string($request['context'] ?? null) ? $request['context'] : null;
        if ($code === false) {
            $reply = self::failure('InvalidArgumentException', 'arco:tinker expects {"code": base64, "context": base64|null} on stdin.');
            $exitCode = 1;
        } elseif (! class_exists(Shell::class)) {
            $reply = self::failure('Arco.TinkerMissing', 'psy/psysh is not installed in this app: add laravel/tinker to "require" in composer.json.');
            $exitCode = 1;
        } else {
            $reply = ($runner ?? new StatementRunner())->run($code, $context);
            $exitCode = $reply['exception'] === null ? 0 : self::STATEMENT_THREW;
        }
        return [$exitCode, self::MARKER . base64_encode(self::fit($reply))];
    }

    /**
     * The reply as JSON short enough for MAX_LINE_BYTES once base64-encoded. Over it, gives way in
     * order: the output (keeps its end), the presented value and the exception message (keep their
     * start), then the scope (its variables are reported as dropped).
     *
     * @param array{output: string, presented: string|null, context: string|null, dropped: list<string>, exception: array{class: string, message: string}|null} $reply
     */
    private static function fit(array $reply): string
    {
        $budget = intdiv(self::MAX_LINE_BYTES - strlen(self::MARKER), 4) * 3;
        $json = json_encode($reply, self::JSON_FLAGS);
        while (strlen($json) > $budget) {
            // Escaping makes text up to 6x longer (control characters become \u00XX): cut in proportion.
            $ratio = $budget / strlen($json) * 0.9;
            if ($reply['output'] !== '') {
                $reply['output'] = self::cut($reply['output'], $ratio, true);
            } elseif ($reply['presented'] !== null && $reply['presented'] !== '') {
                $reply['presented'] = self::cut($reply['presented'], $ratio, false);
            } elseif ($reply['exception'] !== null && $reply['exception']['message'] !== '') {
                $reply['exception']['message'] = self::cut($reply['exception']['message'], $ratio, false);
            } elseif ($reply['context'] !== null) {
                $reply['dropped'] = [...$reply['dropped'], ...array_map('strval', array_keys(StatementRunner::restore($reply['context'])))];
                $reply['context'] = null;
            } else {
                break;
            }
            $json = json_encode($reply, self::JSON_FLAGS);
        }
        return $json;
    }

    /** $text shortened to about $ratio of its length, keeping its end or its start; tiny remains go. */
    private static function cut(string $text, float $ratio, bool $keepEnd): string
    {
        $body = $keepEnd
            ? (str_starts_with($text, StatementRunner::TRUNCATED) ? substr($text, strlen(StatementRunner::TRUNCATED)) : $text)
            : (str_ends_with($text, StatementRunner::TRUNCATED) ? substr($text, 0, -strlen(StatementRunner::TRUNCATED)) : $text);
        $keep = (int) floor(strlen($body) * $ratio);
        if ($keep < 1024) {
            return '';
        }
        return $keepEnd ? StatementRunner::TRUNCATED . substr($body, -$keep) : substr($body, 0, $keep) . StatementRunner::TRUNCATED;
    }

    /** @return array{output: string, presented: null, context: null, dropped: list<string>, exception: array{class: string, message: string}} */
    private static function failure(string $class, string $message): array
    {
        return ['output' => '', 'presented' => null, 'context' => null, 'dropped' => [], 'exception' => ['class' => $class, 'message' => $message]];
    }
}
