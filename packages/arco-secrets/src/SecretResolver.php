<?php declare(strict_types=1);

namespace Multiapi\Arco\Secrets;

use Aws\SecretsManager\SecretsManagerClient;
use Aws\Ssm\SsmClient;
use JsonException;

final class SecretResolver
{
    public function __construct(
        private readonly SsmClient $ssm,
        private readonly SecretsManagerClient $secrets,
    ) {
    }

    /**
     * @param array<string, string> $variables
     * @return array<string, SecretReference>
     */
    public static function references(array $variables): array
    {
        $references = [];
        foreach ($variables as $key => $value) {
            if (SecretReference::isReference($value)) {
                $references[$key] = SecretReference::parse($value);
            }
        }
        return $references;
    }

    /**
     * @param array<string, string> $variables
     * @return array{resolved: array<string, string>, missing: list<string>}
     */
    public function resolve(array $variables): array
    {
        $references = self::references($variables);
        if ($references === []) {
            return ['resolved' => [], 'missing' => []];
        }

        $ssmValues = $this->fetchSsm($references);
        $secretValues = $this->fetchSecrets($references);

        $resolved = [];
        $missing = [];
        foreach ($references as $key => $reference) {
            $value = match ($reference->source) {
                SecretSource::Ssm => $ssmValues[$reference->name] ?? null,
                SecretSource::SecretsManager => $this->secretValue($secretValues, $reference),
            };
            if ($value === null) {
                $missing[] = $reference->toString();
            } else {
                $resolved[$key] = $value;
            }
        }

        return ['resolved' => $resolved, 'missing' => $missing];
    }

    /**
     * @param array<string, SecretReference> $references
     * @return array<string, string> parameter name => value
     */
    private function fetchSsm(array $references): array
    {
        $names = [];
        foreach ($references as $reference) {
            if ($reference->source === SecretSource::Ssm) {
                $names[$reference->name] = true;
            }
        }

        $values = [];
        foreach (array_chunk(array_keys($names), 10) as $chunk) {
            $result = $this->ssm->getParameters(['Names' => $chunk, 'WithDecryption' => true]);
            /** @var list<array{Name: string, Value: string}> $parameters */
            $parameters = $result['Parameters'] ?? [];
            foreach ($parameters as $parameter) {
                $values[$parameter['Name']] = $parameter['Value'];
            }
        }
        return $values;
    }

    /**
     * @param array<string, SecretReference> $references
     * @return list<array{ARN: string, Name: string, SecretString?: string}>
     */
    private function fetchSecrets(array $references): array
    {
        $ids = [];
        foreach ($references as $reference) {
            if ($reference->source === SecretSource::SecretsManager) {
                $ids[$reference->name] = true;
            }
        }

        $values = [];
        foreach (array_chunk(array_keys($ids), 20) as $chunk) {
            $result = $this->secrets->batchGetSecretValue(['SecretIdList' => $chunk]);
            /** @var list<array{ARN: string, Name: string, SecretString?: string}> $secretValues */
            $secretValues = $result['SecretValues'] ?? [];
            array_push($values, ...$secretValues);
        }
        return $values;
    }

    /** @param list<array{ARN: string, Name: string, SecretString?: string}> $secretValues */
    private function secretValue(array $secretValues, SecretReference $reference): ?string
    {
        foreach ($secretValues as $secret) {
            if ($secret['Name'] !== $reference->name && $secret['ARN'] !== $reference->name) {
                continue;
            }
            $string = $secret['SecretString'] ?? null;
            if ($string === null || $reference->jsonKey === null) {
                return $string;
            }
            try {
                $decoded = json_decode($string, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return null;
            }
            if (! is_array($decoded) || ! array_key_exists($reference->jsonKey, $decoded)) {
                return null;
            }
            return self::stringify($decoded[$reference->jsonKey]);
        }
        return null;
    }

    private static function stringify(mixed $value): ?string
    {
        return match (true) {
            is_string($value) => $value,
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            default => null,
        };
    }
}
