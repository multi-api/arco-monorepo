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
        $config = str_replace("->timeout(60)", "->timeout(60)->consoleTimeout(300)->deployCommands(['migrate --force'])", ProjectFixture::consoleConfig());
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
        self::assertSame(['function' => 'shop-production-console', 'version' => '7', 'enabled' => true], $json['console']);
        self::assertSame(['migrate --force'], $json['deployCommands']);
        self::assertTrue($json['settings']['protected']);
        putenv('AWS_PROFILE');
        $project->restore();
    }

    public function test_shows_console_scheduler_and_log_retention_rows(): void
    {
        $config = str_replace("->timeout(60)", "->timeout(60)->console()->scheduler(5)->logRetention(30)", ProjectFixture::standardConfig());
        $project = new ProjectFixture($config);
        $tester = $project->tester(new InfoCommand(deployerFactory: fn () => throw new \LogicException('no AWS expected')));

        $tester->execute(['--env' => 'production'], ['interactive' => false]);
        $display = $tester->getDisplay();
        self::assertMatchesRegularExpression('/\bconsole\s+on\s+environment/', $display);
        self::assertMatchesRegularExpression('/scheduler\s+every 5 min\s+environment/', $display);
        self::assertMatchesRegularExpression('/logRetention\s+30 days\s+environment/', $display);

        $tester->execute(['--env' => 'production', '--json' => true], ['interactive' => false]);
        $json = json_decode($tester->getDisplay(), true);
        // --json carries machine values, as arco.php writes them.
        self::assertTrue($json['settings']['console']);
        self::assertSame(5, $json['settings']['scheduler']);
        self::assertSame(30, $json['settings']['logRetention']);

        $tester->execute(['--env' => 'staging'], ['interactive' => false]);
        $display = $tester->getDisplay();
        self::assertMatchesRegularExpression('/\bconsole\s+not set\s+default/', $display);
        self::assertMatchesRegularExpression('/scheduler\s+off\s+default/', $display);
        self::assertMatchesRegularExpression('/logRetention\s+not managed\s+default/', $display);

        $tester->execute(['--env' => 'staging', '--json' => true], ['interactive' => false]);
        $json = json_decode($tester->getDisplay(), true);
        self::assertArrayHasKey('console', $json['settings']);
        self::assertNull($json['settings']['console']);
        self::assertNull($json['settings']['scheduler']);
        self::assertNull($json['settings']['logRetention']);
        $project->restore();
    }

    public function test_log_retention_never_and_console_off(): void
    {
        $config = str_replace("->timeout(60)", "->timeout(60)->console(false)->logRetention(false)", ProjectFixture::standardConfig());
        $project = new ProjectFixture($config);
        $tester = $project->tester(new InfoCommand(deployerFactory: fn () => throw new \LogicException('no AWS expected')));
        $tester->execute(['--env' => 'production', '--json' => true], ['interactive' => false]);
        $json = json_decode($tester->getDisplay(), true);
        self::assertFalse($json['settings']['console']);
        self::assertFalse($json['settings']['logRetention']);

        $tester->execute(['--env' => 'production'], ['interactive' => false]);
        self::assertMatchesRegularExpression('/\bconsole\s+off\s+environment/', $tester->getDisplay());
        self::assertMatchesRegularExpression('/logRetention\s+never expire\s+environment/', $tester->getDisplay());
        $project->restore();
    }

    public function test_one_day_retention_is_singular(): void
    {
        $project = new ProjectFixture(str_replace("->timeout(60)", "->timeout(60)->logRetention(1)", ProjectFixture::standardConfig()));
        $tester = $project->tester(new InfoCommand(deployerFactory: fn () => throw new \LogicException('no AWS expected')));
        $tester->execute(['--env' => 'production'], ['interactive' => false]);
        self::assertMatchesRegularExpression('/logRetention\s+1 day\s+environment/', $tester->getDisplay());
        $project->restore();
    }

    public function test_setting_values_read_as_spanish(): void
    {
        $on = str_replace("->timeout(60)", "->timeout(60)->console()->scheduler(5)->logRetention(30)", ProjectFixture::standardConfig());
        $off = str_replace("->timeout(60)", "->timeout(60)->console(false)->logRetention(false)", ProjectFixture::standardConfig());
        $run = function (string $config, string $env): string {
            $project = new ProjectFixture($config);
            $app = \Multiapi\Arco\Cli\Application::create();
            $app->addCommands([new InfoCommand(deployerFactory: fn () => throw new \LogicException('no AWS expected'))]);
            $app->setAutoExit(false);
            $tester = new \Symfony\Component\Console\Tester\ApplicationTester($app);
            $tester->run(['command' => 'info', '--env' => $env, '--lang' => 'es'], ['interactive' => false]);
            $project->restore();
            return $tester->getDisplay();
        };

        $display = $run($on, 'production');
        self::assertMatchesRegularExpression('/\bconsole\s+activada\s+environment/', $display);
        self::assertMatchesRegularExpression('/scheduler\s+cada 5 min\s+environment/', $display);
        self::assertMatchesRegularExpression('/logRetention\s+30 días\s+environment/', $display);

        $display = $run($on, 'staging');
        self::assertMatchesRegularExpression('/\bconsole\s+sin definir\s+default/', $display);
        self::assertMatchesRegularExpression('/scheduler\s+desactivado\s+default/', $display);
        self::assertMatchesRegularExpression('/logRetention\s+sin gestionar\s+default/', $display);

        $display = $run($off, 'production');
        self::assertMatchesRegularExpression('/\bconsole\s+desactivada\s+environment/', $display);
        self::assertMatchesRegularExpression('/logRetention\s+no expiran\s+environment/', $display);
    }

    public function test_a_console_that_is_off_is_not_looked_up(): void
    {
        $project = new ProjectFixture(ProjectFixture::standardConfig());
        putenv('AWS_PROFILE=p');
        $status = new \Multiapi\Arco\Deploy\AppStatus('shop-production', 'Active', 'abc.on.aws', 'rev-1');
        $tester = $project->tester(new InfoCommand(deployerFactory: fn () => new FakeDeployer(status: $status)));

        $tester->execute(['--env' => 'production'], ['interactive' => false]);
        self::assertStringContainsString('off (not deployed)', $tester->getDisplay());
        self::assertStringNotContainsString('not deployed yet', $tester->getDisplay());

        $tester->execute(['--env' => 'production', '--json' => true], ['interactive' => false]);
        self::assertSame(['enabled' => false, 'function' => 'shop-production-console'], json_decode($tester->getDisplay(), true)['console']);
        putenv('AWS_PROFILE');
        $project->restore();
    }

    public function test_a_console_version_that_cannot_be_read_is_unknown_not_missing(): void
    {
        $project = new ProjectFixture(ProjectFixture::consoleConfig());
        putenv('AWS_PROFILE=p');
        $denied = new \Multiapi\Arco\Deploy\AppStatus('shop-production', 'Active', 'abc.on.aws', 'rev-1', null, null, 'shop-production-console', null, 'AccessDeniedException');
        $throttled = new \Multiapi\Arco\Deploy\AppStatus('shop-production', 'Active', 'abc.on.aws', 'rev-1', null, null, 'shop-production-console', null, 'TooManyRequestsException');

        $tester = $project->tester(new InfoCommand(deployerFactory: fn () => new FakeDeployer(status: $denied)));
        $tester->execute(['--env' => 'production'], ['interactive' => false]);
        self::assertStringContainsString('unknown (permission denied: lambda:GetAlias)', $tester->getDisplay());
        self::assertStringNotContainsString('not deployed yet', $tester->getDisplay());
        $tester->execute(['--env' => 'production', '--json' => true], ['interactive' => false]);
        self::assertSame(['function' => 'shop-production-console', 'version' => null, 'error' => 'AccessDeniedException', 'enabled' => true], json_decode($tester->getDisplay(), true)['console']);

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
