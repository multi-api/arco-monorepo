<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands\Concerns;

use Multiapi\Arco\Cli\UserError;
use Multiapi\Arco\Config\ArcoFileEditor;
use Multiapi\Arco\Config\ConfigException;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Config\QueueConfig;
use Multiapi\Arco\Config\Validator;

/** Shared by queue:add, queue:set and queue:remove: the queue() calls of one level, validation and the arco.php edit. */
trait EditsQueues
{
    use EditsSettings;

    /**
     * The queue() calls written at exactly this level: options, or false for a queue dropped there.
     *
     * @param array<string, mixed> $raw
     * @return array<string, array<string, int|bool|string>|false>
     */
    private static function levelQueues(array $raw, ?string $environment): array
    {
        $level = $raw;
        if ($environment !== null) {
            $environments = $raw['environments'] ?? null;
            $level = is_array($environments) && is_array($environments[$environment] ?? null) ? $environments[$environment] : [];
        }
        $queues = $level['queues'] ?? null;
        $result = [];
        foreach (is_array($queues) ? $queues : [] as $name => $options) {
            if ($options === false) {
                $result[(string) $name] = false;
            } elseif (is_array($options)) {
                $clean = [];
                foreach ($options as $key => $value) {
                    if (is_int($value) || is_bool($value) || is_string($value)) {
                        $clean[(string) $key] = $value;
                    }
                }
                $result[(string) $name] = $clean;
            }
        }
        return $result;
    }

    /**
     * The raw config with the queue() call at this level replaced (null removes it).
     *
     * @param array<string, mixed> $raw
     * @param array<string, int|bool|string>|false|null $options
     * @return array<string, mixed>
     */
    private static function withQueue(array $raw, ?string $environment, string $name, array|false|null $options): array
    {
        $queues = self::levelQueues($raw, $environment);
        if ($options === null) {
            unset($queues[$name]);
        } else {
            $queues[$name] = $options;
        }
        if ($environment === null) {
            $raw['queues'] = $queues;
            return $raw;
        }
        $environments = is_array($raw['environments'] ?? null) ? $raw['environments'] : [];
        $env = is_array($environments[$environment] ?? null) ? $environments[$environment] : [];
        $env['queues'] = $queues;
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
    private static function resolvedQueue(array $raw, ?string $environment, string $name): ?QueueConfig
    {
        foreach (self::affectedEnvironments($raw, $environment) as $env) {
            foreach (ConfigResolver::resolve($raw, $env)->queues as $queue) {
                if ($queue->name === $name) {
                    return $queue;
                }
            }
            return null;
        }
        return null;
    }

    /**
     * Stops with the queue errors the change would cause (or that the validator finds), so arco.php stays as it is.
     *
     * @param array<string, mixed> $changed
     */
    private function assertValid(array $changed, ?string $environment): void
    {
        $messages = [];
        foreach (self::affectedEnvironments($changed, $environment) as $env) {
            $result = (new Validator())->validate(ConfigResolver::resolve($changed, $env), $this->projectDir());
            foreach ($result->errors as $error) {
                if (str_starts_with($error['key'], 'validation.queue') || $error['key'] === 'validation.worker_name_length') {
                    $messages[$this->t($error['key'], $error['params'])] = true;
                }
            }
        }
        if ($messages !== []) {
            throw new UserError(implode("\n", array_map(static fn (string $m): string => '• ' . $m, array_keys($messages))));
        }
    }

    /**
     * Writes the queue() call (false drops an inherited queue, null removes the call), or explains the line to write by hand.
     *
     * @param array<string, int|bool|string>|false|null $options
     */
    private function writeQueue(?string $environment, string $name, array|false|null $options): void
    {
        $byHand = $this->t('settings.edit_by_hand', ['where' => $this->where($environment)]) . "\n    "
            . ($options === null ? $this->t('settings.remove_call', ['method' => 'queue']) : ArcoFileEditor::queueLine($name, $options));
        try {
            $written = ArcoFileEditor::setQueue($this->arcoPhp(), $environment, $name, $options);
        } catch (ConfigException $e) {
            throw new UserError($this->t($e->key, $e->params) . "\n" . $byHand);
        }
        if (! $written) {
            throw new UserError($byHand);
        }
    }

    private function queueName(\Symfony\Component\Console\Input\InputInterface $input): string
    {
        return $this->argument($input, 'name');
    }
}
