<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy;

use Aws\Command;
use Multiapi\Arco\Deploy\UploadProgress;
use PHPUnit\Framework\TestCase;

final class UploadProgressTest extends TestCase
{
    public function test_megabytes_have_one_decimal_under_10(): void
    {
        self::assertSame('0.0', UploadProgress::megabytes(3));
        self::assertSame('0.5', UploadProgress::megabytes(524288));
        self::assertSame('9.5', UploadProgress::megabytes(9961472));
        self::assertSame('10', UploadProgress::megabytes(10485760));
        self::assertSame('181', UploadProgress::megabytes(189267968));
    }

    public function test_a_failing_callback_never_breaks_the_upload_and_is_not_called_again(): void
    {
        $calls = 0;
        $progress = new UploadProgress(100, function () use (&$calls): void {
            $calls++;
            throw new \RuntimeException('broken output');
        });
        $command = new Command('PutObject');
        $progress->attach($command);

        $command['@http']['progress'](0, 0, 100, 40);
        $command['@http']['progress'](0, 0, 100, 80);
        $progress->finish();

        self::assertSame(1, $calls);
    }
}
