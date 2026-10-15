<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit;

use Multiapi\Arco\Arco;
use PHPUnit\Framework\TestCase;

final class ArcoTest extends TestCase
{
    public function test_constants_match_the_spec(): void
    {
        self::assertSame('nodejs24.x', Arco::LAMBDA_RUNTIME);
        self::assertSame(['us-east-1', 'us-east-2', 'us-west-2', 'ap-northeast-1', 'eu-west-1'], Arco::REGIONS);
        self::assertSame(512 * 1024 * 1024, Arco::MAX_PACKAGE_BYTES);
    }
}
