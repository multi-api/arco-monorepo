<?php declare(strict_types=1);

namespace Multiapi\Arco\Laravel\Tinker;

use Laravel\Tinker\ClassAliasAutoloader;
use Psy\CodeCleaner\NoReturnValue;
use Psy\Configuration;
use Psy\Readline\Transient;
use Psy\Shell;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * Runs one statement for `arco tinker` with PsySH, modelled on Bref's bref:tinker: restores the
 * serialized scope, runs the code, returns the output, the presented value (plain text) and the new
 * serialized scope. Variables that cannot be serialized (closures, PDO, resources) are dropped by name.
 */
final class StatementRunner
{
    /**
     * Each statement is one Lambda invocation, and the reply travels inside the console runtime's
     * 5 MiB output buffer (base64 of JSON, so 4/3 larger): larger scopes are not kept.
     */
    public const MAX_CONTEXT_BYTES = 3_000_000;

    /** The statement's captured output keeps its last bytes, marker included. */
    public const MAX_OUTPUT_BYTES = 1_000_000;

    /** Same marker as the console runtime's OutputBuffer::MARKER (multiapi/arco). */
    public const TRUNCATED = "[truncated]\n";

    /**
     * @param string|null $classMap Composer class map, for tinker's short class names (User for App\Models\User)
     * @param array<mixed> $casters the app's own casters (config tinker.casters): class => callable
     */
    public function __construct(private readonly ?string $classMap = null, private readonly array $casters = [])
    {
    }

    /**
     * The casters `php artisan tinker` registers (Laravel\Tinker\Console\TinkerCommand::getCasters()), so a
     * model shows its attributes, not its internals. Only when laravel/tinker is installed; each class is
     * checked, since not every Laravel version has them all.
     *
     * The app's own casters come last and win; entries that are not callable are left out.
     *
     * @param array<mixed> $extra
     * @return array<string, callable>
     */
    public static function casters(array $extra = []): array
    {
        $caster = 'Laravel\\Tinker\\TinkerCaster';
        $tinker = class_exists($caster) ? [
            'Illuminate\\Support\\Collection' => 'castCollection',
            'Illuminate\\Support\\HtmlString' => 'castHtmlString',
            'Illuminate\\Support\\Stringable' => 'castStringable',
            'Illuminate\\Database\\Eloquent\\Model' => 'castModel',
            'Illuminate\\Process\\ProcessResult' => 'castProcessResult',
            'Illuminate\\Foundation\\Application' => 'castApplication',
        ] : [];
        $casters = [];
        foreach ($tinker as $class => $method) {
            // Older laravel/tinker versions lack some casters (castProcessResult).
            if (class_exists($class) && method_exists($caster, $method)) {
                $casters[$class] = [$caster, $method];
            }
        }
        foreach ($extra as $class => $callable) {
            if (is_string($class) && is_callable($callable)) {
                $casters[$class] = $callable;
            }
        }
        return $casters;
    }

    /** @return array{output: string, presented: string|null, context: string|null, dropped: list<string>, exception: array{class: string, message: string}|null} */
    public function run(string $code, ?string $context): array
    {
        $config = new Configuration(['usePcntl' => false, 'useReadline' => false, 'updateCheck' => 'never', 'rawOutput' => true]);
        // No line is ever read: the userland readline would probe the terminal (stty < /dev/tty) at boot.
        $config->setReadline(new Transient());
        $config->getPresenter()->addCasters(self::casters($this->casters));
        $shell = new Shell($config);
        // PsySH routes what the code prints (and warnings) to the shell output, not to PHP's output.
        $captured = self::capturedOutput();
        $shell->setOutput($captured);
        $shell->setScopeVariables(self::restore($context));
        $aliases = $this->classMap !== null && is_file($this->classMap) && class_exists(ClassAliasAutoloader::class)
            ? ClassAliasAutoloader::register($shell, $this->classMap)
            : null;

        $value = null;
        $exception = null;
        ob_start();
        try {
            $value = $shell->execute($code, true);
        } catch (Throwable $e) {
            $exception = ['class' => $e::class, 'message' => $e->getMessage()];
        } finally {
            $output = ob_get_clean() . $captured->fetch();
            $aliases?->unregister();
        }

        $presented = $exception === null && ! $value instanceof NoReturnValue ? self::plain($config->getPresenter()->present($value)) : null;
        $variables = [];
        foreach (array_diff_key($shell->getScopeVariables(false), $shell->getSpecialScopeVariables(false)) as $name => $variable) {
            $variables[(string) $name] = $variable;
        }
        [$newContext, $dropped] = self::capture($variables);

        return ['output' => self::tail($output, self::MAX_OUTPUT_BYTES), 'presented' => $presented, 'context' => $newContext, 'dropped' => $dropped, 'exception' => $exception];
    }

