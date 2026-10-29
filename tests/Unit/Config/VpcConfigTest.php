<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Config;

use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\Environment;
use Multiapi\Arco\Config\VpcConfig;
use PHPUnit\Framework\TestCase;

final class VpcConfigTest extends TestCase
{
    public function test_it_holds_the_subnets_and_security_groups(): void
    {
        $vpc = new VpcConfig(['subnet-a1'], ['sg-b2']);

        self::assertSame(['subnet-a1'], $vpc->subnets);
        self::assertSame(['sg-b2'], $vpc->securityGroups);
    }

    public function test_the_builder_stores_the_setting_and_false_clears_it(): void
    {
        $raw = App::make('shop')->vpc(['subnet-a1'], ['sg-b2'])->environment('local', fn (Environment $e) => $e->vpc(false))->toArray();

        self::assertSame(['subnets' => ['subnet-a1'], 'securityGroups' => ['sg-b2']], $raw['settings']['vpc']);
        self::assertFalse($raw['environments']['local']['settings']['vpc']);
    }

    public function test_the_builder_stores_endpoints(): void
    {
        $raw = App::make('shop')->vpc(['subnet-a1'], ['sg-b2'], endpoints: ['s3', 'dynamodb' => 'vpce-0abc'])->toArray();

        self::assertSame(['s3', 'dynamodb' => 'vpce-0abc'], $raw['settings']['vpc']['endpoints']);
    }

    public function test_it_holds_endpoints_and_maps_them_by_service(): void
    {
        $vpc = new VpcConfig(['subnet-a1'], ['sg-b2'], [['service' => 's3', 'id' => null], ['service' => 'dynamodb', 'id' => 'vpce-0abc']]);

        self::assertSame(['s3' => null, 'dynamodb' => 'vpce-0abc'], $vpc->endpointIds());
        self::assertSame([], (new VpcConfig(['subnet-a1'], ['sg-b2']))->endpoints);
    }
}
