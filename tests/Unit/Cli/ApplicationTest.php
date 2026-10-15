<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Cli;

use Multiapi\Arco\Cli\Application;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\ApplicationTester;

final class ApplicationTest extends TestCase
{
    public function test_lists_with_version_and_lang_option(): void
    {
        $app = Application::create();
        $app->setAutoExit(false);
        $tester = new ApplicationTester($app);

        $tester->run(['command' => 'list']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Arco', $tester->getDisplay());
        self::assertStringContainsString('--lang', $tester->getDisplay());
    }
}
