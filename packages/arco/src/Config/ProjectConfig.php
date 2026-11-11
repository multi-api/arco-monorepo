<?php declare(strict_types=1);

namespace Multiapi\Arco\Config;

/**
 * What the project root holds decides the runtime kind and where the configuration comes from:
 * arco.php (PHP), arco.json (process), a go.mod (process, configuration from the config layer).
 */
final class ProjectConfig
{
    /**
     * The raw configuration array ConfigResolver reads.
     *
     * @return array{raw: array<string, mixed>, hasArcoPhp: bool} hasArcoPhp: arco.php is the source (false for arco.json, go.mod and a layer alone)
     * @throws ConfigException config.both_files, config.not_found, config.json_*, config.layer_missing
     */
    public static function raw(string $projectDir, ?ConfigLayer $layer): array
    {
        $arcoPhp = $projectDir . '/arco.php';
        $arcoJson = $projectDir . '/arco.json';
        $hasGoMod = is_file($projectDir . '/go.mod');

        if (is_file($arcoPhp) && is_file($arcoJson)) {
            throw ConfigException::make('config.both_files');
        }
        if (is_file($arcoPhp)) {
            return ['raw' => (new ConfigLoader())->load($arcoPhp), 'hasArcoPhp' => true];
        }
        if (is_file($arcoJson)) {
            $raw = ArcoJson::load($arcoJson, $hasGoMod);
            foreach (['name', 'region'] as $key) {
                if ($raw[$key] === null && ($layer === null || $layer->$key === null)) {
                    throw ConfigException::make('config.json_missing', ['key' => $key]);
                }
            }
            return ['raw' => $raw, 'hasArcoPhp' => false];
        }
        if ($layer === null) {
            throw ConfigException::make('config.not_found', ['path' => $arcoPhp]);
        }
        $raw = ConfigResolver::layerOnly($layer);
        if ($hasGoMod) {
            $raw['process'] = ['language' => 'go'];
        }

        return ['raw' => $raw, 'hasArcoPhp' => false];
    }

    /** Whether the project is a process app: arco.json, or a go.mod without arco.php. File checks only. */
    public static function isProcess(string $projectDir): bool
    {
        return is_file($projectDir . '/arco.json')
            || (! is_file($projectDir . '/arco.php') && is_file($projectDir . '/go.mod'));
    }

    /** Whether the project root has a configuration file (arco.php or arco.json). */
    public static function hasFile(string $projectDir): bool
    {
        return is_file($projectDir . '/arco.php') || is_file($projectDir . '/arco.json');
    }
}
