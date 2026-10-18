<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Commands;

use Multiapi\Arco\Build\AppInspector;
use Multiapi\Arco\Build\PresetRegistry;
use Multiapi\Arco\Commands\InitCommand;
use Multiapi\Arco\Config\ConfigLoader;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Config\Validator;
use Multiapi\Arco\Deploy\DeployCommands;
use Multiapi\Arco\Laravel\LaravelPreset;
use Aws\Route53\Exception\Route53Exception;
use Multiapi\Arco\Tests\Support\TempPaths;
use Multiapi\Arco\Tests\Unit\Deploy\AwsMocks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

final class InitCommandTest extends TestCase
{
    public function test_writes_a_loadable_config_with_secret_references(): void
    {
        $project = new ProjectFixture();
        file_put_contents("$project->dir/.env", "APP_NAME=Shop\nAPP_KEY=base64:abc\nDB_PASSWORD=secret\nMAIL_FROM=x@example.com\n");
        file_put_contents("$project->dir/.gitignore", "/vendor\n");

        $tester = $project->tester(new InitCommand(new PresetRegistry([])));
        $tester->execute(['--name' => 'shop', '--region' => 'us-east-2', '--env' => ['staging', 'production']], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        $raw = (new ConfigLoader())->load("$project->dir/arco.php");
        self::assertSame(['staging', 'production'], ConfigResolver::environments($raw));
        $production = ConfigResolver::resolve($raw, 'production');
        self::assertSame('us-east-2', $production->region);
        self::assertSame('arco-ssm:/shop/production/app-key', $production->variables['APP_KEY']);
        self::assertSame('arco-ssm:/shop/production/db-password', $production->variables['DB_PASSWORD']);
        self::assertArrayNotHasKey('APP_NAME', $production->variables);
        self::assertStringContainsString(".arco/\n", (string) file_get_contents("$project->dir/.gitignore"));
        self::assertStringContainsString('arco secret:set app-key --env=production', $tester->getDisplay());
        $project->restore();
    }

    public function test_stock_laravel_env_only_references_keys_with_real_values(): void
    {
        $project = new ProjectFixture();
        // Laravel's stock .env.example, with a database password filled in.
        file_put_contents("$project->dir/.env", <<<'ENV'
APP_NAME=Laravel
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost:8000

APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US

APP_MAINTENANCE_DRIVER=file
# APP_MAINTENANCE_STORE=database

# PHP_CLI_SERVER_WORKERS=4

BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=debug

DB_CONNECTION=sqlite
# DB_HOST=127.0.0.1
# DB_PORT=3306
# DB_DATABASE=laravel
# DB_USERNAME=root
DB_PASSWORD=secret

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database

CACHE_STORE=database
# CACHE_PREFIX=

MEMCACHED_HOST=127.0.0.1

REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

MAIL_MAILER=log
MAIL_SCHEME=null
MAIL_HOST=127.0.0.1
MAIL_PORT=2525
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_FROM_ADDRESS="hello@example.com"
MAIL_FROM_NAME="${APP_NAME}"

AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=
AWS_USE_PATH_STYLE_ENDPOINT=false

VITE_APP_NAME="${APP_NAME}"
ENV);

        $tester = $project->tester(new InitCommand(new PresetRegistry([])));
        $tester->execute(['--name' => 'shop', '--region' => 'us-east-1'], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        $production = ConfigResolver::resolve((new ConfigLoader())->load("$project->dir/arco.php"), 'production');
        self::assertSame(['APP_ENV' => 'production', 'DB_PASSWORD' => 'arco-ssm:/shop/production/db-password'], $production->variables);
        self::assertTrue((new Validator())->validate($production, $project->dir)->isValid());
        $project->restore();
    }

    public function test_quoted_empty_values_are_skipped(): void
    {
        $project = new ProjectFixture();
        file_put_contents("$project->dir/.env", "APP_KEY=\"\"\nSTRIPE_SECRET=''\nAPI_TOKEN=\"NULL\"\nPUSHER_APP_SECRET=\"s3cret\"\nAWS_SESSION_TOKEN=abc\n");

        $tester = $project->tester(new InitCommand(new PresetRegistry([])));
        $tester->execute(['--name' => 'shop', '--region' => 'us-east-1'], ['interactive' => false]);

        $production = ConfigResolver::resolve((new ConfigLoader())->load("$project->dir/arco.php"), 'production');
        self::assertSame(['APP_ENV' => 'production', 'PUSHER_APP_SECRET' => 'arco-ssm:/shop/production/pusher-app-secret'], $production->variables);
        $project->restore();
    }

    public function test_refuses_to_overwrite_without_force(): void
    {
        $project = new ProjectFixture('<?php // existing');
        $tester = $project->tester(new InitCommand(new PresetRegistry([])));

        $tester->execute(['--name' => 'shop', '--region' => 'us-east-1'], ['interactive' => false]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertSame('<?php // existing', file_get_contents("$project->dir/arco.php"));
        $project->restore();
    }

    public function test_rejects_invalid_region_and_environment_without_writing(): void
    {
        foreach ([['--region' => "us'x"], ['--region' => 'us-east-1', '--env' => ["it's"]]] as $args) {
            $project = new ProjectFixture();
            $tester = $project->tester(new InitCommand(new PresetRegistry([])));
            $tester->execute(['--name' => 'shop'] + $args, ['interactive' => false]);
            self::assertSame(1, $tester->getStatusCode());
            self::assertFileDoesNotExist("$project->dir/arco.php");
            $project->restore();
        }
    }

    public function test_duplicate_environments_are_collapsed(): void
    {
        $project = new ProjectFixture();
        $tester = $project->tester(new InitCommand(new PresetRegistry([])));
        $tester->execute(['--name' => 'shop', '--region' => 'us-east-1', '--env' => ['production', 'production']], ['interactive' => false]);
        $tester->assertCommandIsSuccessful();
        self::assertSame(['production'], ConfigResolver::environments((new ConfigLoader())->load("$project->dir/arco.php")));
        $project->restore();
    }

    public function test_default_name_is_a_slug_of_the_directory(): void
    {
        self::assertSame('my-shop-2', InitCommand::slug('My Shop_2'));
        self::assertSame('app-2shop', InitCommand::slug('2shop'));
    }

    private static function init(ProjectFixture $project, ?FakeZones $zones = null): \Symfony\Component\Console\Tester\CommandTester
    {
        return $project->tester(new InitCommand(new PresetRegistry([]), ($zones ?? new FakeZones([]))->finder()));
    }

    private static function config(ProjectFixture $project, string $environment = 'production'): \Multiapi\Arco\Config\AppConfig
    {
        return ConfigResolver::resolve((new ConfigLoader())->load("$project->dir/arco.php"), $environment);
    }

    public function test_render_writes_domains_and_each_dns_mode(): void
    {
        $php = InitCommand::render('shop', 'us-east-1', ['production', 'staging', 'dev'], [], [
            'production' => ['arco.acmeapp.io', 'api-arco.acmeapp.io'],
            'staging' => ['staging.acmeapp.io'],
            'dev' => ['dev.acmeapp.io'],
        ], ['production' => 'acme-dns', 'staging' => false]);
        $path = TempPaths::path('arco-render-', '.php');
        file_put_contents($path, $php);
        $raw = (new ConfigLoader())->load($path);

        $production = ConfigResolver::resolve($raw, 'production');
        self::assertSame(['arco.acmeapp.io', 'api-arco.acmeapp.io'], $production->domains);
        self::assertSame('acme-dns', $production->dnsProfile);
        self::assertTrue(ConfigResolver::resolve($raw, 'staging')->dnsManual);
        $dev = ConfigResolver::resolve($raw, 'dev');
        self::assertSame(['dev.acmeapp.io'], $dev->domains);
        self::assertNull($dev->dnsProfile);
        self::assertFalse($dev->dnsManual);
        self::assertStringContainsString("        ])\n        ->domain(['arco.acmeapp.io', 'api-arco.acmeapp.io'])\n        ->dns('acme-dns'))", $php);
        self::assertTrue((new Validator())->validate($production, sys_get_temp_dir())->isValid());
    }

    public function test_domain_flags_with_a_dns_profile_check_only_that_profile(): void
    {
        $project = new ProjectFixture();
        $zones = new FakeZones(['acme-dns' => FakeZones::holding('222222222222', ['acmeapp.io' => '/hostedzone/Z1'])]);
        $tester = self::init($project, $zones);

        $tester->execute(['--name' => 'shop', '--region' => 'us-east-1', '--env' => ['staging', 'production'], '--domain' => ['Arco.acmeapp.io', 'api-arco.acmeapp.io'], '--dns-profile' => 'acme-dns'], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        foreach (['staging', 'production'] as $environment) {
            self::assertSame(['arco.acmeapp.io', 'api-arco.acmeapp.io'], self::config($project, $environment)->domains);
            self::assertSame('acme-dns', self::config($project, $environment)->dnsProfile);
        }
        self::assertSame([], array_filter($zones->calls, fn (string $c) => ! str_starts_with($c, 'acme-dns:')));
        $project->restore();
    }

    public function test_dns_profile_flag_fails_with_the_sso_hint_when_it_cannot_be_checked(): void
    {
        $project = new ProjectFixture();
        $zones = new FakeZones(['acme-prod' => [new RuntimeException('Error loading SSO Token: The SSO session has expired')]]);
        $tester = self::init($project, $zones);

        $tester->execute(['--name' => 'shop', '--region' => 'us-east-1', '--domain' => ['arco.acmeapp.io'], '--dns-profile' => 'acme-prod'], ['interactive' => false]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertFileDoesNotExist("$project->dir/arco.php");
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('aws sso login --profile acme-prod', $display);
        self::assertStringContainsString('--dns=manual', $display);
        $project->restore();
    }

    public function test_dns_manual_flag(): void
    {
        $project = new ProjectFixture();
        $zones = new FakeZones([]);
        $tester = self::init($project, $zones);

        $tester->execute(['--name' => 'shop', '--region' => 'us-east-1', '--domain' => ['arco.acmeapp.io'], '--dns' => 'manual'], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        self::assertTrue(self::config($project)->dnsManual);
        self::assertSame([], $zones->calls);
        $project->restore();
    }

    public function test_non_interactive_without_a_known_deploy_profile_trusts_the_deploy_account(): void
    {
        $project = new ProjectFixture();
        $zones = new FakeZones([]);
        $tester = self::init($project, $zones);

        $tester->execute(['--name' => 'shop', '--region' => 'us-east-1', '--domain' => ['arco.acmeapp.io']], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        $config = self::config($project);
        self::assertSame(['arco.acmeapp.io'], $config->domains);
        self::assertNull($config->dnsProfile);
        self::assertFalse($config->dnsManual);
        self::assertSame([], $zones->calls);
        self::assertStringContainsString('The deploy profile is not known yet', $tester->getDisplay());
        $project->restore();
    }

    public function test_non_interactive_checks_only_the_deploy_profile(): void
    {
        $project = new ProjectFixture();
        putenv('AWS_PROFILE=p');
        $zones = new FakeZones(['p' => FakeZones::holding('111111111111', ['acmeapp.io' => '/hostedzone/Z1']), 'acme-prod' => FakeZones::holding('2', ['acmeapp.io' => '/hostedzone/Z9'])]);
        $tester = self::init($project, $zones);

        $tester->execute(['--name' => 'shop', '--region' => 'us-east-1', '--domain' => ['arco.acmeapp.io']], ['interactive' => false]);
        putenv('AWS_PROFILE');

        $tester->assertCommandIsSuccessful();
        self::assertNull(self::config($project)->dnsProfile);
        self::assertFalse(self::config($project)->dnsManual);
        self::assertSame(['p:ListHostedZonesByName', 'p:GetCallerIdentity'], $zones->calls);
        $project->restore();
    }

    public function test_non_interactive_fails_when_the_deploy_profile_lacks_the_zone(): void
    {
        $project = new ProjectFixture();
        putenv('AWS_PROFILE=p');
        $zones = new FakeZones(['p' => FakeZones::empty()]);
        $tester = self::init($project, $zones);

        $tester->execute(['--name' => 'shop', '--region' => 'us-east-1', '--domain' => ['arco.acmeapp.io']], ['interactive' => false]);
        putenv('AWS_PROFILE');

        self::assertSame(1, $tester->getStatusCode());
        self::assertFileDoesNotExist("$project->dir/arco.php");
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('Profile p has no Route 53 zone for: arco.acmeapp.io', $display);
        self::assertStringContainsString('--dns-profile=<profile>', $display);
        $project->restore();
    }

    public function test_invalid_domain_flag_fails_without_writing(): void
    {
        $project = new ProjectFixture();
        $tester = self::init($project);

        $tester->execute(['--name' => 'shop', '--region' => 'us-east-1', '--domain' => ['localhost']], ['interactive' => false]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertFileDoesNotExist("$project->dir/arco.php");
        $project->restore();
    }

    public function test_interactive_questions_reask_invalid_domains_and_check_the_chosen_profile(): void
    {
        $project = new ProjectFixture();
        $zones = new FakeZones(['acme-prod' => FakeZones::holding('222222222222', ['acmeapp.io' => '/hostedzone/Z1'])]);
        $tester = self::init($project, $zones);
        $tester->setInputs(['yes', 'localhost', 'arco.acmeapp.io, api-arco.acmeapp.io', 'acme-prod', '', '']);

        $tester->execute(['--name' => 'shop', '--region' => 'us-east-1']);

        $tester->assertCommandIsSuccessful();
        $config = self::config($project);
        self::assertSame(['arco.acmeapp.io', 'api-arco.acmeapp.io'], $config->domains);
        self::assertSame('acme-prod', $config->dnsProfile);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Use a custom domain for production?', $display);
        self::assertStringContainsString('Where is the DNS for acmeapp.io?', $display);
        self::assertStringContainsString('Route 53 in profile p', $display);
        self::assertStringContainsString("Outside AWS — I'll create the records myself", $display);
        self::assertStringContainsString('found zone acmeapp.io in profile acme-prod (account 222222222222)', $display);
        self::assertSame(['acme-prod:ListHostedZonesByName', 'acme-prod:GetCallerIdentity'], $zones->calls);
        $project->restore();
    }

    public function test_interactive_default_answer_is_no_domain(): void
    {
        $project = new ProjectFixture();
        $tester = self::init($project);
        $tester->setInputs(['', '', '']);

        $tester->execute(['--name' => 'shop', '--region' => 'us-east-1']);

        $tester->assertCommandIsSuccessful();
        self::assertSame([], self::config($project)->domains);
        $project->restore();
    }

    public function test_interactive_reasks_where_the_dns_is_when_the_profile_lacks_the_zone(): void
    {
        $project = new ProjectFixture();
        $zones = new FakeZones([
            'p' => [AwsMocks::error(Route53Exception::class, 'ListHostedZonesByName', 'AccessDenied', 403, 'not authorized')],
            'acme-prod' => FakeZones::holding('222222222222', ['acmeapp.io' => '/hostedzone/Z1']),
        ]);
        $tester = self::init($project, $zones);
        $tester->setInputs(['yes', 'arco.acmeapp.io', 'p', 'acme-prod', '', '']);

        $tester->execute(['--name' => 'shop', '--region' => 'us-east-1']);

        $tester->assertCommandIsSuccessful();
        self::assertSame('acme-prod', self::config($project)->dnsProfile);
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('Profile p could not be checked: AccessDenied: not authorized', $display);
        self::assertSame(2, substr_count($display, 'Where is the DNS for acmeapp.io?'));
        $project->restore();
    }

    public function test_interactive_manual_dns_makes_no_aws_call(): void
    {
        $project = new ProjectFixture();
        $zones = new FakeZones([]);
        $tester = self::init($project, $zones);
        $tester->setInputs(['yes', 'arco.acmeapp.io', '@manual', '', '']);

        $tester->execute(['--name' => 'shop', '--region' => 'us-east-1']);

        $tester->assertCommandIsSuccessful();
        self::assertTrue(self::config($project)->dnsManual);
        self::assertSame([], $zones->calls);
        $project->restore();
    }

    public function test_interactive_deploy_account_is_the_default(): void
    {
        // Unknown deploy profile: accepted without a lookup.
        $project = new ProjectFixture();
        $zones = new FakeZones([]);
        $tester = self::init($project, $zones);
        $tester->setInputs(['yes', 'arco.acmeapp.io', '', '', '']);
        $tester->execute(['--name' => 'shop', '--region' => 'us-east-1']);
        $tester->assertCommandIsSuccessful();
        self::assertNull(self::config($project)->dnsProfile);
        self::assertFalse(self::config($project)->dnsManual);
        self::assertSame([], $zones->calls);
        $project->restore();

        // Known deploy profile: shown in the label and checked.
        $project = new ProjectFixture();
        putenv('AWS_PROFILE=p');
        $zones = new FakeZones(['p' => FakeZones::holding('111111111111', ['acmeapp.io' => '/hostedzone/Z1'])]);
        $tester = self::init($project, $zones);
        $tester->setInputs(['yes', 'arco.acmeapp.io', '', '', '']);
        $tester->execute(['--name' => 'shop', '--region' => 'us-east-1']);
        putenv('AWS_PROFILE');
        $tester->assertCommandIsSuccessful();
        self::assertNull(self::config($project)->dnsProfile);
        self::assertStringContainsString('Route 53 in the deploy account (profile p)', $tester->getDisplay());
        self::assertSame(['p:ListHostedZonesByName', 'p:GetCallerIdentity'], $zones->calls);
        $project->restore();
    }

    private static function laravelProject(ProjectFixture $project, string $connection): void
    {
        file_put_contents("$project->dir/artisan", '<?php');
        file_put_contents("$project->dir/composer.json", (string) json_encode(['require' => ['laravel/framework' => '^13.0']]));
        file_put_contents("$project->dir/.env", "DB_CONNECTION=$connection\n");
    }

    public function test_proposes_migrations_on_deploy_for_laravel_apps_with_a_server_database(): void
    {
        $project = new ProjectFixture();
        self::laravelProject($project, 'mysql');
        $tester = self::laravelInit($project);
        $tester->setInputs(['no', 'yes', 'yes', '']); // no custom domain; console; yes to migrations; retention

        $tester->execute(['--name' => 'shop', '--region' => 'us-east-1']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('mysql', $tester->getDisplay());
        $config = ConfigResolver::resolve((new ConfigLoader())->load("$project->dir/arco.php"), 'production');
        self::assertSame(['migrate --force'], $config->deployCommands);
        self::assertSame('app', $config->sources['deployCommands']);
        self::assertSame([], (new Validator())->validate($config, $project->dir)->warnings, 'the proposed command needs no confirmation in production');
        self::assertStringNotContainsString('WARNING', $tester->getDisplay());
        $project->restore();
    }

    public function test_declining_the_migration_proposal_writes_no_deploy_commands(): void
    {
        $project = new ProjectFixture();
        self::laravelProject($project, '"pgsql"');
        $tester = self::laravelInit($project);
        $tester->setInputs(['no', 'yes', 'no', '']); // console yes, migrations no

        $tester->execute(['--name' => 'shop', '--region' => 'us-east-1']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('pgsql', $tester->getDisplay());
        self::assertStringNotContainsString('deployCommands', (string) file_get_contents("$project->dir/arco.php"));
        $project->restore();
    }

    public function test_no_migration_proposal_for_sqlite_or_without_a_terminal(): void
    {
        $project = new ProjectFixture();
        self::laravelProject($project, 'sqlite');
        $tester = self::laravelInit($project);
        $tester->setInputs(['no', '', '']);
        $tester->execute(['--name' => 'shop', '--region' => 'us-east-1']);
        self::assertSame([], ConfigResolver::resolve((new ConfigLoader())->load("$project->dir/arco.php"), 'production')->deployCommands);

        self::laravelProject($project, 'pgsql');
        $tester->execute(['--name' => 'shop', '--region' => 'us-east-1', '--force' => true], ['interactive' => false]);
        self::assertSame([], ConfigResolver::resolve((new ConfigLoader())->load("$project->dir/arco.php"), 'production')->deployCommands);
        $project->restore();
    }

    /** @param list<array{task: string, minutes: list<int>}>|null $schedule what artisan reports */
    private static function laravelInit(ProjectFixture $project, ?array $schedule = null): CommandTester
    {
        return $project->tester(new InitCommand(new PresetRegistry([new LaravelPreset()]), null, new AppInspector(fn (): ?array => $schedule)));
    }

    /** A Laravel app with a queued job on 'emails', an S3 disk and the DynamoDB cache. */
    private static function fullLaravelApp(ProjectFixture $project, string $connection = 'sqlite'): void
    {
        self::laravelProject($project, $connection);
        file_put_contents("$project->dir/.env", "DB_CONNECTION=$connection\nFILESYSTEM_DISK=s3\nCACHE_STORE=dynamodb\n");
        mkdir("$project->dir/app/Jobs", 0777, true);
        file_put_contents("$project->dir/app/Jobs/SendWelcome.php", "<?php\n\nclass SendWelcome implements ShouldQueue\n{\n    public \$queue = 'emails';\n}\n");
    }

    /** The display on one line, without the "!" SymfonyStyle starts a wrapped note line with. */
    private static function flatDisplay(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+(!\s+)?/', ' ', $tester->getDisplay());
    }

    private static function assertValid(ProjectFixture $project): void
    {
        self::assertSame([], (new Validator())->validate(self::config($project), $project->dir)->errors);
    }

    public function test_interactive_accepts_everything_detected(): void
    {
        $project = new ProjectFixture();
        self::fullLaravelApp($project);
        $tester = self::laravelInit($project, [['task' => 'a', 'minutes' => [0, 5]]]);
        // domain, console, scheduler, interval, queues, queue names, failed jobs, storage, storage name, cache, retention
        $tester->setInputs(array_fill(0, 11, ''));

        $tester->execute(['--name' => 'shop', '--region' => 'us-east-1']);

        $tester->assertCommandIsSuccessful();
        $config = self::config($project);
        self::assertTrue($config->console);
        self::assertSame(5, $config->scheduler);
        self::assertSame(['default', 'emails'], array_map(fn ($queue) => $queue->name, $config->queues));
        self::assertNotNull($config->failedJobsTable);
        self::assertSame(['files'], array_map(fn ($storage) => $storage->name, $config->storage));
        self::assertNotNull($config->cacheTable);
        self::assertSame(30, $config->logRetention);
        self::assertSame('app', $config->sources['scheduler'] ?? null);
        self::assertStringContainsString('every 5 minutes or more often, for: a', self::flatDisplay($tester));
        self::assertValid($project);
        $project->restore();
    }

    public function test_interactive_picks_some_queues_and_declines_the_rest(): void
    {
        $project = new ProjectFixture();
        self::fullLaravelApp($project);
        $tester = self::laravelInit($project);
        // domain, console, queues, queue names, failed jobs, storage, cache, retention
        $tester->setInputs(['', 'no', '', 'emails', 'no', 'no', 'no', '']);

        $tester->execute(['--name' => 'shop', '--region' => 'us-east-1']);

        $tester->assertCommandIsSuccessful();
        $config = self::config($project);
        self::assertNull($config->console);
        self::assertSame(['emails'], array_map(fn ($queue) => $queue->name, $config->queues));
        self::assertNull($config->failedJobsTable);
        self::assertSame([], $config->storage);
        self::assertNull($config->cacheTable);
        self::assertSame(30, $config->logRetention);
        self::assertValid($project);
        $project->restore();
    }

    public function test_interactive_reasks_an_invalid_storage_name(): void
    {
        $project = new ProjectFixture();
        self::fullLaravelApp($project);
        $tester = self::laravelInit($project);
        // domain, console, queues, storage, storage names (two refused), cache, retention
        $tester->setInputs(['', 'no', 'no', '', 'public', 'Bad Name', 'uploads', 'no', '']);

        $tester->execute(['--name' => 'shop', '--region' => 'us-east-1']);

        $tester->assertCommandIsSuccessful();
        self::assertSame(['uploads'], array_map(fn ($storage) => $storage->name, self::config($project)->storage));
        $display = self::flatDisplay($tester);
        self::assertStringContainsString('Storage cannot be named "public"', $display);
        self::assertStringContainsString('Storage name "Bad Name" must start with a lowercase letter', $display);
        self::assertValid($project);
        $project->restore();
    }

    public function test_interactive_reasks_a_storage_name_whose_bucket_name_is_too_long(): void
    {
        $project = new ProjectFixture();
        self::fullLaravelApp($project);
        $tester = self::laravelInit($project);
        // domain, console, queues, storage, storage names (the default is too long), cache, retention
        $tester->setInputs(['', 'no', 'no', '', '', 'up', 'no', '']);

        // <app>-production-files-<account> is 65 characters with a 35-character app name; -up- fits in 63.
        $tester->execute(['--name' => str_repeat('a', 35), '--region' => 'us-east-1']);

        $tester->assertCommandIsSuccessful();
        self::assertSame(['up'], array_map(fn ($storage) => $storage->name, self::config($project)->storage));
        self::assertStringContainsString('is longer than 63 characters', self::flatDisplay($tester));
        self::assertValid($project);
        $project->restore();
    }

    public function test_interactive_leaves_out_detected_queues_whose_names_are_too_long(): void
    {
        $project = new ProjectFixture();
        self::laravelProject($project, 'sqlite');
        mkdir("$project->dir/app/Jobs", 0777, true);
        file_put_contents("$project->dir/app/Jobs/Notify.php", "<?php\n\nclass Notify implements ShouldQueue\n{\n    public \$queue = 'notifications';\n}\n");
        $tester = self::laravelInit($project);
        $tester->setInputs(['', 'no', '', '', '']); // domain, console, queues, failed jobs, retention

        // <35-character app>-production-queue-notifications is 66 characters, over the 64 of a function name.
        $tester->execute(['--name' => str_repeat('a', 35), '--region' => 'us-east-1']);

        $tester->assertCommandIsSuccessful();
        self::assertSame(['default'], array_map(fn ($queue) => $queue->name, self::config($project)->queues));
        self::assertStringContainsString('Left out queue(s) notifications', self::flatDisplay($tester));
        self::assertValid($project);
        $project->restore();
    }

    public function test_storage_flag_too_long_for_the_app_name_writes_nothing(): void
    {
        $project = new ProjectFixture();
        $tester = $project->tester(new InitCommand(new PresetRegistry([])));

        $tester->execute(['--name' => str_repeat('a', 40), '--region' => 'us-east-1', '--storage' => 'uploads'], ['interactive' => false]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertFileDoesNotExist("$project->dir/arco.php");
        self::assertStringContainsString('is longer than 63 characters', self::flatDisplay($tester));
        $project->restore();
    }

    public function test_a_config_that_would_not_validate_is_not_written(): void
    {
        $project = new ProjectFixture();
        $tester = $project->tester(new InitCommand(new PresetRegistry([])));

        $tester->execute(['--name' => str_repeat('a', 60), '--region' => 'us-east-1'], ['interactive' => false]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertFileDoesNotExist("$project->dir/arco.php");
        self::assertDirectoryDoesNotExist("$project->dir/.arco");
        self::assertStringContainsString('is longer than 64 characters', self::flatDisplay($tester));
        $project->restore();
    }

    public function test_declining_console_skips_migrate_and_scheduler(): void
    {
        $project = new ProjectFixture();
        self::laravelProject($project, 'pgsql');
        $tester = self::laravelInit($project, [['task' => 'a', 'minutes' => [0]]]);
        $tester->setInputs(['', 'no', '']); // domain, console, retention

        $tester->execute(['--name' => 'shop', '--region' => 'us-east-1']);

        $tester->assertCommandIsSuccessful();
        $php = (string) file_get_contents("$project->dir/arco.php");
        self::assertStringNotContainsString('deployCommands', $php);
        self::assertStringNotContainsString('scheduler', $php);
        self::assertStringNotContainsString('console', $php);
        self::assertStringNotContainsString('Run "migrate --force"', $tester->getDisplay());
        self::assertStringContainsString('Running migrations on deploy needs the console function', self::flatDisplay($tester));
        self::assertValid($project);
        $project->restore();
    }

    public function test_migrate_writes_console(): void
    {
        $project = new ProjectFixture();
        self::laravelProject($project, 'mysql');
        $tester = self::laravelInit($project);
        $tester->setInputs(['', 'yes', 'yes', '']); // domain, console, migrate, retention

        $tester->execute(['--name' => 'shop', '--region' => 'us-east-1']);

        $tester->assertCommandIsSuccessful();
        $config = self::config($project);
        self::assertTrue($config->console);
        self::assertSame([DeployCommands::MIGRATE], $config->deployCommands);
        self::assertStringContainsString("    ->deployCommands(['migrate --force'])\n    ->console()\n", (string) file_get_contents("$project->dir/arco.php"));
        self::assertValid($project);
        $project->restore();
    }

    public function test_migrate_flag_without_console_flag_turns_the_console_on(): void
    {
        $project = new ProjectFixture();
        self::laravelProject($project, 'mysql');
        $tester = self::laravelInit($project);

        $tester->execute(['--name' => 'shop', '--region' => 'us-east-1', '--migrate' => true], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        $config = self::config($project);
        self::assertTrue($config->console);
        self::assertSame([DeployCommands::MIGRATE], $config->deployCommands);
        self::assertValid($project);
        $project->restore();
    }

    public function test_schedule_unread_suggests_every_minute(): void
    {
        $project = new ProjectFixture();
        self::laravelProject($project, 'sqlite');
        mkdir("$project->dir/routes");
        file_put_contents("$project->dir/routes/console.php", "<?php\n\nSchedule::command('reports:send')->hourly();\n");
        $tester = self::laravelInit($project, []);
        $tester->setInputs(['', '', '', '', '']); // domain, console, scheduler, interval, retention

        $tester->execute(['--name' => 'shop', '--region' => 'us-east-1']);

        $tester->assertCommandIsSuccessful();
        self::assertSame(1, self::config($project)->scheduler);
        self::assertStringContainsString('their times could not be read', self::flatDisplay($tester));
        $project->restore();
    }

    public function test_non_interactive_writes_only_flags_and_prints_hints(): void
    {
        $project = new ProjectFixture();
        self::fullLaravelApp($project);
        $tester = self::laravelInit($project, [['task' => 'a', 'minutes' => [0, 5]]]);

        $tester->execute(['--name' => 'shop', '--region' => 'us-east-1'], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        $config = self::config($project);
        self::assertNull($config->console);
        self::assertNull($config->scheduler);
        self::assertNull($config->logRetention);
        self::assertSame([], $config->queues);
        self::assertSame([], $config->storage);
        self::assertNull($config->cacheTable);
        self::assertNull($config->failedJobsTable);
        $display = $tester->getDisplay();
        foreach (['arco console:add', 'arco scheduler:set 5', 'arco logs:retention 30', 'arco queue:add default', 'arco queue:add emails', 'arco storage:add files', 'arco cache:add'] as $hint) {
            self::assertSame(1, substr_count($display, $hint), $hint);
        }
        $project->restore();
    }

    public function test_non_interactive_non_laravel_project_prints_no_hints(): void
    {
        $project = new ProjectFixture();
        $tester = $project->tester(new InitCommand(new PresetRegistry([])));

        $tester->execute(['--name' => 'shop', '--region' => 'us-east-1'], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        self::assertStringNotContainsString('arco console:add', $tester->getDisplay());
        self::assertStringNotContainsString('WARNING', $tester->getDisplay());
        $project->restore();
    }

    public function test_flags_without_questions(): void
    {
        $project = new ProjectFixture();
        $tester = $project->tester(new InitCommand(new PresetRegistry([])));

        $tester->execute([
            '--name' => 'shop', '--region' => 'us-east-1', '--console' => true, '--scheduler' => '10',
            '--queue' => ['default', 'emails'], '--storage' => 'uploads', '--cache' => true, '--failed-jobs-table' => true, '--log-retention' => '14',
        ], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString(
            "    ->region('us-east-1')\n    ->console()\n    ->scheduler(10)\n    ->logRetention(14)\n    ->queue('default')\n    ->queue('emails')\n    ->failedJobsTable()\n    ->storage('uploads')\n    ->cache()\n    ->environment(",
            (string) file_get_contents("$project->dir/arco.php"),
        );
        self::assertValid($project);
        $project->restore();
    }

    public function test_flags_answer_their_questions(): void
    {
        $project = new ProjectFixture();
        self::fullLaravelApp($project);
        $tester = self::laravelInit($project, [['task' => 'a', 'minutes' => [0, 5]]]);
        $tester->setInputs(['', '', '']); // domain, failed jobs, cache

        $tester->execute(['--name' => 'shop', '--region' => 'us-east-1', '--scheduler' => '15', '--queue' => ['emails'], '--storage' => 'uploads', '--log-retention' => '7']);

        $tester->assertCommandIsSuccessful();
        $config = self::config($project);
        self::assertTrue($config->console);
        self::assertSame(15, $config->scheduler);
        self::assertSame(7, $config->logRetention);
        self::assertSame(['emails'], array_map(fn ($queue) => $queue->name, $config->queues));
        self::assertSame(['uploads'], array_map(fn ($storage) => $storage->name, $config->storage));
        self::assertNotNull($config->failedJobsTable);
        self::assertNotNull($config->cacheTable);
        self::assertValid($project);
        $project->restore();
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function invalidFlags(): array
    {
        return [
            'scheduler interval' => [['--scheduler' => '7']],
            'log retention' => [['--log-retention' => '10']],
            'reserved storage' => [['--storage' => 'public']],
            'storage name' => [['--storage' => 'Files']],
            'queue name' => [['--queue' => ['Bad Name']]],
            'scheduler without console' => [['--scheduler' => '5', '--no-console' => true]],
            'migrate without console' => [['--migrate' => true, '--no-console' => true]],
        ];
    }

    /** @param array<string, mixed> $flags */
    #[DataProvider('invalidFlags')]
    public function test_invalid_flags_write_nothing(array $flags): void
    {
        $project = new ProjectFixture();
        $tester = $project->tester(new InitCommand(new PresetRegistry([])));

        $tester->execute(['--name' => 'shop', '--region' => 'us-east-1'] + $flags, ['interactive' => false]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertFileDoesNotExist("$project->dir/arco.php");
        $project->restore();
    }

    public function test_non_laravel_project_asks_only_console_and_retention(): void
    {
        $project = new ProjectFixture();
        $tester = $project->tester(new InitCommand(new PresetRegistry([])));
        $tester->setInputs(['', 'yes', 'never']); // domain, console, retention

        $tester->execute(['--name' => 'shop', '--region' => 'us-east-1']);

        $tester->assertCommandIsSuccessful();
        $config = self::config($project);
        self::assertTrue($config->console);
        self::assertNull($config->logRetention);
        self::assertStringNotContainsString('WARNING', $tester->getDisplay());
        self::assertValid($project);
        $project->restore();
    }
}
