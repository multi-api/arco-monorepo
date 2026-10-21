<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Commands;

use Aws\MockHandler;
use Aws\Result;
use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Build\PresetRegistry;
use Multiapi\Arco\Commands\DomainAddCommand;
use Multiapi\Arco\Commands\InfoCommand;
use Multiapi\Arco\Commands\InitCommand;
use Multiapi\Arco\Commands\RemoveCommand;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ConfigLoader;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Deploy\AppStatus;
use Multiapi\Arco\Deploy\Domains\CloudflareDns;
use Multiapi\Arco\Deploy\Domains\DnsProvider;
use Multiapi\Arco\Tests\Unit\Deploy\Domains\FakeCloudflare;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** dns('cloudflare') in remove, domain:add, init and info (deploy: DeployCommandTest). */
final class CloudflareCommandsTest extends TestCase
{
    private FakeCloudflare $cloudflare;
    /** @var list<string> */
    private array $tokens = [];

    protected function setUp(): void
    {
        $this->cloudflare = (new FakeCloudflare())->zone('acmeapp.io', 'zone-acme');
    }

    protected function tearDown(): void
    {
        putenv('CLOUDFLARE_API_TOKEN');
    }

    private function factory(): \Closure
    {
        return function (string $token) {
            $this->tokens[] = $token;
            return $this->cloudflare->api($token);
        };
    }

    private static function withDomains(string $dns = "->dns('cloudflare')"): string
    {
        return str_replace('->timeout(60)', "->timeout(60)->domain(['arco.acmeapp.io', 'api-arco.acmeapp.io'])$dns", ProjectFixture::standardConfig());
    }

    private static function config(ProjectFixture $project, string $environment = 'production'): AppConfig
    {
        return ConfigResolver::resolve((new ConfigLoader())->load("$project->dir/arco.php"), $environment);
    }

    private static function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    /** @param list<?DnsProvider> $providers @param list<?string> $dnsProfiles */
    private function remove(FakeDeployer $deployer, array &$providers, array &$dnsProfiles): RemoveCommand
    {
        $mock = new MockHandler([new Result(['Account' => '111111111111'])]);
        return new RemoveCommand(
            awsFactory: fn (?string $profile, string $region) => new AwsClients($profile, $region, ['handler' => $mock, 'credentials' => false]),
            deployerFactory: function (AwsClients $aws, ?AwsClients $dns, bool $forceDns = false, ?DnsProvider $provider = null) use ($deployer, &$providers, &$dnsProfiles) {
                $providers[] = $provider;
                $dnsProfiles[] = $dns?->profile;
                return $deployer;
            },
            cloudflareFactory: $this->factory(),
        );
    }

