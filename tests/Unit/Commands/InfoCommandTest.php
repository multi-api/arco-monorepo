<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Commands;

use Multiapi\Arco\Commands\InfoCommand;
use PHPUnit\Framework\TestCase;

final class InfoCommandTest extends TestCase
{
    public function test_shows_resolved_settings_with_sources_and_masks_plain_secrets(): void
    {
        $project = new ProjectFixture(ProjectFixture::standardConfig());
        $tester = $project->tester(new InfoCommand(deployerFactory: fn () => throw new \LogicException('no AWS expected')));

        $tester->execute(['--env' => 'production'], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        self::assertMatchesRegularExpression('/concurrency\s+48\s+app/', $display);
        self::assertMatchesRegularExpression('/timeout\s+60\s+environment/', $display);
        self::assertMatchesRegularExpression('/auth\s+public\s+default/', $display);
        self::assertMatchesRegularExpression('/maxInstances\s+-\s+default/', $display);
        self::assertStringNotContainsString('maxEnvironments', $display);
        self::assertMatchesRegularExpression('/runtime\.build\s+1\.13\.0-r2 \(FrankenPHP 1\.13\.0, PHP 8\.5\.11\)\s+default/', $display);
        self::assertStringContainsString('arco-ssm:/shop/production/app-key', $display);
        self::assertStringNotContainsString('plain-token', $display);
        $project->restore();
    }

    public function test_json_output(): void
    {
        $project = new ProjectFixture(ProjectFixture::standardConfig());
        $tester = $project->tester(new InfoCommand(deployerFactory: fn () => throw new \LogicException('no AWS expected')));

        $tester->execute(['--env' => 'production', '--json' => true], ['interactive' => false]);

        $json = json_decode($tester->getDisplay(), true);
        self::assertSame('shop-production', $json['function']);
        self::assertNull($json['url']);
        self::assertSame(60, $json['settings']['timeout']);
        self::assertArrayHasKey('maxInstances', $json['settings']);
        self::assertArrayNotHasKey('maxEnvironments', $json['settings']);
        self::assertSame('1.13.0-r2 (FrankenPHP 1.13.0, PHP 8.5.11)', $json['settings']['runtime.build']);
        $project->restore();
    }

    public function test_shows_domains_and_dns_mode(): void
    {
        $config = str_replace("->timeout(60)", "->timeout(60)->domain(['arco.acmeapp.io', 'api-arco.acmeapp.io'])->dns('acme-dns')", ProjectFixture::standardConfig());
        $project = new ProjectFixture($config);
        $tester = $project->tester(new InfoCommand(deployerFactory: fn () => throw new \LogicException('no AWS expected')));

        $tester->execute(['--env' => 'production'], ['interactive' => false]);
        $display = $tester->getDisplay();
        self::assertMatchesRegularExpression('/domains\s+arco\.acmeapp\.io, api-arco\.acmeapp\.io\s+environment/', $display);
        self::assertMatchesRegularExpression('/dns\s+acme-dns\s+environment/', $display);

        $tester->execute(['--env' => 'staging'], ['interactive' => false]);
        self::assertMatchesRegularExpression('/domains\s+-\s+default/', $tester->getDisplay());

        $tester->execute(['--env' => 'production', '--json' => true], ['interactive' => false]);
        $json = json_decode($tester->getDisplay(), true);
        self::assertSame(['arco.acmeapp.io', 'api-arco.acmeapp.io'], $json['domains']);
        self::assertNull($json['distribution']);
        $project->restore();
    }

    public function test_shows_console_settings_deploy_commands_and_the_live_version(): void
    {
        $config = str_replace("->timeout(60)", "->timeout(60)->consoleTimeout(300)->deployCommands(['migrate --force'])", ProjectFixture::standardConfig());
        $project = new ProjectFixture($config);
        putenv('AWS_PROFILE=p');
        $status = new \Multiapi\Arco\Deploy\AppStatus('shop-production', 'Active', 'abc.on.aws', 'rev-1', null, null, 'shop-production-console', '7');
        $tester = $project->tester(new InfoCommand(deployerFactory: fn () => new FakeDeployer(status: $status)));

        $tester->execute(['--env' => 'production'], ['interactive' => false]);
        $display = $tester->getDisplay();
        self::assertMatchesRegularExpression('/consoleTimeout\s+300\s+environment/', $display);
        self::assertMatchesRegularExpression('/deployCommands\s+migrate --force\s+environment/', $display);
        self::assertMatchesRegularExpression('/protected\s+yes\s+default/', $display, 'yes/no come from the translator (en here)');
        self::assertStringContainsString('shop-production-console (version 7)', $display);

        $tester->execute(['--env' => 'production', '--json' => true], ['interactive' => false]);
        $json = json_decode($tester->getDisplay(), true);
        self::assertSame(['function' => 'shop-production-console', 'version' => '7'], $json['console']);
        self::assertSame(['migrate --force'], $json['deployCommands']);
        self::assertTrue($json['settings']['protected']);
        putenv('AWS_PROFILE');
        $project->restore();
    }

    public function test_a_console_version_that_cannot_be_read_is_unknown_not_missing(): void
    {
        $project = new ProjectFixture(ProjectFixture::standardConfig());
        putenv('AWS_PROFILE=p');
        $denied = new \Multiapi\Arco\Deploy\AppStatus('shop-production', 'Active', 'abc.on.aws', 'rev-1', null, null, 'shop-production-console', null, 'AccessDeniedException');
        $throttled = new \Multiapi\Arco\Deploy\AppStatus('shop-production', 'Active', 'abc.on.aws', 'rev-1', null, null, 'shop-production-console', null, 'TooManyRequestsException');

        $tester = $project->tester(new InfoCommand(deployerFactory: fn () => new FakeDeployer(status: $denied)));
        $tester->execute(['--env' => 'production'], ['interactive' => false]);
        self::assertStringContainsString('unknown (permission denied: lambda:GetAlias)', $tester->getDisplay());
        self::assertStringNotContainsString('not deployed yet', $tester->getDisplay());
        $tester->execute(['--env' => 'production', '--json' => true], ['interactive' => false]);
        self::assertSame(['function' => 'shop-production-console', 'version' => null, 'error' => 'AccessDeniedException'], json_decode($tester->getDisplay(), true)['console']);

        $tester = $project->tester(new InfoCommand(deployerFactory: fn () => new FakeDeployer(status: $throttled)));
        $tester->execute(['--env' => 'production'], ['interactive' => false]);
        self::assertStringContainsString('unknown (TooManyRequestsException)', $tester->getDisplay());
        putenv('AWS_PROFILE');
        $project->restore();
    }

    public function test_expired_credentials_are_reported_instead_of_not_deployed(): void
    {
        $project = new ProjectFixture(ProjectFixture::standardConfig());
        file_put_contents((string) getenv('AWS_CONFIG_FILE'), "[profile acme-sso]\nsso_session = acme\nsso_account_id = 111111111111\n");
        $deployer = new FakeDeployer();
        $deployer->describeError = new \Aws\Exception\CredentialsException('Error retrieving credentials');
        $tester = $project->tester(new InfoCommand(deployerFactory: fn () => $deployer));

        putenv('AWS_PROFILE=acme-sso');
        $tester->execute(['--env' => 'production'], ['interactive' => false]);
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringNotContainsString('Not deployed yet', $display);
        self::assertStringContainsString('The AWS credentials for profile "acme-sso" are missing or expired', $display);
        self::assertStringContainsString('aws sso login --profile acme-sso', $display);
        self::assertSame(1, $tester->getStatusCode());

        $tester->execute(['--env' => 'production', '--json' => true], ['interactive' => false]);
        self::assertStringContainsString('missing or expired', json_decode($tester->getDisplay(), true)['error']);

        putenv('AWS_PROFILE=p');
        $deployer->describeError = new \Aws\Exception\AwsException('expired', new \Aws\Command('GetWebFunction'), ['code' => 'ExpiredTokenException']);
        $tester->execute(['--env' => 'production'], ['interactive' => false]);
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('The AWS credentials for profile "p" are missing or expired', $display);
        self::assertStringNotContainsString('sso login', $display);
        putenv('AWS_PROFILE');
        $project->restore();
    }
}
