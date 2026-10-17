<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands\Concerns;

use Multiapi\Arco\Cli\UserError;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ArcoFileEditor;
use Multiapi\Arco\Config\ConfigException;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Config\QueueConfig;
use Multiapi\Arco\Config\StorageConfig;
use Multiapi\Arco\Config\Validator;

/**
 * Shared by the commands that edit calls keyed by a name, such as ->queue('<name>', ...) and
 * ->storage('<name>', ...): the calls of one level, where a name is configured, validation and the
 * arco.php edit. The using trait names the call through resource().
 *
 * @phpstan-import-type Option from ArcoFileEditor
 */
trait EditsNamedResources
{
    use EditsSettings;

    /**
     * The call this command edits: its method, the raw config key it loads into, and the validation
     * error keys (or key prefixes) that concern it.
     *
     * @return array{method: 'queue'|'storage', key: string, errors: list<string>}
     */
    abstract private static function resource(): array;

    /**
     * The resolved entries of this call in one environment.
     *
     * @return list<QueueConfig|StorageConfig>
     */
    abstract private static function resolvedEntries(AppConfig $config): array;

    /**
     * The calls written at exactly this level: options, or false for an entry dropped there.
     *
     * @param array<string, mixed> $raw
     * @return array<string, array<string, Option>|false>
     */
    private static function levelNamed(array $raw, ?string $environment): array
    {
        $level = $raw;
        if ($environment !== null) {
            $environments = $raw['environments'] ?? null;
            $level = is_array($environments) && is_array($environments[$environment] ?? null) ? $environments[$environment] : [];
        }
        $entries = $level[self::resource()['key']] ?? null;
        $result = [];
        foreach (is_array($entries) ? $entries : [] as $name => $options) {
            if ($options === false) {
                $result[(string) $name] = false;
            } elseif (is_array($options)) {
                $clean = [];
                foreach ($options as $key => $value) {
                    if (is_int($value) || is_bool($value) || is_string($value)) {
                        $clean[(string) $key] = $value;
                    } elseif (is_array($value)) {
                        $clean[(string) $key] = array_filter($value, static fn (mixed $item): bool => is_int($item) || is_string($item));
                    }
                }
                $result[(string) $name] = $clean;
            }
        }
        return $result;
    }

    /**
     * The raw config with the call at this level replaced (null removes it).
     *
     * @param array<string, mixed> $raw
     * @param array<string, Option>|false|null $options
     * @return array<string, mixed>
     */
    private static function withNamed(array $raw, ?string $environment, string $name, array|false|null $options): array
    {
        $key = self::resource()['key'];
        $entries = self::levelNamed($raw, $environment);
        if ($options === null) {
            unset($entries[$name]);
        } else {
            $entries[$name] = $options;
        }
        if ($environment === null) {
            $raw[$key] = $entries;
            return $raw;
        }
        $environments = is_array($raw['environments'] ?? null) ? $raw['environments'] : [];
        $env = is_array($environments[$environment] ?? null) ? $environments[$environment] : [];
        $env[$key] = $entries;
        $environments[$environment] = $env;
        $raw['environments'] = $environments;
        return $raw;
    }

    /**
     * The environments a change at this level reaches.
     *
     * @param array<string, mixed> $raw
     * @return list<string>
     */
    private static function affectedEnvironments(array $raw, ?string $environment): array
    {
        return $environment === null ? ConfigResolver::environments($raw) : [$environment];
    }

    /** @param array<string, mixed> $raw */
    private static function resolvedNamed(array $raw, string $environment, string $name): QueueConfig|StorageConfig|null
    {
        foreach (self::resolvedEntries(ConfigResolver::resolve($raw, $environment)) as $entry) {
            if ($entry->name === $name) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * The entry as the app level alone defines it: every environment's own call for it is ignored, so an
     * environment override never hides what a change at the app level does.
     *
     * @param array<string, mixed> $raw
     */
    private static function appLevelNamed(array $raw, string $name): QueueConfig|StorageConfig|null
    {
        $environments = ConfigResolver::environments($raw);
        if (! is_array(self::levelNamed($raw, null)[$name] ?? null) || $environments === []) {
            return null;
        }
        foreach ($environments as $env) {
            $raw = self::withNamed($raw, $env, $name, null);
        }
        return self::resolvedNamed($raw, $environments[0], $name);
    }

    /**
     * Where the entry is already configured: the target level, and for the app level also every
     * environment. Returns the environment name, null for the app level, or false when it is nowhere.
     *
     * @param array<string, mixed> $raw
     */
    private static function existsAt(array $raw, ?string $environment, string $name): string|false|null
    {
        if ($environment !== null) {
            return self::resolvedNamed($raw, $environment, $name) !== null ? $environment : false;
        }
        if (is_array(self::levelNamed($raw, null)[$name] ?? null)) {
            return null;
        }
        foreach (ConfigResolver::environments($raw) as $env) {
            if (self::resolvedNamed($raw, $env, $name) !== null) {
                return $env;
            }
        }
        return false;
    }

    /**
     * Stops with the errors about this call the change would cause (or that the validator finds), so arco.php stays as it is.
     *
     * @param array<string, mixed> $changed
     */
    private function assertValid(array $changed, ?string $environment): void
    {
        $messages = [];
        foreach (self::affectedEnvironments($changed, $environment) as $env) {
            $result = (new Validator())->validate(ConfigResolver::resolve($changed, $env), $this->projectDir());
            foreach ($result->errors as $error) {
                foreach (self::resource()['errors'] as $prefix) {
                    if (str_starts_with($error['key'], $prefix)) {
                        $messages[$this->t($error['key'], $error['params'])] = true;
                    }
                }
            }
        }
        if ($messages !== []) {
            throw new UserError(implode("\n", array_map(static fn (string $m): string => '• ' . $m, array_keys($messages))));
        }
    }

    /**
     * Writes the call (false drops an inherited entry, null removes the call), or explains the line to write by hand.
     *
     * @param array<string, Option>|false|null $options
     */
    private function writeNamed(?string $environment, string $name, array|false|null $options): void
    {
        $method = self::resource()['method'];
        $byHand = $this->t('settings.edit_by_hand', ['where' => $this->where($environment)]) . "\n    "
            . ($options === null ? $this->t('settings.remove_call', ['method' => $method]) : ArcoFileEditor::namedLine($method, $name, $options));
        try {
            $written = ArcoFileEditor::setNamed($this->arcoPhp(), $environment, $method, $name, $options);
        } catch (ConfigException $e) {
            throw new UserError($this->t($e->key, $e->params) . "\n" . $byHand);
        }
        if (! $written) {
            throw new UserError($byHand);
        }
    }

    /** @param array<string, Option>|false $options */
    private static function namedLine(string $name, array|false $options): string
    {
        return ArcoFileEditor::namedLine(self::resource()['method'], $name, $options);
    }
}
