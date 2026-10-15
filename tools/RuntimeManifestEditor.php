<?php declare(strict_types=1);

namespace Multiapi\Arco\Tools;

use InvalidArgumentException;
use Multiapi\Arco\Binary\BinaryException;
use Multiapi\Arco\Binary\RuntimeManifest;
use RuntimeException;

/** Maintainer tool: adds a published runtime build (its build.json) to the shipped manifest. */
final class RuntimeManifestEditor
{
    public const REPOSITORY_PATTERN = '/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+\z/';

    public static function assertValidRepository(string $repository): void
    {
        if (! preg_match(self::REPOSITORY_PATTERN, $repository)) {
            throw new InvalidArgumentException('Invalid --repo: expected "owner/name" using letters, digits, "_", "." and "-".');
        }
    }

    /**
     * Cross-checks the GitHub release metadata against build.json: the release must be immutable and the
     * binary asset's size and digest must match what build.json claims.
     *
     * @param array<string, mixed> $release decoded `repos/<repo>/releases/tags/<tag>` response
     * @param array<string, mixed> $buildJson
     */
    public static function assertReleaseMatches(array $release, array $buildJson): void
    {
        if (($release['immutable'] ?? null) !== true) {
            throw new InvalidArgumentException('The release is not immutable; enable release immutability before adding the build.');
        }
        $asset = null;
        foreach (is_array($release['assets'] ?? null) ? $release['assets'] : [] as $candidate) {
            if (is_array($candidate) && ($candidate['name'] ?? null) === 'frankenphp-linux-aarch64') {
                $asset = $candidate;
                break;
            }
        }
        if ($asset === null) {
            throw new InvalidArgumentException('The release has no frankenphp-linux-aarch64 asset.');
        }
        if (! isset($buildJson['size'], $buildJson['sha256']) || ($asset['size'] ?? null) !== $buildJson['size']) {
            throw new InvalidArgumentException('Release asset size does not match build.json.');
        }
        if (! is_string($buildJson['sha256']) || ($asset['digest'] ?? null) !== 'sha256:' . $buildJson['sha256']) {
            throw new InvalidArgumentException('Release asset digest does not match build.json sha256.');
        }
    }

    /** @param array<string, mixed> $buildJson */
    public static function add(string $manifestPath, array $buildJson, string $repository, bool $setDefault): void
    {
        self::assertValidRepository($repository);
        $id = $buildJson['id'] ?? null;
        if (! is_string($id) || ! preg_match(RuntimeManifest::ID_PATTERN, $id)) {
            throw new InvalidArgumentException('build.json has no valid "id".');
        }
        if (($buildJson['arch'] ?? null) !== 'linux-aarch64') {
            throw new InvalidArgumentException('build.json arch must be linux-aarch64.');
        }

        $data = require $manifestPath;
        if (! is_array($data) || ! is_array($data['builds'] ?? null)) {
            throw new InvalidArgumentException("$manifestPath is not a runtime manifest.");
        }
        /** @var array{builds: array<string, mixed>, default?: string, ...} $data */
        if (isset($data['builds'][$id])) {
            throw new InvalidArgumentException("Build $id is already in the manifest; publish a new revision instead.");
        }

        $data['builds'][$id] = [
            'url' => "https://github.com/$repository/releases/download/frankenphp-$id/frankenphp-linux-aarch64",
            'sha256' => $buildJson['sha256'] ?? null,
            'size' => $buildJson['size'] ?? null,
            'frankenphp' => $buildJson['frankenphp'] ?? null,
            'php' => $buildJson['php'] ?? null,
        ];
        if ($setDefault) {
            $data['default'] = $id;
        }

        try {
            RuntimeManifest::fromArray($data);
        } catch (BinaryException $e) {
            throw new InvalidArgumentException('Invalid build: ' . ($e->params['reason'] ?? $e->key), 0, $e);
        }

        ksort($data['builds'], SORT_NATURAL);
        $php = "<?php\n\n"
            . "// Runtime builds known to this Arco release. Generated with bin/runtime-manifest-add; do not edit by hand.\n"
            . "// Each build is an immutable release of github.com/$repository.\n"
            . 'return ' . self::export($data) . ";\n";
        if (file_put_contents($manifestPath, $php) === false) {
            throw new RuntimeException("Could not write $manifestPath.");
        }
    }

    /**
     * Renders data in the same short-array style as the shipped manifest, so regenerated diffs stay small.
     *
     * @param array<array-key, mixed> $data
     */
    private static function export(array $data, int $depth = 0): string
    {
        if ($data === []) {
            return '[]';
        }
        $pad = str_repeat('    ', $depth);
        $lines = ['['];
        foreach ($data as $key => $value) {
            $prefix = is_int($key) ? '' : var_export($key, true) . ' => ';
            $rendered = is_array($value) ? self::export($value, $depth + 1) : var_export($value, true);
            $lines[] = "$pad    $prefix$rendered,";
        }
        $lines[] = "$pad]";
        return implode("\n", $lines);
    }
}
