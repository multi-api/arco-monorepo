<?php declare(strict_types=1);

namespace Multiapi\Arco\ConsoleRuntime;

/**
 * Masks the values of secret-looking options (--password=..., --api-key ...) before a command line is
 * written to the function log. The command itself still gets the real values; the rest of the line is
 * kept for the audit trail.
 */
final class SecretArguments
{
    public const MASK = '***';
    private const SECRET_NAME = '/pass(word|wd)|secret|token|key|credential|auth|private/i';
    private const OPTION = '/^--?([A-Za-z0-9][A-Za-z0-9_.-]*)(=.*)?$/s';

    /**
     * @param list<string> $arguments
     * @return list<string>
     */
    public static function mask(array $arguments): array
    {
        $masked = [];
        $hideNext = false;
        foreach ($arguments as $argument) {
            if ($hideNext && ! str_starts_with($argument, '-')) {
                $masked[] = self::MASK;
                $hideNext = false;
                continue;
            }
            $hideNext = false;
            if (preg_match(self::OPTION, $argument, $m) === 1 && self::isSecret($m[1])) {
                if (isset($m[2])) {
                    $argument = substr($argument, 0, -strlen($m[2])) . '=' . self::MASK;
                } else {
                    $hideNext = true;
                }
            }
            $masked[] = $argument;
        }
        return $masked;
    }

    /** The command line as written, or rebuilt with masked values when it holds a secret-looking option. */
    public static function maskLine(string $line): string
    {
        try {
            $arguments = ArgumentSplitter::split($line);
        } catch (\InvalidArgumentException) {
            return self::maskUnsplittable($line);
        }
        $masked = self::mask($arguments);
        if ($masked === $arguments) {
            return $line;
        }
        return implode(' ', array_map(self::quote(...), $masked));
    }

    private static function isSecret(string $name): bool
    {
        return preg_match(self::SECRET_NAME, $name) === 1;
    }

    private static function quote(string $argument): string
    {
        if ($argument !== '' && preg_match('#^[A-Za-z0-9_\-.,:/=@%+*]+$#', $argument) === 1) {
            return $argument;
        }
        return "'" . str_replace("'", "'\\''", $argument) . "'";
    }

    /** A line with an unclosed quote is logged too (the command then fails): mask word by word. */
    private static function maskUnsplittable(string $line): string
    {
        return (string) preg_replace_callback(
            '/(?<=^|\s)(--?([A-Za-z0-9][A-Za-z0-9_.-]*))(=\S*|\s+(?!-)\S+)/',
            fn (array $m): string => self::isSecret($m[2])
                ? $m[1] . ($m[3][0] === '=' ? '=' : (string) preg_replace('/\S+$/', '', $m[3])) . self::MASK
                : $m[0],
            $line,
        );
    }
}
