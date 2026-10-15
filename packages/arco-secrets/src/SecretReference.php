<?php declare(strict_types=1);

namespace Multiapi\Arco\Secrets;

final readonly class SecretReference
{
    public const SSM_PREFIX = 'arco-ssm:';
    public const SECRET_PREFIX = 'arco-secret:';

    private function __construct(
        public SecretSource $source,
        public string $name,
        public ?string $jsonKey,
    ) {
    }

    public static function isReference(string $value): bool
    {
        return str_starts_with($value, self::SSM_PREFIX) || str_starts_with($value, self::SECRET_PREFIX);
    }

    public static function parse(string $value): self
    {
        if (str_starts_with($value, self::SSM_PREFIX)) {
            $name = substr($value, strlen(self::SSM_PREFIX));
            if ($name === '') {
                throw InvalidReference::malformed($value, 'the parameter name is empty.');
            }
            if (str_contains($name, '#')) {
                throw InvalidReference::malformed($value, '"#key" is only supported for arco-secret: references.');
            }
            return new self(SecretSource::Ssm, $name, null);
        }

        if (str_starts_with($value, self::SECRET_PREFIX)) {
            $rest = substr($value, strlen(self::SECRET_PREFIX));
            $parts = explode('#', $rest, 2);
            $id = $parts[0];
            $key = $parts[1] ?? null;
            if ($id === '') {
                throw InvalidReference::malformed($value, 'the secret id is empty.');
            }
            if ($key === '') {
                throw InvalidReference::malformed($value, 'the JSON key after "#" is empty.');
            }
            return new self(SecretSource::SecretsManager, $id, $key);
        }

        throw InvalidReference::notAReference($value);
    }

    public function toString(): string
    {
        return match ($this->source) {
            SecretSource::Ssm => self::SSM_PREFIX . $this->name,
            SecretSource::SecretsManager => self::SECRET_PREFIX . $this->name . ($this->jsonKey !== null ? '#' . $this->jsonKey : ''),
        };
    }
}
