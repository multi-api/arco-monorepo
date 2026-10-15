<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Secrets;

use Multiapi\Arco\Secrets\InvalidReference;
use Multiapi\Arco\Secrets\SecretReference;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecretReferenceTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>}> */
    public static function vectors(): iterable
    {
        $json = file_get_contents(__DIR__ . '/../../../packages/arco-secrets/test-vectors.json');
        self::assertIsString($json);
        /** @var list<array<string, mixed>> $vectors */
        $vectors = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        foreach ($vectors as $vector) {
            yield (string) $vector['input'] => [$vector];
        }
    }

    /** @param array<string, mixed> $vector */
    #[DataProvider('vectors')]
    public function test_vector(array $vector): void
    {
        $input = (string) $vector['input'];

        if ($vector['valid'] === false) {
            if (SecretReference::isReference($input)) {
                $this->expectException(InvalidReference::class);
                SecretReference::parse($input);
            } else {
                self::assertFalse(SecretReference::isReference($input));
            }
            return;
        }

        $reference = SecretReference::parse($input);
        self::assertSame($vector['source'], $reference->source->value);
        self::assertSame($vector['name'], $reference->name);
        self::assertSame($vector['jsonKey'], $reference->jsonKey);
        self::assertSame($input, $reference->toString());
    }

    public function test_parse_rejects_non_references(): void
    {
        $this->expectException(InvalidReference::class);
        SecretReference::parse('hello');
    }
}
