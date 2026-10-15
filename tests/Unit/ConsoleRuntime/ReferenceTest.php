<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\ConsoleRuntime;

use Multiapi\Arco\ConsoleRuntime\Reference;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Same vectors as arco-secrets (PHP) and the web runtime (Node): the three must agree. */
final class ReferenceTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>}> */
    public static function vectors(): iterable
    {
        /** @var list<array<string, mixed>> $vectors */
        $vectors = json_decode((string) file_get_contents(__DIR__ . '/../../../packages/arco-secrets/test-vectors.json'), true, flags: JSON_THROW_ON_ERROR);
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
            if (Reference::isReference($input)) {
                $this->expectException(\InvalidArgumentException::class);
                Reference::parse($input);
            } else {
                self::assertFalse(Reference::isReference($input));
            }
            return;
        }
        $reference = Reference::parse($input);
        self::assertSame($vector['source'], $reference->source);
        self::assertSame($vector['name'], $reference->name);
        self::assertSame($vector['jsonKey'], $reference->jsonKey);
    }
}
