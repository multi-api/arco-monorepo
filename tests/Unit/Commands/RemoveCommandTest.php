<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Commands;

use Aws\MockHandler;
use Aws\Result;
use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Commands\RemoveCommand;
use Multiapi\Arco\Deploy\AppStatus;
use PHPUnit\Framework\TestCase;

final class RemoveCommandTest extends TestCase
{
    private function command(FakeDeployer $deployer): RemoveCommand
    {
        $mock = new MockHandler([new Result(['Account' => '111111111111'])]);
        return new RemoveCommand(
            awsFactory: fn (?string $profile, string $region) => new AwsClients($profile, $region, ['handler' => $mock, 'credentials' => false]),
            deployerFactory: fn () => $deployer,
        );
    }

    public function test_requires_typing_the_environment_name(): void
    {
        $project = new ProjectFixture(ProjectFixture::standardConfig());
        $deployer = new FakeDeployer(status: new AppStatus('shop-production', 'Active', 'x.on.aws', 'rev-1'));
        $tester = $project->tester($this->command($deployer));
        $tester->setInputs(['prod']);

        $tester->execute(['--env' => 'production', '--profile' => 'p']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertNotContains('remove', $deployer->calls);
        $project->restore();
    }

    public function test_removes_after_confirmation(): void
    {
        $project = new ProjectFixture(ProjectFixture::standardConfig());
        $deployer = new FakeDeployer(status: new AppStatus('shop-production', 'Active', 'x.on.aws', 'rev-1'));
        $tester = $project->tester($this->command($deployer));
        $tester->setInputs(['production']);

        $tester->execute(['--env' => 'production', '--profile' => 'p']);

        $tester->assertCommandIsSuccessful();
        self::assertContains('remove', $deployer->calls);
        $project->restore();
    }

    public function test_non_interactive_needs_force(): void
    {
        $project = new ProjectFixture(ProjectFixture::standardConfig());
        $deployer = new FakeDeployer(status: new AppStatus('shop-production', 'Active', 'x.on.aws', 'rev-1'));

        $tester = $project->tester($this->command($deployer));
        $tester->execute(['--env' => 'production', '--profile' => 'p'], ['interactive' => false]);
        self::assertSame(1, $tester->getStatusCode());

        $tester = $project->tester($this->command($deployer));
        $tester->execute(['--env' => 'production', '--profile' => 'p', '--force' => true], ['interactive' => false]);
        $tester->assertCommandIsSuccessful();
        self::assertContains('remove', $deployer->calls);
        $project->restore();
    }

    public function test_removes_domain_resources_left_after_domain_was_dropped_using_the_dns_profile(): void
    {
        // domain() is gone from arco.php but dns() is kept: the stack's aliases live in that account.
        $config = str_replace("->timeout(60)", "->timeout(60)->dns('acme-prod')", ProjectFixture::standardConfig());
        $project = new ProjectFixture($config);
        $deployer = new FakeDeployer(status: null, domainResources: true);
        $mock = new MockHandler([new Result(['Account' => '111111111111']), new Result(['Account' => '333333333333'])]);
        $dnsProfiles = [];
        $command = new RemoveCommand(
            awsFactory: fn (?string $profile, string $region) => new AwsClients($profile, $region, ['handler' => $mock, 'credentials' => false]),
            deployerFactory: function (AwsClients $aws, ?AwsClients $dns) use ($deployer, &$dnsProfiles) {
                $dnsProfiles[] = $dns?->profile;
                return $deployer;
            },
        );

        $tester = $project->tester($command);
        $tester->execute(['--env' => 'production', '--profile' => 'p', '--force' => true], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        self::assertSame(['acme-prod'], $dnsProfiles);
        self::assertSame(['describe', 'remove'], $deployer->calls);
        self::assertCount(0, $mock, 'the DNS profile credentials were checked');
        $project->restore();
    }

    public function test_nothing_to_remove_only_when_neither_function_nor_domain_resources_exist(): void
    {
        $config = str_replace("->timeout(60)", "->timeout(60)->domain('arco.acmeapp.io')", ProjectFixture::standardConfig());
        $project = new ProjectFixture($config);
        $deployer = new FakeDeployer(status: null, domainResources: false);

        $tester = $project->tester($this->command($deployer));
        $tester->execute(['--env' => 'production', '--profile' => 'p', '--force' => true], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        self::assertSame(['describe'], $deployer->calls);
        $project->restore();
    }
}
