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

    public function test_non_interactive_needs_yes(): void
    {
        $project = new ProjectFixture(ProjectFixture::standardConfig());
        $deployer = new FakeDeployer(status: new AppStatus('shop-production', 'Active', 'x.on.aws', 'rev-1'));

        $tester = $project->tester($this->command($deployer));
        $tester->execute(['--env' => 'production', '--profile' => 'p'], ['interactive' => false]);
        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Add --yes', preg_replace('/\s+/', ' ', $tester->getDisplay()));
        self::assertNotContains('remove', $deployer->calls);

        $tester = $project->tester($this->command($deployer));
        $tester->execute(['--env' => 'production', '--profile' => 'p', '--yes' => true], ['interactive' => false]);
        $tester->assertCommandIsSuccessful();
        self::assertContains('remove', $deployer->calls);
        $project->restore();
    }

    public function test_a_non_protected_environment_without_a_terminal_needs_yes(): void
    {
        $project = new ProjectFixture(ProjectFixture::standardConfig());
        $deployer = new FakeDeployer(status: new AppStatus('shop-staging', 'Active', 'x.on.aws', 'rev-1'));
        $tester = $project->tester($this->command($deployer));

        $tester->execute(['--env' => 'staging', '--profile' => 'p'], ['interactive' => false]);

        self::assertSame(1, $tester->getStatusCode());
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('needs --yes without a terminal', $display);
        self::assertStringNotContainsString('protected', $display);
        self::assertNotContains('remove', $deployer->calls);
        $project->restore();
    }

    public function test_force_still_works_with_one_deprecation_warning(): void
    {
        $project = new ProjectFixture(ProjectFixture::standardConfig());
        $deployer = new FakeDeployer(status: new AppStatus('shop-production', 'Active', 'x.on.aws', 'rev-1'));
        $tester = $project->tester($this->command($deployer));

        $tester->execute(['--env' => 'production', '--profile' => 'p', '--force' => true], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        self::assertContains('remove', $deployer->calls);
        self::assertSame(1, substr_count(preg_replace('/\s+/', ' ', $tester->getDisplay()), 'remove --force is deprecated'));
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
        $tester->execute(['--env' => 'production', '--profile' => 'p', '--yes' => true], ['interactive' => false]);

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
        $tester->execute(['--env' => 'production', '--profile' => 'p', '--yes' => true], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        self::assertSame(['describe'], $deployer->calls);
        $project->restore();
    }

    public function test_an_unreadable_scheduler_rule_is_a_warning_and_the_remove_goes_on(): void
    {
        $project = new ProjectFixture(ProjectFixture::standardConfig());
        $deployer = new FakeDeployer(status: new AppStatus('shop-production', 'Active', 'x.on.aws', 'rev-1'));
        $deployer->removeSteps = [
            ['remove.scheduler_unreadable', ['rule' => 'arco-shop-production-scheduler', 'error' => 'not authorized to perform: events:DescribeRule']],
            ['remove.step_console', ['function' => 'shop-production-console']],
        ];
        $tester = $project->tester($this->command($deployer));
        $tester->setInputs(['production']);

        $tester->execute(['--env' => 'production', '--profile' => 'p']);

        $tester->assertCommandIsSuccessful();
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('[WARNING] The scheduler rule arco-shop-production-scheduler', $display);
        self::assertStringContainsString('events:DescribeRule', $display);
        self::assertStringContainsString('• Deleting console function shop-production-console', $display);
        $project->restore();
    }

    public function test_lists_the_tagged_resources_before_asking_for_confirmation(): void
    {
        $project = new ProjectFixture(ProjectFixture::standardConfig());
        $deployer = new FakeDeployer(status: new AppStatus('shop-production', 'Active', 'x.on.aws', 'rev-1'));
        $deployer->tagged = [
            ['type' => 'lambda:function', 'name' => 'shop-production', 'arn' => 'arn:aws:lambda:us-east-1:111111111111:function:shop-production', 'retained' => false],
            ['type' => 's3', 'name' => 'shop-production-uploads', 'arn' => 'arn:aws:s3:::shop-production-uploads', 'retained' => true],
        ];
        $tester = $project->tester($this->command($deployer));
        $tester->setInputs(['nope']);

        $tester->execute(['--env' => 'production', '--profile' => 'p']);

        self::assertSame(1, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $list = strpos($display, 'shop-production-uploads');
        $question = strpos($display, 'Type "production" to confirm');
        self::assertNotFalse($list);
        self::assertNotFalse($question);
        self::assertLessThan($question, $list, 'the list comes before the confirmation');
        self::assertMatchesRegularExpression('/lambda:function shop-production .*deleted/', $display);
        self::assertMatchesRegularExpression('/s3 shop-production-uploads .*kept/', $display);
        self::assertNotContains('remove', $deployer->calls);
        $project->restore();
    }

    public function test_force_still_lists_the_tagged_resources(): void
    {
        $project = new ProjectFixture(ProjectFixture::standardConfig());
        $deployer = new FakeDeployer(status: new AppStatus('shop-production', 'Active', 'x.on.aws', 'rev-1'));
        $deployer->tagged = [['type' => 'sqs', 'name' => 'shop-production-emails', 'arn' => 'arn:aws:sqs:us-east-1:111111111111:shop-production-emails', 'retained' => true]];
        $tester = $project->tester($this->command($deployer));

        $tester->execute(['--env' => 'production', '--profile' => 'p', '--yes' => true], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        $list = strpos($display, 'shop-production-emails');
        $done = strpos($display, 'was removed');
        self::assertNotFalse($list);
        self::assertNotFalse($done);
        self::assertLessThan($done, $list);
        $project->restore();
    }

    public function test_a_list_that_cannot_be_read_is_a_note_and_the_remove_goes_on(): void
    {
        $project = new ProjectFixture(ProjectFixture::standardConfig());
        $deployer = new FakeDeployer(status: new AppStatus('shop-production', 'Active', 'x.on.aws', 'rev-1'));
        $deployer->taggedError = new \Aws\Exception\AwsException('denied', new \Aws\Command('GetResources'), ['code' => 'AccessDeniedException', 'message' => 'not authorized to perform: tag:GetResources']);
        $tester = $project->tester($this->command($deployer));

        $tester->execute(['--env' => 'production', '--profile' => 'p', '--yes' => true], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('tag:GetResources', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
        self::assertContains('remove', $deployer->calls);
        $project->restore();
    }
}
