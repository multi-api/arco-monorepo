<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Commands;

use Multiapi\Arco\Commands\DomainAddCommand;
use Multiapi\Arco\Commands\DomainRemoveCommand;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ConfigLoader;
use Multiapi\Arco\Config\ConfigResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class DomainCommandsTest extends TestCase
{
    private const WITH_DOMAINS = <<<'PHP'
        <?php
        use Multiapi\Arco\Config\App;
        use Multiapi\Arco\Config\Environment;

        return App::make('shop')
            ->region('us-east-1')
            ->environment('production', fn (Environment $env) => $env
                ->domain(['arco.acmeapp.io', 'api-arco.acmeapp.io'])
                ->dns('common')
                ->timeout(60));
        PHP;

    private static function add(ProjectFixture $project, FakeZones $zones): CommandTester
    {
        return $project->tester(new DomainAddCommand(null, $zones->finder()));
    }

    private static function config(ProjectFixture $project, string $environment = 'production'): AppConfig
    {
        return ConfigResolver::resolve((new ConfigLoader())->load("$project->dir/arco.php"), $environment);
    }

    private static function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    public function test_add_with_a_dns_profile_edits_the_environment_closure(): void
    {
        $project = new ProjectFixture(ProjectFixture::standardConfig());
        $zones = new FakeZones(['common' => FakeZones::holding('222222222222', ['acmeapp.io' => '/hostedzone/Z1'])]);

        $tester = self::add($project, $zones);
        $tester->execute(['hosts' => ['arco.acmeapp.io', 'API-arco.acmeapp.io'], '--env' => 'production', '--dns-profile' => 'common'], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        $config = self::config($project);
        self::assertSame(['arco.acmeapp.io', 'api-arco.acmeapp.io'], $config->domains);
        self::assertSame('common', $config->dnsProfile);
        self::assertSame(60, $config->timeout);
        self::assertSame(['common:ListHostedZonesByName', 'common:GetCallerIdentity'], $zones->calls);
        self::assertStringContainsString("->domain(['arco.acmeapp.io', 'api-arco.acmeapp.io']) ->dns('common')", self::display($tester));
        self::assertStringContainsString('Run `arco deploy --env=production` to apply.', self::display($tester));
        $project->restore();
    }

    public function test_add_to_an_iam_environment_is_allowed(): void
    {
        $project = new ProjectFixture(<<<'PHP'
            <?php
            use Multiapi\Arco\Config\App;
            use Multiapi\Arco\Config\Environment;

            return App::make('shop')
                ->region('us-east-1')
                ->auth('iam')
                ->environment('production', fn (Environment $env) => $env->timeout(60));
            PHP);

        $tester = self::add($project, new FakeZones([]));
        $tester->execute(['hosts' => ['arco.acmeapp.io'], '--env' => 'production', '--dns' => 'manual'], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        self::assertSame(['arco.acmeapp.io'], self::config($project)->domains);
        self::assertSame('iam', self::config($project)->auth);
        $project->restore();
    }

    public function test_add_to_a_bare_environment_whose_zone_is_in_the_deploy_profile(): void
    {
        $project = new ProjectFixture(ProjectFixture::standardConfig());
        $zones = new FakeZones(['p' => FakeZones::holding('111111111111', ['acmeapp.io' => '/hostedzone/Z1']), 'acme-prod' => FakeZones::empty()]);

        $tester = self::add($project, $zones);
        $tester->execute(['hosts' => ['staging.acmeapp.io'], '--env' => 'staging', '--profile' => 'p'], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        $config = self::config($project, 'staging');
        self::assertSame(['staging.acmeapp.io'], $config->domains);
        self::assertNull($config->dnsProfile);
        self::assertFalse($config->dnsManual);
        self::assertSame([], self::config($project)->domains);
        self::assertStringNotContainsString('->dns(', (string) file_get_contents("$project->dir/arco.php"));
        $project->restore();
    }

    public function test_add_merges_and_keeps_a_dns_profile_that_holds_the_zones(): void
    {
        $project = new ProjectFixture(self::WITH_DOMAINS);
        $zones = new FakeZones(['common' => FakeZones::holding('222222222222', ['acmeapp.io' => '/hostedzone/Z1'])]);

        $tester = self::add($project, $zones);
        $tester->execute(['hosts' => ['www.acmeapp.io', 'arco.acmeapp.io'], '--env' => 'production'], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        $config = self::config($project);
        self::assertSame(['arco.acmeapp.io', 'api-arco.acmeapp.io', 'www.acmeapp.io'], $config->domains);
        self::assertSame('common', $config->dnsProfile);
        self::assertSame(['common:ListHostedZonesByName', 'common:GetCallerIdentity'], $zones->calls);
        $project->restore();
    }

    public function test_add_asks_where_the_dns_is_when_the_dns_profile_lacks_a_zone(): void
    {
        $project = new ProjectFixture(self::WITH_DOMAINS);
        $zones = new FakeZones([
            'common' => FakeZones::holding('222222222222', ['acmeapp.io' => '/hostedzone/Z1']),
            'p' => FakeZones::holding('111111111111', ['acmeapp.io' => '/hostedzone/Z1', 'example.com' => '/hostedzone/Z2']),
        ]);
        $tester = self::add($project, $zones);
        $tester->setInputs(['p']);

        $tester->execute(['hosts' => ['www.example.com'], '--env' => 'production']);

        $tester->assertCommandIsSuccessful();
        self::assertSame('p', self::config($project)->dnsProfile);
        self::assertSame([], array_filter($zones->calls, fn (string $c) => str_starts_with($c, 'acme-prod:')));
        self::assertStringContainsString('Profile common has no Route 53 zone for: www.example.com', self::display($tester));
        self::assertMatchesRegularExpression('/Where is the DNS for acmeapp.io, example.com\? \[Route 53 in profile common\]/', self::display($tester));
        $project->restore();
    }

    public function test_add_never_changes_an_explicit_dns_profile_without_being_told(): void
    {
        $project = new ProjectFixture(self::WITH_DOMAINS);
        $before = file_get_contents("$project->dir/arco.php");
        $zones = new FakeZones([
            'common' => FakeZones::holding('222222222222', ['acmeapp.io' => '/hostedzone/Z1']),
            'p' => FakeZones::holding('111111111111', ['acmeapp.io' => '/hostedzone/Z1', 'example.com' => '/hostedzone/Z2']),
        ]);

        $tester = self::add($project, $zones);
        $tester->execute(['hosts' => ['www.example.com'], '--env' => 'production', '--profile' => 'p'], ['interactive' => false]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertSame($before, file_get_contents("$project->dir/arco.php"));
        self::assertStringContainsString('Profile common has no Route 53 zone for: www.example.com', self::display($tester));
        self::assertStringContainsString('--dns-profile=<profile>', self::display($tester));
        self::assertSame(['common:ListHostedZonesByName', 'common:GetCallerIdentity'], $zones->calls);
        $project->restore();
    }

    public function test_add_writes_nothing_when_one_of_the_two_edits_is_not_possible(): void
    {
        $project = new ProjectFixture(str_replace("->dns('common')", "->dns(getenv('ARCO_TEST_DNS') ?: 'common')", self::WITH_DOMAINS));
        $before = file_get_contents("$project->dir/arco.php");
        $zones = new FakeZones(['other' => FakeZones::holding('333333333333', ['acmeapp.io' => '/hostedzone/Z1'])]);

        $tester = self::add($project, $zones);
        $tester->execute(['hosts' => ['www.acmeapp.io'], '--env' => 'production', '--dns-profile' => 'other'], ['interactive' => false]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertSame($before, file_get_contents("$project->dir/arco.php"));
        self::assertStringContainsString("->domain(['arco.acmeapp.io', 'api-arco.acmeapp.io', 'www.acmeapp.io']) ->dns('other')", self::display($tester));
        $project->restore();
    }

    public function test_add_keeps_manual_dns_without_lookups(): void
    {
        $project = new ProjectFixture(str_replace("->dns('common')", '->dns(false)', self::WITH_DOMAINS));
        $zones = new FakeZones([]);

        $tester = self::add($project, $zones);
        $tester->execute(['hosts' => ['www.example.com'], '--env' => 'production', '--profile' => 'p'], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        self::assertTrue(self::config($project)->dnsManual);
        self::assertContains('www.example.com', self::config($project)->domains);
        self::assertSame([], $zones->calls);
        $project->restore();
    }

    public function test_add_fails_without_writing_when_the_deploy_profile_lacks_the_zone(): void
    {
        $project = new ProjectFixture(ProjectFixture::standardConfig());
        $before = file_get_contents("$project->dir/arco.php");
        $zones = new FakeZones(['p' => FakeZones::empty()]);

        $tester = self::add($project, $zones);
        $tester->execute(['hosts' => ['www.example.com'], '--env' => 'production', '--profile' => 'p'], ['interactive' => false]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertSame($before, file_get_contents("$project->dir/arco.php"));
        self::assertStringContainsString('Profile p has no Route 53 zone for: www.example.com', self::display($tester));
        self::assertStringContainsString('--dns=manual', self::display($tester));
        self::assertSame(['p:ListHostedZonesByName'], $zones->calls);
        $project->restore();
    }

    public function test_add_refuses_invalid_hosts_and_app_level_domains(): void
    {
        $project = new ProjectFixture(ProjectFixture::standardConfig());
        $tester = self::add($project, new FakeZones([]));
        $tester->execute(['hosts' => ['*.example.com'], '--env' => 'production', '--dns' => 'manual'], ['interactive' => false]);
        self::assertSame(1, $tester->getStatusCode());
        $project->restore();

        $appLevel = "<?php\nuse Multiapi\\Arco\\Config\\App;\nreturn App::make('shop')->region('us-east-1')->domain('a.example.com')->environment('production');\n";
        $project = new ProjectFixture($appLevel);
        $tester = self::add($project, new FakeZones([]));
        $tester->execute(['hosts' => ['b.example.com'], '--env' => 'production', '--dns' => 'manual'], ['interactive' => false]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertSame($appLevel, file_get_contents("$project->dir/arco.php"));
        self::assertStringContainsString("->domain(['a.example.com', 'b.example.com']) ->dns(false)", self::display($tester));
        $project->restore();
    }

    public function test_add_interactive_manual_dns_when_nothing_is_found(): void
    {
        $project = new ProjectFixture(ProjectFixture::standardConfig());
        $zones = new FakeZones(['p' => FakeZones::empty(), 'acme-prod' => FakeZones::empty()]);
        $tester = self::add($project, $zones);
        $tester->setInputs(['@manual']);

        $tester->execute(['hosts' => ['www.example.com'], '--env' => 'production']);

        $tester->assertCommandIsSuccessful();
        self::assertTrue(self::config($project)->dnsManual);
        $project->restore();
    }

    public function test_remove_one_domain_then_the_last_keeps_dns(): void
    {
        $project = new ProjectFixture(self::WITH_DOMAINS);

        $tester = $project->tester(new DomainRemoveCommand());
        $tester->execute(['hosts' => ['api-arco.acmeapp.io'], '--env' => 'production'], ['interactive' => false]);
        $tester->assertCommandIsSuccessful();
        self::assertSame(['arco.acmeapp.io'], self::config($project)->domains);

        $tester = $project->tester(new DomainRemoveCommand());
        $tester->execute(['hosts' => ['arco.acmeapp.io'], '--env' => 'production'], ['interactive' => false]);
        $tester->assertCommandIsSuccessful();
        $config = self::config($project);
        self::assertSame([], $config->domains);
        self::assertSame('common', $config->dnsProfile);
        self::assertSame(60, $config->timeout);
        self::assertStringNotContainsString('->domain(', (string) file_get_contents("$project->dir/arco.php"));
        self::assertStringContainsString('Run `arco deploy --env=production` to apply.', self::display($tester));
        $project->restore();
    }

    public function test_remove_fails_when_no_domain_matches(): void
    {
        $project = new ProjectFixture(self::WITH_DOMAINS);
        $before = file_get_contents("$project->dir/arco.php");

        $tester = $project->tester(new DomainRemoveCommand());
        $tester->execute(['hosts' => ['other.acmeapp.io'], '--env' => 'production'], ['interactive' => false]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertSame($before, file_get_contents("$project->dir/arco.php"));
        $project->restore();
    }
}
