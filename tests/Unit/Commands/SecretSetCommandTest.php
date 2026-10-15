<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Commands;

use Aws\MockHandler;
use Aws\Result;
use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Commands\SecretSetCommand;
use PHPUnit\Framework\TestCase;

final class SecretSetCommandTest extends TestCase
{
    public function test_stores_a_secure_string_from_stdin(): void
    {
        $project = new ProjectFixture(ProjectFixture::standardConfig());
        $params = [];
        $mock = new MockHandler([new Result(['Account' => '111111111111']), new Result(['Version' => 1])]);
        $handler = function (\Aws\CommandInterface $c, \Psr\Http\Message\RequestInterface $r) use ($mock, &$params) {
            $params[$c->getName()] = $c->toArray();
            return $mock($c, $r);
        };
        $tester = $project->tester(new SecretSetCommand(
            fn (?string $profile, string $region) => new AwsClients($profile, $region, ['handler' => $handler, 'credentials' => false]),
        ));
        $tester->setInputs(["base64:abc==\n"]);

        $tester->execute(['name' => 'app-key', '--env' => 'production', '--profile' => 'p'], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        self::assertSame('/shop/production/app-key', $params['PutParameter']['Name']);
        self::assertSame('base64:abc==', $params['PutParameter']['Value']);
        self::assertSame('SecureString', $params['PutParameter']['Type']);
        self::assertTrue($params['PutParameter']['Overwrite']);
        self::assertStringContainsString('arco-ssm:/shop/production/app-key', $tester->getDisplay());
        $project->restore();
    }

    public function test_rejects_invalid_names_and_empty_values(): void
    {
        $project = new ProjectFixture(ProjectFixture::standardConfig());
        $mock = new MockHandler([new Result(['Account' => '111111111111'])]);
        $factory = fn (?string $profile, string $region) => new AwsClients($profile, $region, ['handler' => $mock, 'credentials' => false]);

        $tester = $project->tester(new SecretSetCommand($factory));
        $tester->execute(['name' => 'bad name', '--env' => 'production', '--profile' => 'p'], ['interactive' => false]);
        self::assertSame(1, $tester->getStatusCode());

        $tester = $project->tester(new SecretSetCommand($factory));
        $tester->setInputs(["\n"]);
        $tester->execute(['name' => 'app-key', '--env' => 'production', '--profile' => 'p'], ['interactive' => false]);
        self::assertSame(1, $tester->getStatusCode());
        $project->restore();
    }
}
