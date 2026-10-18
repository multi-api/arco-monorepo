<?php declare(strict_types=1);

namespace Multiapi\Arco\Build;

use Multiapi\Arco\Config\AppConfig;

final class Builder
{
    /** `storage/framework/down` is Laravel's maintenance flag: shipping it would take the app down. */
    public const ALWAYS_EXCLUDED = [
        '.git/**', '.arco/**', 'node_modules/**', 'vendor/**', 'storage/framework/down',
        // Editor, CI and agent folders: never needed at runtime and often large.
        '.idea/**', '.vscode/**', '.fleet/**', '.zed/**', '.github/**', '**/.DS_Store',
        // AI assistant config and context (Claude, Cursor, Junie, Windsurf, Gemini, Codex, Aider, MCP).
        '.claude/**', '.superpowers/**', '.ai/**', '.junie/**', '.cursor/**', '.windsurf/**', '.gemini/**', '.codex/**',
        '.aider*', '.mcp.json',
        // Packages written by `arco package` at the project root (its old default output).
        'arco-*.zip',
    ];

    /** Secrets that are never packaged, at any depth; `include` patterns cannot override these. */
    public const NEVER_PACKAGED = ['.env', '.env.*', '**/.env', '**/.env.*'];

    /** Copied for `composer install` (private repositories) and deleted before packaging. */
    public const BUILD_ONLY = ['auth.json'];

    public function __construct(private readonly CommandRunner $runner, private readonly string $composer = 'composer')
    {
    }

    /**
     * @param (callable(string, array<string, string|int>): void)|null $onStep told before each phase starts
     * @param list<string> $exclude more patterns to leave out, such as the package's own output path
     */
    public function build(AppConfig $config, FrameworkPreset $preset, string $projectDir, ?callable $onStep = null, array $exclude = []): BuildResult
    {
        $step = $onStep ?? static function (string $key, array $params = []): void {
        };
        $staging = sys_get_temp_dir() . '/arco-build-' . bin2hex(random_bytes(6));
        $appDir = "$staging/app";
        mkdir($appDir, 0777, true);

        $matcher = new PathMatcher(
            [...self::ALWAYS_EXCLUDED, ...$preset->excludeRules(), ...$config->exclude, ...$exclude],
            $config->include,
            self::NEVER_PACKAGED,
        );
        $step('build.copying', []);
        $skipped = ProjectCopier::copy($projectDir, $appDir, $matcher);
        $result = new BuildResult($staging, $appDir, $skipped);

        try {
            if (is_file("$appDir/composer.json")) {
                self::absolutizePathRepositories($projectDir, $appDir);
                $step('build.composer', []);
                $this->runner->run(
                    [$this->composer, 'install', '--no-dev', '--optimize-autoloader', '--no-interaction', '--no-progress'],
                    $appDir,
                );
            }
            foreach (self::BUILD_ONLY as $file) {
                if (is_file("$appDir/$file")) {
                    unlink("$appDir/$file");
                }
            }
            $preset->prepare($appDir);
            foreach ($preset->buildSteps() as $command) {
                $step('build.step', ['command' => implode(' ', $command)]);
                $this->runner->run($command, $appDir, ['ARCO_BUILD' => '1']);
            }
        } catch (BuildException $e) {
            $result->cleanup();
            throw $e;
        }

        return $result;
    }

    /**
     * The staged copy lives in a temporary directory, so relative `path` repositories (monorepos) would no longer
     * resolve. Rewrites them to absolute paths in the staged composer.json and composer.lock only.
     */
    private static function absolutizePathRepositories(string $projectDir, string $appDir): void
    {
        $json = self::readJson("$appDir/composer.json");
        $repositories = $json['repositories'] ?? null;
        if ($json !== null && is_array($repositories)) {
            $changed = false;
            foreach ($repositories as $key => $repository) {
                if (is_array($repository) && ($repository['type'] ?? null) === 'path' && is_string($repository['url'] ?? null)) {
                    $url = self::absolutePath($projectDir, $repository['url']);
                    if ($url !== $repository['url']) {
                        $repository['url'] = $url;
                        $repositories[$key] = $repository;
                        $changed = true;
                    }
                }
            }
            if ($changed) {
                $json['repositories'] = $repositories;
                self::writeJson("$appDir/composer.json", $json);
            }
        }

        $lock = self::readJson("$appDir/composer.lock");
        if ($lock === null) {
            return;
        }
        $changed = false;
        foreach (['packages', 'packages-dev'] as $section) {
            $packages = $lock[$section] ?? null;
            if (! is_array($packages)) {
                continue;
            }
            foreach ($packages as $i => $package) {
                $dist = is_array($package) ? ($package['dist'] ?? null) : null;
                if (is_array($package) && is_array($dist) && ($dist['type'] ?? null) === 'path' && is_string($dist['url'] ?? null)) {
                    $url = self::absolutePath($projectDir, $dist['url']);
                    if ($url !== $dist['url']) {
                        $dist['url'] = $url;
                        $package['dist'] = $dist;
                        $packages[$i] = $package;
                        $changed = true;
                    }
                }
            }
            $lock[$section] = $packages;
        }
        if ($changed) {
            self::writeJson("$appDir/composer.lock", $lock);
        }
    }

    /** @return array<mixed>|null */
    private static function readJson(string $file): ?array
    {
        $contents = is_file($file) ? file_get_contents($file) : false;
        $data = $contents === false ? null : json_decode($contents, true);
        return is_array($data) ? $data : null;
    }

    /** @param array<mixed> $data */
    private static function writeJson(string $file, array $data): void
    {
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }

    /** Absolute paths, `~` paths, URLs and Windows drives stay as they are; the rest resolves against $projectDir. */
    private static function absolutePath(string $projectDir, string $url): string
    {
        if ($url === '' || $url[0] === '/' || $url[0] === '~' || $url[0] === '\\' || preg_match('#^[A-Za-z][A-Za-z0-9+.-]*:#', $url) === 1) {
            return $url;
        }
        $segments = [];
        foreach (explode('/', rtrim($projectDir, '/') . '/' . $url) as $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '.' && $segment !== '') {
                $segments[] = $segment;
            }
        }
        return '/' . implode('/', $segments);
    }
}
