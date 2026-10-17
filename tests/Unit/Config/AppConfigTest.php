<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Config;

use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\QueueConfig;
use Multiapi\Arco\Config\RuntimeConfig;
use Multiapi\Arco\Config\StorageConfig;
use Multiapi\Arco\Config\TableConfig;
use PHPUnit\Framework\TestCase;

final class AppConfigTest extends TestCase
{
    /** A value for each constructor parameter that differs from its default, so a dropped field shows. */
    private static function sample(\ReflectionParameter $parameter): mixed
    {
        $type = $parameter->getType();
        $names = $type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType
            ? array_map(fn (\ReflectionType $t) => $t instanceof \ReflectionNamedType ? $t->getName() : '', $type->getTypes())
            : [$type instanceof \ReflectionNamedType ? $type->getName() : 'mixed'];
        return match (true) {
            in_array(RuntimeConfig::class, $names, true) => (new \ReflectionClass(RuntimeConfig::class))->newInstanceWithoutConstructor(),
            in_array(TableConfig::class, $names, true) => new TableConfig('cache', existing: 'sample-' . $parameter->getName()),
            in_array('array', $names, true) => match ($parameter->getName()) {
                'queues' => [new QueueConfig('emails')],
                'storage' => [new StorageConfig('files')],
                default => ['sample-' . $parameter->getName() => 'x'],
            },
            in_array('int', $names, true) => 7 + $parameter->getPosition(),
            in_array('bool', $names, true) => ! (bool) ($parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : false),
            default => 'sample-' . $parameter->getName(),
        };
    }

    public function test_with_account_keeps_every_property_but_the_account(): void
    {
        $constructor = (new \ReflectionClass(AppConfig::class))->getConstructor();
        self::assertNotNull($constructor);
        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $arguments[$parameter->getName()] = self::sample($parameter);
        }
        $config = new AppConfig(...$arguments);

        $copy = $config->withAccount('999999999999');

        self::assertSame('999999999999', $copy->account);
        foreach ((new \ReflectionClass(AppConfig::class))->getProperties() as $property) {
            if ($property->getName() === 'account' || $property->isStatic()) {
                continue;
            }
            self::assertSame($property->getValue($config), $property->getValue($copy), "withAccount() dropped {$property->getName()}");
        }
    }
}
