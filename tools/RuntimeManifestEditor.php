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
     * binary assets' (FrankenPHP and, when present, the PHP CLI) size and digest must match what build.json claims.
     *
     * @param array<string, mixed> $release decoded `repos/<repo>/releases/tags/<tag>` response
     * @param array<string, mixed> $buildJson
     */
    public static function assertReleaseMatches(array $release, array $buildJson): void
    {
        if (($release['immutable'] ?? null) !== true) {
            throw new InvalidArgumentException('The release is not immutable; enable release immutability before adding the build.');
        }
        self::assertAssetMatches($release, 'frankenphp-linux-aarch64', $buildJson);
        $phpCli = self::phpCli($buildJson);
        if ($phpCli !== null) {
            self::assertAssetMatches($release, 'php-linux-aarch64', $phpCli);
        }
    }

    /**
     * @param array<string, mixed> $release
     * @param array<string, mixed> $expected the build.json (or php_cli) object holding size and sha256
     */
    private static function assertAssetMatches(array $release, string $name, array $expected): void
    {
        $asset = self::asset($release, $name);
        if (! isset($expected['size'], $expected['sha256']) || ($asset['size'] ?? null) !== $expected['size']) {
            throw new InvalidArgumentException("Release asset $name size does not match build.json.");
        }
        if (! is_string($expected['sha256']) || ($asset['digest'] ?? null) !== 'sha256:' . $expected['sha256']) {
            throw new InvalidArgumentException("Release asset $name digest does not match build.json sha256.");
        }
    }

    /**
     * @param array<string, mixed> $buildJson
     * @return array<string, mixed>|null
     */
    private static function phpCli(array $buildJson): ?array
    {
        $phpCli = $buildJson['php_cli'] ?? null;
        if ($phpCli === null) {
            return null;
        }
        if (! is_array($phpCli)) {
            throw new InvalidArgumentException('build.json php_cli must be an object.');
        }
        /** @var array<string, mixed> $phpCli */
        return $phpCli;
    }

    /**
     * @param array<string, mixed> $release
     * @return array<string, mixed>
     */
    private static function asset(array $release, string $name): array
    {
        foreach (is_array($release['assets'] ?? null) ? $release['assets'] : [] as $candidate) {
            if (is_array($candidate) && ($candidate['name'] ?? null) === $name) {
                /** @var array<string, mixed> $candidate */
                return $candidate;
            }
        }
        throw new InvalidArgumentException("The release has no $name asset.");
    }

    /**
     * Adds the build of a published release, under its Arco id php<php>-fp<frankenphp>-r<n> (the release
     * id `<frankenphp>-r<n>` stays the GitHub tag `frankenphp-<release id>`). The default build is then
     * the newest build of the manifest's default_php: a newer build of that PHP minor becomes the default,
     * a build of another minor never does. Aliases are kept as they are.
     *
     * @param array<string, mixed> $buildJson
     * @return string the Arco build id
     */
    public static function add(string $manifestPath, array $buildJson, string $repository): string
    {
        self::assertValidRepository($repository);
        $release = $buildJson['id'] ?? null;
        if (! is_string($release) || ! preg_match(RuntimeManifest::RELEASE_ID_PATTERN, $release, $m)) {
            throw new InvalidArgumentException('build.json has no valid "id" (a release id such as 1.13.0-r3).');
        }
        if (($buildJson['arch'] ?? null) !== 'linux-aarch64') {
            throw new InvalidArgumentException('build.json arch must be linux-aarch64.');
        }
        $php = $buildJson['php'] ?? null;
        if (! is_string($php) || ! preg_match('/^\d+\.\d+\.\d+$/D', $php)) {
            throw new InvalidArgumentException('build.json php must be a full PHP version such as 8.5.11.');
        }
        $id = RuntimeManifest::id($php, $m[1], (int) $m[2]);

        $data = require $manifestPath;
        if (! is_array($data) || ! is_array($data['builds'] ?? null)) {
            throw new InvalidArgumentException("$manifestPath is not a runtime manifest.");
        }
        /** @var array{builds: array<string, array<string, mixed>>, default_php?: string, aliases?: array<string, string>} $data */
        if (isset($data['builds'][$id])) {
            throw new InvalidArgumentException("Build $id is already in the manifest; publish a new revision instead.");
        }
        $base = "https://github.com/$repository/releases/download/frankenphp-$release";
        foreach ($data['builds'] as $existing => $entry) {
            if (($entry['url'] ?? null) === "$base/frankenphp-linux-aarch64") {
                throw new InvalidArgumentException("Release frankenphp-$release is already in the manifest as $existing.");
            }
        }

        $data['builds'][$id] = [
            'url' => "$base/frankenphp-linux-aarch64",
            'sha256' => $buildJson['sha256'] ?? null,
            'size' => $buildJson['size'] ?? null,
            'frankenphp' => $buildJson['frankenphp'] ?? null,
            'php' => $php,
        ];
        $phpCli = self::phpCli($buildJson);
        if ($phpCli !== null) {
            $data['builds'][$id]['php_cli'] = [
                'url' => "$base/php-linux-aarch64",
                'sha256' => $phpCli['sha256'] ?? null,
                'size' => $phpCli['size'] ?? null,
            ];
        }

        try {
            RuntimeManifest::fromArray($data);
        } catch (BinaryException $e) {
            throw new InvalidArgumentException('Invalid build: ' . ($e->params['reason'] ?? $e->key), 0, $e);
        }

        ksort($data['builds'], SORT_NATURAL);
        // Keys in a fixed order, so regenerated diffs stay small.
        $data = ['default_php' => $data['default_php'] ?? null, 'builds' => $data['builds'], 'aliases' => $data['aliases'] ?? []];
        $out = "<?php\n\n"
            . "// Runtime builds known to this Arco release. Generated with bin/runtime-manifest-add; do not edit by hand.\n"
            . "// Each build is an immutable release of github.com/$repository.\n"
            . "// The default build is the newest build of default_php; see docs/maintainers/runtime-builds.md.\n"
            . 'return ' . self::export($data) . ";\n";
        if (file_put_contents($manifestPath, $out) === false) {
            throw new RuntimeException("Could not write $manifestPath.");
        }
        return $id;
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