    public function test_remove_without_a_token_deletes_nothing(): void
    {
        $project = new ProjectFixture(self::withDomains());
        $deployer = new FakeDeployer(status: new AppStatus('shop-production', 'Active', 'x.on.aws', 'rev-1'), domainResources: true);
        $providers = $dnsProfiles = [];

        $tester = $project->tester($this->remove($deployer, $providers, $dnsProfiles));
        $tester->execute(['--env' => 'production', '--profile' => 'p', '--yes' => true], ['interactive' => false]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('CLOUDFLARE_API_TOKEN', self::display($tester));
        self::assertStringContainsString('Zone:DNS:Edit + Zone:Read', self::display($tester));
        self::assertNotContains('remove', $deployer->calls);
        self::assertSame([null], $dnsProfiles, 'no Route 53 clients');
        $project->restore();
    }

    public function test_remove_checks_the_token_and_hands_the_cloudflare_provider_to_the_deployer(): void
    {
        $project = new ProjectFixture(self::withDomains());
        putenv('CLOUDFLARE_API_TOKEN=env-token');
        $deployer = new FakeDeployer(status: new AppStatus('shop-production', 'Active', 'x.on.aws', 'rev-1'), domainResources: true);
        $providers = $dnsProfiles = [];

        $tester = $project->tester($this->remove($deployer, $providers, $dnsProfiles));
        $tester->execute(['--env' => 'production', '--profile' => 'p', '--yes' => true], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        self::assertContains('remove', $deployer->calls);
        self::assertSame(['env-token'], $this->tokens);
        self::assertSame(['GET zones?per_page=5'], $this->cloudflare->log);
        self::assertContainsOnlyInstancesOf(CloudflareDns::class, $providers);
        self::assertSame([null], $dnsProfiles);
        $project->restore();
    }

    public function test_remove_with_a_refused_token_deletes_nothing(): void
    {
        $project = new ProjectFixture(self::withDomains());
        $this->cloudflare->queue(FakeCloudflare::json(401, ['success' => false, 'errors' => [['code' => 10000, 'message' => 'Authentication error']]]));
        $deployer = new FakeDeployer(status: new AppStatus('shop-production', 'Active', 'x.on.aws', 'rev-1'), domainResources: true);
        $providers = $dnsProfiles = [];

        $tester = $project->tester($this->remove($deployer, $providers, $dnsProfiles));
        $tester->execute(['--env' => 'production', '--profile' => 'p', '--yes' => true, '--cloudflare-token' => FakeCloudflare::TOKEN], ['interactive' => false]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Cloudflare refused the API token', self::display($tester));
        self::assertStringNotContainsString(FakeCloudflare::TOKEN, $tester->getDisplay());
        self::assertNotContains('remove', $deployer->calls);
        $project->restore();
    }

    private function domainAdd(FakeZones $zones): DomainAddCommand
    {
        return new DomainAddCommand(null, $zones->finder(), $this->factory());
    }

    public function test_domain_add_with_dns_cloudflare_checks_the_zones_and_writes_dns_cloudflare(): void
    {
        $project = new ProjectFixture(ProjectFixture::standardConfig());
        $zones = new FakeZones([]);

        $tester = $project->tester($this->domainAdd($zones));
        $tester->execute(['hosts' => ['arco.acmeapp.io'], '--env' => 'production', '--dns' => 'cloudflare', '--cloudflare-token' => 'flag-token'], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        $config = self::config($project);
        self::assertTrue($config->dnsCloudflare);
        self::assertNull($config->dnsProfile);
        self::assertStringContainsString("->dns('cloudflare')", (string) file_get_contents("$project->dir/arco.php"));
        self::assertStringContainsString('arco.acmeapp.io: found zone acmeapp.io in Cloudflare', self::display($tester));
        self::assertSame(['flag-token'], $this->tokens);
        self::assertSame([], $zones->calls, 'Route 53 is not asked');
        $project->restore();
    }

    public function test_domain_add_with_dns_cloudflare_and_no_token_writes_it_and_defers_the_check(): void
    {
        $project = new ProjectFixture(ProjectFixture::standardConfig());

        $tester = $project->tester($this->domainAdd(new FakeZones([])));
        $tester->execute(['hosts' => ['arco.acmeapp.io'], '--env' => 'production', '--dns' => 'cloudflare'], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        self::assertTrue(self::config($project)->dnsCloudflare);
        self::assertStringContainsString('the zones will be checked when you deploy', self::display($tester));
        self::assertSame([], $this->cloudflare->log);
        $project->restore();
    }

    public function test_domain_add_with_dns_cloudflare_fails_without_writing_when_a_zone_is_missing(): void
    {
        $project = new ProjectFixture(ProjectFixture::standardConfig());
        putenv('CLOUDFLARE_API_TOKEN=env-token');

        $tester = $project->tester($this->domainAdd(new FakeZones([])));
        $tester->execute(['hosts' => ['www.example.org'], '--env' => 'production', '--dns' => 'cloudflare'], ['interactive' => false]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Cloudflare has no zone the API token can see for: www.example.org', self::display($tester));
        self::assertSame([], self::config($project)->domains);
        $project->restore();
    }

    public function test_domain_add_offers_cloudflare_when_asking_where_the_dns_is(): void
    {
        $project = new ProjectFixture(ProjectFixture::standardConfig());
        putenv('CLOUDFLARE_API_TOKEN=env-token');
        $zones = new FakeZones(['acme-prod' => FakeZones::empty()]);

        $tester = $project->tester($this->domainAdd($zones));
        $tester->setInputs(['@cloudflare']);
        $tester->execute(['hosts' => ['arco.acmeapp.io'], '--env' => 'production']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('[@cloudflare] Cloudflare', self::display($tester));
        self::assertTrue(self::config($project)->dnsCloudflare);
        $project->restore();
    }

    public function test_domain_add_keeps_dns_cloudflare_when_its_zones_hold_the_new_domain(): void
    {
        $project = new ProjectFixture(self::withDomains());
        putenv('CLOUDFLARE_API_TOKEN=env-token');
        $zones = new FakeZones([]);

        $tester = $project->tester($this->domainAdd($zones));
        $tester->execute(['hosts' => ['www.acmeapp.io'], '--env' => 'production'], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        $config = self::config($project);
        self::assertSame(['arco.acmeapp.io', 'api-arco.acmeapp.io', 'www.acmeapp.io'], $config->domains);
        self::assertTrue($config->dnsCloudflare);
        self::assertSame([], $zones->calls);
        self::assertSame(1, substr_count((string) file_get_contents("$project->dir/arco.php"), "->dns('cloudflare')"));
        $project->restore();
    }

    public function test_init_with_dns_cloudflare_writes_it_for_the_environments(): void
    {
        $project = new ProjectFixture();
        $zones = new FakeZones([]);

        $tester = $project->tester(new InitCommand(new PresetRegistry([]), $zones->finder(), null, $this->factory()));
        $tester->execute(['--name' => 'shop', '--region' => 'us-east-1', '--domain' => ['arco.acmeapp.io'], '--dns' => 'cloudflare', '--cloudflare-token' => 'flag-token'], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        self::assertTrue(self::config($project)->dnsCloudflare);
        self::assertStringContainsString("->dns('cloudflare')", (string) file_get_contents("$project->dir/arco.php"));
        self::assertStringNotContainsString('flag-token', (string) file_get_contents("$project->dir/arco.php"));
        self::assertSame([], $zones->calls);
        $project->restore();
    }

    public function test_info_shows_the_dns_provider_and_json_adds_the_dns_key(): void
    {
        $cases = [
            "->dns('cloudflare')" => ['cloudflare', 'cloudflare'],
            "->dns('acme-dns')" => ['route53 (acme-dns)', 'acme-dns'],
            '' => ['route53 (deploy account)', 'Route 53, deploy account'],
            '->dns(false)' => ['manual', 'manual (not Route 53)'],
        ];
        foreach ($cases as $dns => [$provider, $setting]) {
            $project = new ProjectFixture(self::withDomains($dns));
            $tester = $project->tester(new InfoCommand(deployerFactory: fn () => throw new \LogicException('no AWS expected')));

            $tester->execute(['--env' => 'production'], ['interactive' => false]);
            self::assertMatchesRegularExpression('/dns\.provider\s+' . preg_quote($provider, '/') . '\s/', $tester->getDisplay(), $dns);

            $tester->execute(['--env' => 'production', '--json' => true], ['interactive' => false]);
            $json = json_decode($tester->getDisplay(), true);
            self::assertSame($provider, $json['dns'], $dns);
            self::assertSame($setting, $json['settings']['dns'], "$dns: settings.dns is unchanged for Route 53 and manual");
            $project->restore();
        }

        $project = new ProjectFixture(ProjectFixture::standardConfig());
        $tester = $project->tester(new InfoCommand(deployerFactory: fn () => throw new \LogicException('no AWS expected')));
        $tester->execute(['--env' => 'production', '--json' => true], ['interactive' => false]);
        self::assertNull(json_decode($tester->getDisplay(), true)['dns'], 'no domains, no provider');
        $project->restore();
    }
}
