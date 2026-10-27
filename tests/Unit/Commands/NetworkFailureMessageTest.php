<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Commands;

use Multiapi\Arco\Cli\Application;
use Multiapi\Arco\Cli\ArcoCommand;
use Multiapi\Arco\Commands\Concerns\ResolvesTarget;
use Multiapi\Arco\Deploy\DeployException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\ApplicationTester;

final class NetworkFailureMessageTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, string}> */
    public static function failures(): iterable
    {
        yield 'known code, en' => ['en', 'SubnetOutOfIPAddresses', 'raw aws text', 'a subnet has no free IP addresses'];
        yield 'known code, es' => ['es', 'SubnetOutOfIPAddresses', 'raw aws text', 'una subnet no tiene direcciones IP libres'];
        yield 'unknown code keeps the AWS text' => ['en', 'SomethingNew', 'raw aws text', 'raw aws text'];
        yield 'no code keeps the AWS text' => ['es', '', 'raw aws text', 'raw aws text'];
    }

    #[DataProvider('failures')]
    public function test_a_failed_network_connector_translates_its_reason_code(string $lang, string $code, string $aws, string $expected): void
    {
        $command = new class (DeployException::make('deploy.network_failed', ['connector' => 'shop-production', 'reason' => $aws, 'code' => $code])) extends ArcoCommand {
            use ResolvesTarget;

            public function __construct(private readonly \Throwable $error)
            {
                parent::__construct('net-failure');
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                $output->writeln((string) $this->describeError($this->error));
                return self::SUCCESS;
            }
        };
        $app = Application::create();
        $app->addCommands([$command]);
        $app->setAutoExit(false);
        $tester = new ApplicationTester($app);

        $tester->run(['command' => 'net-failure', '--lang' => $lang]);

        $display = $tester->getDisplay();
        self::assertStringContainsString('shop-production', $display);
        self::assertStringContainsString($expected, $display);
        if ($expected !== $aws) {
            self::assertStringNotContainsString($aws, $display);
        }
    }
}