    /**
     * @param array<string, mixed> $variables
     * @return array{0: string|null, 1: list<string>} serialized scope (base64) and the dropped names
     */
    public static function capture(array $variables): array
    {
        $kept = [];
        $dropped = [];
        foreach ($variables as $name => $value) {
            if (self::holdsResource($value)) {
                $dropped[] = (string) $name;
                continue;
            }
            try {
                serialize($value);
                $kept[$name] = $value;
            } catch (Throwable) {
                $dropped[] = (string) $name;
            }
        }
        if ($kept === []) {
            return [null, $dropped];
        }
        $context = base64_encode(serialize($kept));
        if (strlen($context) > self::MAX_CONTEXT_BYTES) {
            return [null, [...$dropped, ...array_map('strval', array_keys($kept))]];
        }
        return [$context, $dropped];
    }

    /**
     * The scope sent back by the client. Unserializing any class is no escalation: the context only
     * comes from whoever invoked the console function, and that caller can already run any code.
     *
     * @return array<string, mixed>
     */
    public static function restore(?string $context): array
    {
        $raw = $context === null || $context === '' ? false : base64_decode($context, true);
        $variables = $raw === false ? false : @unserialize($raw, ['allowed_classes' => true]);
        $out = [];
        foreach (is_array($variables) ? $variables : [] as $name => $value) {
            if (is_string($name)) {
                $out[$name] = $value;
            }
        }
        return $out;
    }

    /** The last bytes of $text, at most $maxBytes including the marker that replaces the start. */
    public static function tail(string $text, int $maxBytes): string
    {
        if (strlen($text) <= $maxBytes) {
            return $text;
        }
        $keep = $maxBytes - strlen(self::TRUNCATED);
        return self::TRUNCATED . ($keep > 0 ? substr($text, -$keep) : '');
    }

    /** Plain text of everything written to it; PsySH's own newline after output lacking one is left out. */
    private static function capturedOutput(): BufferedOutput
    {
        return new class (self::plain(...)) extends BufferedOutput {
            /** @param \Closure(string): string $plain */
            public function __construct(private readonly \Closure $plain)
            {
                parent::__construct();
            }

            /** @param string|iterable<string> $messages */
            public function write(string|iterable $messages, bool $newline = false, int $options = 0): void
            {
                if ($messages === '' && $newline) {
                    return;
                }
                if (($options & self::OUTPUT_RAW) === 0) {
                    $plain = [];
                    foreach (is_string($messages) ? [$messages] : $messages as $message) {
                        $plain[] = ($this->plain)($message);
                    }
                    $messages = $plain;
                    $options = ($options & ~(self::OUTPUT_NORMAL | self::OUTPUT_PLAIN)) | self::OUTPUT_RAW;
                }
                parent::write($messages, $newline, $options);
            }
        };
    }

    /** serialize() turns resources into i:0 without an error, so they are found first. */
    private static function holdsResource(mixed $value, int $depth = 0): bool
    {
        if (is_resource($value) || gettype($value) === 'resource (closed)') {
            return true;
        }
        if (is_array($value) && $depth < 5) {
            foreach ($value as $item) {
                if (self::holdsResource($item, $depth + 1)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** PsySH presents with style tags (<number>42</number>); the client prints plain text. */
    private static function plain(string $styled): string
    {
        $formatter = new OutputFormatter(false);
        preg_match_all('/<([a-z][a-z0-9_-]*)>/', $styled, $tags);
        foreach (array_unique($tags[1]) as $tag) {
            if (! $formatter->hasStyle($tag)) {
                $formatter->setStyle($tag, new OutputFormatterStyle());
            }
        }
        return (string) $formatter->format($styled);
    }
}
