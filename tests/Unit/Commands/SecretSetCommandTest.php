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
        $mock = new MockHandler([new Result(['Account' => '111111111111']), new Result(['Version' => 1]), new Result([])]);
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
        // PutParameter cannot tag on overwrite: the tags follow in their own call, new or overwritten.
        self::assertArrayNotHasKey('Tags', $params['PutParameter']);
        self::assertSame('Parameter', $params['AddTagsToResource']['ResourceType']);
        self::assertSame('/shop/production/app-key', $params['AddTagsToResource']['ResourceId']);
        self::assertSame([['Key' => 'arco:app', 'Value' => 'shop'], ['Key' => 'arco:environment', 'Value' => 'production']], $params['AddTagsToResource']['Tags']);
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

    public function test_a_tag_the_profile_may_not_add_is_a_warning_and_the_secret_is_saved(): void
    {
        $project = new ProjectFixture(ProjectFixture::standardConfig());
        $mock = new MockHandler([
            new Result(['Account' => '111111111111']),
            new Result(['Version' => 2]),
            new \Aws\Ssm\Exception\SsmException('denied', new \Aws\Command('AddTagsToResource'), ['code' => 'AccessDeniedException', 'message' => 'not authorized to perform: ssm:AddTagsToResource']),
        ]);
        $tester = $project->tester(new SecretSetCommand(
            fn (?string $profile, string $region) => new AwsClients($profile, $region, ['handler' => $mock, 'credentials' => false]),
        ));
        $tester->setInputs(["value\n"]);

        $tester->execute(['name' => 'app-key', '--env' => 'production', '--profile' => 'p'], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('ssm:AddTagsToResource', (string) $display);
        self::assertStringContainsString('arco-ssm:/shop/production/app-key', (string) $display);
        $project->restore();
    }
}
