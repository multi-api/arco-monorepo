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
        self::assertMatchesRegularExpression('/runtime\.build\s+php8\.5\.11-fp1\.13\.0-r2 \(FrankenPHP 1\.13\.0, PHP 8\.5\.11\)\s+default/', $display);
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
        self::assertSame('php8.5.11-fp1.13.0-r2 (FrankenPHP 1.13.0, PHP 8.5.11)', $json['settings']['runtime.build']);
        self::assertSame(4, $json['settings']['runtime.workers'], 'an integer since 0.9 (it was "4-64")');
        self::assertSame(64, $json['settings']['runtime.maxThreads']);
        $project->restore();
    }

    public function test_a_deprecated_queue_spelling_is_warned_once_with_several_environments(): void
    {
        $config = str_replace('->concurrency(48)', "->concurrency(48)->queue('emails', concurrency: 5)->queue('jobs', concurrency: 3)", ProjectFixture::standardConfig());
        $project = new ProjectFixture($config);
        $tester = $project->tester(new InfoCommand(deployerFactory: fn () => throw new \LogicException('no AWS expected')));

        $tester->execute(['--env' => 'production'], ['interactive' => false]);

        self::assertSame(1, substr_count($tester->getDisplay(), 'is deprecated'));
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
        $config = str_replace("->timeout(60)", "->timeout(60)->console(timeout: 300)->deployCommands(['migrate --force'])", ProjectFixture::consoleConfig());
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

    public function test_shows_a_paused_scheduler(): void
    {
        $config = str_replace("->timeout(60)", "->timeout(60)->scheduler(5)", ProjectFixture::consoleConfig());
        $project = new ProjectFixture($config);
        putenv('AWS_PROFILE=p');
        $status = fn (?bool $paused) => new \Multiapi\Arco\Deploy\AppStatus('shop-production', 'Active', 'abc.on.aws', 'rev-1', null, null, 'shop-production-console', '7', schedulerPaused: $paused);
        $deployer = new FakeDeployer(status: $status(true));
        $tester = $project->tester(new InfoCommand(deployerFactory: fn () => $deployer));

        $tester->execute(['--env' => 'production'], ['interactive' => false]);
        self::assertMatchesRegularExpression('/scheduler\s+every 5 min \(paused\)\s+environment/', $tester->getDisplay());
        $tester->execute(['--env' => 'production', '--json' => true], ['interactive' => false]);
        $json = json_decode($tester->getDisplay(), true);
        self::assertSame(5, $json['settings']['scheduler']);
        self::assertTrue($json['settings']['schedulerPaused']);

        $deployer->status = $status(false);
        $tester->execute(['--env' => 'production'], ['interactive' => false]);
        self::assertStringNotContainsString('(paused)', $tester->getDisplay());
        $tester->execute(['--env' => 'production', '--json' => true], ['interactive' => false]);
        self::assertFalse(json_decode($tester->getDisplay(), true)['settings']['schedulerPaused']);

        $deployer->status = $status(null);
        $tester->execute(['--env' => 'production', '--json' => true], ['interactive' => false]);
        $json = json_decode($tester->getDisplay(), true);
        self::assertArrayHasKey('schedulerPaused', $json['settings']);
        self::assertNull($json['settings']['schedulerPaused']);
        putenv('AWS_PROFILE');
        $project->restore();
    }

    public function test_a_paused_scheduler_reads_as_spanish(): void
    {
        $project = new ProjectFixture(str_replace("->timeout(60)", "->timeout(60)->scheduler(5)", ProjectFixture::consoleConfig()));
        putenv('AWS_PROFILE=p');
        $status = new \Multiapi\Arco\Deploy\AppStatus('shop-production', 'Active', 'abc.on.aws', 'rev-1', null, null, 'shop-production-console', '7', schedulerPaused: true);
        $app = \Multiapi\Arco\Cli\Application::create();
        $app->addCommands([new InfoCommand(deployerFactory: fn () => new FakeDeployer(status: $status))]);
        $app->setAutoExit(false);
        $tester = new \Symfony\Component\Console\Tester\ApplicationTester($app);
        $tester->run(['command' => 'info', '--env' => 'production', '--lang' => 'es'], ['interactive' => false]);
        self::assertMatchesRegularExpression('/scheduler\s+cada 5 min \(en pausa\)\s+environment/', $tester->getDisplay());
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
        self::assertMatchesRegularExpression('/logRetention\s+30 days \(default\)\s+default/', $display);

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
        self::assertMatchesRegularExpression('/logRetention\s+30 días \(por defecto\)\s+default/', $display);

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

    public function test_shows_the_queues_with_their_urls_and_worker_versions(): void
    {
        $config = str_replace('->concurrency(48)', "->concurrency(48)->queue('emails')->queue('legacy', existing: 'legacy-jobs', dlq: false)", ProjectFixture::standardConfig());
        $project = new ProjectFixture($config);
        putenv('AWS_PROFILE=p');
        $status = new \Multiapi\Arco\Deploy\AppStatus('shop-production', 'Active', 'abc.on.aws', 'rev-1', queues: [
            'emails' => ['url' => 'https://sqs.us-east-1.amazonaws.com/111111111111/shop-production-emails', 'version' => '4', 'error' => null],
            'legacy' => ['url' => 'https://sqs.us-east-1.amazonaws.com/111111111111/legacy-jobs', 'version' => null, 'error' => null],
        ]);
        $tester = $project->tester(new InfoCommand(deployerFactory: fn () => new FakeDeployer(status: $status)));

        $tester->execute(['--env' => 'production'], ['interactive' => false]);
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('Queue emails https://sqs.us-east-1.amazonaws.com/111111111111/shop-production-emails · worker shop-production-queue-emails (version 4)', $display);
        self::assertStringContainsString('Queue legacy https://sqs.us-east-1.amazonaws.com/111111111111/legacy-jobs · worker not deployed yet (run arco deploy)', $display);

        $tester->execute(['--env' => 'production', '--json' => true], ['interactive' => false]);
        self::assertSame([
            ['name' => 'emails', 'url' => 'https://sqs.us-east-1.amazonaws.com/111111111111/shop-production-emails', 'existing' => false, 'dlq' => 'shop-production-emails-dlq', 'worker' => ['function' => 'shop-production-queue-emails', 'version' => '4']],
            ['name' => 'legacy', 'url' => 'https://sqs.us-east-1.amazonaws.com/111111111111/legacy-jobs', 'existing' => true, 'dlq' => null, 'worker' => ['function' => 'shop-production-queue-legacy', 'version' => null]],
        ], json_decode($tester->getDisplay(), true)['queues']);
        putenv('AWS_PROFILE');
        $project->restore();
    }

    public function test_queues_without_remote_status_have_no_url_or_version(): void
    {
        $project = new ProjectFixture(str_replace('->concurrency(48)', "->concurrency(48)->queue('emails')", ProjectFixture::standardConfig()));
        $tester = $project->tester(new InfoCommand(deployerFactory: fn () => throw new \LogicException('no AWS expected')));

        $tester->execute(['--env' => 'production', '--json' => true], ['interactive' => false]);
        self::assertSame(
            [['name' => 'emails', 'url' => null, 'existing' => false, 'dlq' => 'shop-production-emails-dlq', 'worker' => ['function' => 'shop-production-queue-emails', 'version' => null]]],
            json_decode($tester->getDisplay(), true)['queues'],
        );

        $project->restore();
        $project = new ProjectFixture(ProjectFixture::standardConfig());
        $tester = $project->tester(new InfoCommand(deployerFactory: fn () => throw new \LogicException('no AWS expected')));
        $tester->execute(['--env' => 'production', '--json' => true], ['interactive' => false]);
        self::assertSame([], json_decode($tester->getDisplay(), true)['queues']);
        $project->restore();
    }

    public function test_shows_storage_cache_and_failed_jobs_table(): void
    {
        $config = str_replace('->concurrency(48)', "->concurrency(48)->storage('uploads', public: ['public/'])->storage('legacy', existing: 'old-bucket')->cache()->failedJobsTable(existing: 'shared-failed')", ProjectFixture::standardConfig());
        $project = new ProjectFixture($config);
        putenv('AWS_PROFILE=p');
        $status = new \Multiapi\Arco\Deploy\AppStatus('shop-production', 'Active', 'abc.on.aws', 'rev-1', account: '111111111111');
        $tester = $project->tester(new InfoCommand(deployerFactory: fn () => new FakeDeployer(status: $status)));

        $tester->execute(['--env' => 'production'], ['interactive' => false]);
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('Storage uploads shop-production-uploads-111111111111 · default disk · public: public/', $display);
        self::assertStringContainsString('Storage legacy old-bucket (existing, not managed by Arco)', $display);
        self::assertStringContainsString('Cache table shop-production-cache', $display);
        self::assertStringContainsString('Failed jobs table shared-failed (existing, not managed by Arco)', $display);

        $tester->execute(['--env' => 'production', '--json' => true], ['interactive' => false]);
        $json = json_decode($tester->getDisplay(), true);
        self::assertSame([
            ['name' => 'uploads', 'bucket' => 'shop-production-uploads-111111111111', 'url' => 'https://shop-production-uploads-111111111111.s3.us-east-1.amazonaws.com', 'existing' => false, 'default' => true, 'defaultOverridden' => false, 'public' => ['public/']],
            ['name' => 'legacy', 'bucket' => 'old-bucket', 'url' => 'https://old-bucket.s3.us-east-1.amazonaws.com', 'existing' => true, 'default' => false, 'defaultOverridden' => false, 'public' => []],
        ], $json['storage']);
        self::assertSame(['table' => 'shop-production-cache', 'existing' => false], $json['cache']);
        self::assertSame(['table' => 'shared-failed', 'existing' => true], $json['failedJobsTable']);
        putenv('AWS_PROFILE');
        $project->restore();
    }

    public function test_storage_default_overridden_by_variables(): void
    {
        $config = str_replace('->concurrency(48)', "->concurrency(48)->storage('uploads')->variables(['AWS_BUCKET' => 'other'])", ProjectFixture::standardConfig());
        $project = new ProjectFixture($config);
        putenv('AWS_PROFILE=p');
        $status = new \Multiapi\Arco\Deploy\AppStatus('shop-production', 'Active', 'abc.on.aws', 'rev-1', account: '111111111111');
        $tester = $project->tester(new InfoCommand(deployerFactory: fn () => new FakeDeployer(status: $status)));

        $tester->execute(['--env' => 'production'], ['interactive' => false]);
        self::assertStringContainsString('default disk (overridden by variables(): AWS_BUCKET)', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));

        $tester->execute(['--env' => 'production', '--json' => true], ['interactive' => false]);
        $json = json_decode($tester->getDisplay(), true);
        self::assertTrue($json['storage'][0]['default']);
        self::assertTrue($json['storage'][0]['defaultOverridden']);
        putenv('AWS_PROFILE');
        $project->restore();
    }

    public function test_storage_without_remote_status_has_no_generated_bucket_and_no_tables_are_null(): void
    {
        $project = new ProjectFixture(str_replace('->concurrency(48)', "->concurrency(48)->storage('uploads')", ProjectFixture::standardConfig()));
        $tester = $project->tester(new InfoCommand(deployerFactory: fn () => throw new \LogicException('no AWS expected')));

        $tester->execute(['--env' => 'production', '--json' => true], ['interactive' => false]);
        $json = json_decode($tester->getDisplay(), true);
        self::assertSame([['name' => 'uploads', 'bucket' => null, 'url' => null, 'existing' => false, 'default' => true, 'defaultOverridden' => false, 'public' => []]], $json['storage']);
        self::assertNull($json['cache']);
        self::assertNull($json['failedJobsTable']);
        $project->restore();
    }

    /** @return list<array{type: string, name: string, arn: string, retained: bool, relations: list<array{type: string, target: string}>}> */
    private static function tagged(): array
    {
        return [
            ['type' => 'lambda:function', 'name' => 'shop-production-console', 'arn' => 'arn:aws:lambda:us-east-1:111111111111:function:shop-production-console', 'retained' => false, 'relations' => []],
            ['type' => 'sqs', 'name' => 'shop-production-emails', 'arn' => 'arn:aws:sqs:us-east-1:111111111111:shop-production-emails', 'retained' => true, 'relations' => []],
        ];
    }

    public function test_resources_lists_the_tagged_resources_of_the_environment(): void
    {
        $project = new ProjectFixture(ProjectFixture::standardConfig());
        putenv('AWS_PROFILE=p');
        $deployer = new FakeDeployer();
        $deployer->tagged = self::tagged();
        $tester = $project->tester(new InfoCommand(deployerFactory: fn () => $deployer));

        $tester->execute(['--env' => 'production', '--resources' => true], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        self::assertMatchesRegularExpression('/lambda:function\s+shop-production-console\s+deleted/', $display);
        self::assertMatchesRegularExpression('/sqs\s+shop-production-emails\s+kept/', $display);
        self::assertStringContainsString('arco-shop-production', $display, 'names the Resource Group');
        self::assertStringNotContainsString('concurrency', $display, 'only the resources');
        self::assertNotContains('describe', $deployer->calls);

        $tester->execute(['--env' => 'production', '--resources' => true, '--json' => true], ['interactive' => false]);
        $tester->assertCommandIsSuccessful();
        self::assertSame([
            'schema' => 1,
            'app' => 'shop',
            'environment' => 'production',
            'group' => 'arco-shop-production',
            'resources' => self::tagged(),
        ], json_decode($tester->getDisplay(), true));
        putenv('AWS_PROFILE');
        $project->restore();
    }

    public function test_resources_that_cannot_be_listed_fail_with_the_reason(): void
    {
        $project = new ProjectFixture(ProjectFixture::standardConfig());
        putenv('AWS_PROFILE=p');
        $deployer = new FakeDeployer();
        $deployer->taggedError = new \Aws\Exception\AwsException('denied', new \Aws\Command('GetResources'), ['code' => 'AccessDeniedException', 'message' => 'not authorized to perform: tag:GetResources']);
        $tester = $project->tester(new InfoCommand(deployerFactory: fn () => $deployer));

        $tester->execute(['--env' => 'production', '--resources' => true], ['interactive' => false]);
        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('tag:GetResources', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));

        $tester->execute(['--env' => 'production', '--resources' => true, '--json' => true], ['interactive' => false]);
        self::assertSame(1, $tester->getStatusCode());
        $json = json_decode($tester->getDisplay(), true);
        self::assertSame(1, $json['schema']);
        self::assertSame([], $json['resources']);
        self::assertStringContainsString('tag:GetResources', $json['error']);
        putenv('AWS_PROFILE');
        $project->restore();
    }

    public function test_resources_without_a_profile_say_how_to_choose_one(): void
    {
        $project = new ProjectFixture(ProjectFixture::standardConfig());
        putenv('AWS_PROFILE');
        $tester = $project->tester(new InfoCommand(deployerFactory: fn () => throw new \LogicException('no AWS expected')));

        $tester->execute(['--env' => 'production', '--resources' => true], ['interactive' => false]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('AWS_PROFILE', $tester->getDisplay());
        $project->restore();
    }

    public function test_json_gains_schema_1_and_keeps_every_existing_key(): void
    {
        $project = new ProjectFixture(ProjectFixture::standardConfig());
        $tester = $project->tester(new InfoCommand(deployerFactory: fn () => throw new \LogicException('no AWS expected')));

        $tester->execute(['--env' => 'production', '--json' => true], ['interactive' => false]);

        $json = json_decode($tester->getDisplay(), true);
        self::assertSame(1, $json['schema']);
        self::assertSame('schema', array_key_first($json), 'first key, as in info --resources');
        unset($json['schema']);
        self::assertSame(
            ['function', 'url', 'revision', 'domains', 'distribution', 'console', 'queues', 'storage', 'cache', 'failedJobsTable', 'deployCommands', 'settings', 'dns'],
            array_keys($json),
        );
        $project->restore();
    }

    public function test_profile_flag_is_used_and_runs_the_account_guard(): void
    {
        $config = str_replace("->timeout(60)", "->timeout(60)->account('999999999999')", ProjectFixture::standardConfig());
        $project = new ProjectFixture($config);
        $profiles = [];
        $mocks = new \Multiapi\Arco\Tests\Unit\Deploy\AwsMocks();
        $mocks->handler->append(new \Aws\Result(['Account' => '111111111111']));
        $deployer = new FakeDeployer();
        $tester = $project->tester(new InfoCommand(
            awsFactory: function (?string $profile, string $region) use ($mocks, &$profiles) {
                $profiles[] = $profile;
                return $mocks->clients($region);
            },
            deployerFactory: fn () => $deployer,
        ));

        $tester->execute(['--env' => 'production', '--profile' => 'acme-prod'], ['interactive' => false]);

        self::assertSame(['acme-prod'], $profiles);
        self::assertSame(['GetCallerIdentity'], $mocks->calls);
        self::assertNotContains('describe', $deployer->calls, 'the wrong account is never read');
        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('locked to account 999999999999', preg_replace('/\s+/', ' ', $tester->getDisplay()));

        $mocks->handler->append(new \Aws\Result(['Account' => '111111111111']));
        $tester->execute(['--env' => 'production', '-p' => 'acme-prod', '--json' => true], ['interactive' => false]);
        self::assertSame(1, json_decode($tester->getDisplay(), true)['schema']);
        self::assertStringContainsString('locked to account', json_decode($tester->getDisplay(), true)['error']);
        $project->restore();
    }

    public function test_profile_flag_on_the_right_account_describes_the_environment(): void
    {
        $config = str_replace("->timeout(60)", "->timeout(60)->account('111111111111')", ProjectFixture::standardConfig());
        $project = new ProjectFixture($config);
        $mocks = new \Multiapi\Arco\Tests\Unit\Deploy\AwsMocks();
        $mocks->handler->append(new \Aws\Result(['Account' => '111111111111']));
        $deployer = new FakeDeployer();
        $tester = $project->tester(new InfoCommand(awsFactory: fn (?string $p, string $r) => $mocks->clients($r), deployerFactory: fn () => $deployer));

        $tester->execute(['--env' => 'production', '--profile' => 'p'], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        self::assertContains('describe', $deployer->calls);
        $project->restore();
    }

    public function test_json_config_errors_print_schema_and_error_with_exit_1(): void
    {
        foreach ([[], ['--resources' => true]] as $extra) {
            // An environment arco.php does not define: a config error, before any AWS call.
            $project = new ProjectFixture(ProjectFixture::standardConfig());
            $tester = $project->tester(new InfoCommand(deployerFactory: fn () => throw new \LogicException('no AWS expected')));

            $tester->execute(['--env' => 'nowhere', '--json' => true, ...$extra], ['interactive' => false]);

            self::assertSame(1, $tester->getStatusCode());
            $json = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(['schema', 'error'], array_keys($json));
            self::assertSame(1, $json['schema']);
            self::assertStringContainsString('nowhere', $json['error']);
            $project->restore();
        }
    }
}
