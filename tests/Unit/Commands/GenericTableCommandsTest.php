<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Commands;

use Multiapi\Arco\Cli\Application;
use Multiapi\Arco\Commands\InfoCommand;
use Multiapi\Arco\Commands\TableAddCommand;
use Multiapi\Arco\Commands\TableRemoveCommand;
use Multiapi\Arco\Config\ArcoFileEditor;
use Multiapi\Arco\Config\ConfigLoader;
use Multiapi\Arco\Config\ConfigResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class GenericTableCommandsTest extends TestCase
{
    private ?ProjectFixture $project = null;

    protected function tearDown(): void
    {
        putenv('AWS_PROFILE');
        $this->project?->restore();
    }

    private function project(string $calls = ''): ProjectFixture
    {
        return $this->project = new ProjectFixture(str_replace('->concurrency(48)', '->concurrency(48)' . $calls, ProjectFixture::standardConfig()));
    }

    /** @param array<string, mixed> $input */
    private function run_(Command $command, array $input): CommandTester
    {
        $tester = $this->project?->tester($command) ?? throw new \LogicException('No project.');
        $tester->execute($input, ['interactive' => false]);
        return $tester;
    }

    private function source(): string
    {
        return (string) file_get_contents($this->project?->dir . '/arco.php');
    }

    private static function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    public function test_both_commands_are_registered(): void
    {
        $app = Application::create();
        self::assertTrue($app->has('table:add'));
        self::assertTrue($app->has('table:remove'));
    }

    public function test_add_writes_the_table_with_ttl_in_the_environment(): void
    {
        $this->project();

        $tester = $this->run_(new TableAddCommand(), ['name' => 'state', '--ttl' => 'expires_at', '--env' => 'production']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString("->table('state', ttl: 'expires_at')", $this->source());
        self::assertStringContainsString('Table state added (production).', self::display($tester));
        self::assertStringContainsString('Run `arco deploy --env=production` to apply.', self::display($tester));
        $config = ConfigResolver::resolve((new ConfigLoader())->load($this->project?->dir . '/arco.php'), 'production');
        self::assertSame('expires_at', $config->tables[0]->ttl);
        self::assertSame([], ConfigResolver::resolve((new ConfigLoader())->load($this->project?->dir . '/arco.php'), 'staging')->tables);
    }

    public function test_add_existing_at_app_level(): void
    {
        $this->project();

        $tester = $this->run_(new TableAddCommand(), ['name' => 'shared', '--existing' => 'legacy-table', '--app' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString("->table('shared', existing: 'legacy-table')", $this->source());
        self::assertStringContainsString('Deploy each environment to apply', self::display($tester));
    }

    public function test_add_duplicate_name_fails(): void
    {
        $this->project("->table('state')");
        $before = $this->source();

        $tester = $this->run_(new TableAddCommand(), ['name' => 'state', '--app' => true]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Table "state" already exists in the app (all environments).', self::display($tester));
        self::assertSame($before, $this->source());
    }

    public function test_add_reserved_names_are_rejected_and_file_unchanged(): void
    {
        $this->project();
        $before = $this->source();

        foreach (['cache', 'failed-jobs'] as $name) {
            $tester = $this->run_(new TableAddCommand(), ['name' => $name, '--app' => true]);
            self::assertSame(1, $tester->getStatusCode());
            self::assertStringContainsString("\"$name\" is reserved", self::display($tester));
        }
        self::assertSame($before, $this->source());
    }

    public function test_add_a_name_whose_table_is_the_cache_table_is_rejected(): void
    {
        $this->project("->cache(existing: 'shop-production-state')");
        $before = $this->source();

        $tester = $this->run_(new TableAddCommand(), ['name' => 'state', '--env' => 'production']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('shop-production-state', self::display($tester));
        self::assertStringContainsString('cache()', self::display($tester));
        self::assertSame($before, $this->source());
    }

    public function test_add_invalid_existing_is_rejected(): void
    {
        $this->project();
        $before = $this->source();

        $tester = $this->run_(new TableAddCommand(), ['name' => 'state', '--existing' => 'bad name', '--app' => true]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('existing must be a DynamoDB table name (3 to 255 letters, digits, underscores, dots and hyphens) or table ARN', self::display($tester));
        self::assertSame($before, $this->source());
    }

    public function test_remove_at_same_level_keeps_the_table(): void
    {
        $this->project("->table('state', ttl: 'expires_at')");

        $tester = $this->run_(new TableRemoveCommand(), ['name' => 'state', '--app' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringNotContainsString("table('state'", $this->source());
        self::assertStringContainsString('Table state removed (app).', self::display($tester));
        self::assertStringContainsString('The DynamoDB table and its items are kept in AWS', self::display($tester));
        self::assertArrayNotHasKey('tables', (new ConfigLoader())->load($this->project?->dir . '/arco.php'));
    }

    public function test_remove_inherited_table_with_env_writes_false(): void
    {
        $this->project("->table('state')");

        $tester = $this->run_(new TableRemoveCommand(), ['name' => 'state', '--env' => 'production']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString("->table('state', false)", $this->source());
        self::assertStringContainsString("->table('state')", $this->source());
        self::assertSame([], ConfigResolver::resolve((new ConfigLoader())->load($this->project?->dir . '/arco.php'), 'production')->tables);
        self::assertCount(1, ConfigResolver::resolve((new ConfigLoader())->load($this->project?->dir . '/arco.php'), 'staging')->tables);
    }

    public function test_remove_missing_table_not_found(): void
    {
        $this->project();

        $tester = $this->run_(new TableRemoveCommand(), ['name' => 'nope', '--env' => 'production']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Table "nope" is not configured in environment production.', self::display($tester));
    }

    public function test_the_editor_writes_and_removes_table_calls(): void
    {
        $this->project();
        $path = $this->project?->dir . '/arco.php';

        self::assertTrue(ArcoFileEditor::setNamed($path, 'staging', 'table', 'state', ['ttl' => 'expires_at', 'existing' => 'x-table']));
        self::assertStringContainsString("->table('state', existing: 'x-table', ttl: 'expires_at')", $this->source());
        self::assertTrue(ArcoFileEditor::setNamed($path, 'staging', 'table', 'state', null));
        self::assertStringNotContainsString("->table(", $this->source());
        self::assertSame("->table('state', false)", ArcoFileEditor::namedLine('table', 'state', false));
    }

    public function test_info_lists_the_tables(): void
    {
        $this->project("->table('state', ttl: 'expires_at')->table('shared', existing: 'legacy-table')");
        putenv('AWS_PROFILE=p');
        $status = new \Multiapi\Arco\Deploy\AppStatus('shop-production', 'Active', 'abc.on.aws', 'rev-1', account: '111111111111');
        $tester = $this->run_(new InfoCommand(deployerFactory: fn () => new FakeDeployer(status: $status)), ['--env' => 'production']);
        $display = self::display($tester);
        self::assertStringContainsString('Table state shop-production-state · TTL expires_at', $display);
        self::assertStringContainsString('Table shared legacy-table (existing, not managed by Arco)', $display);

        $tester = $this->run_(new InfoCommand(deployerFactory: fn () => new FakeDeployer(status: $status)), ['--env' => 'production', '--json' => true]);
        $json = json_decode($tester->getDisplay(), true);
        self::assertSame([
            ['name' => 'state', 'table' => 'shop-production-state', 'existing' => false, 'ttl' => 'expires_at'],
            ['name' => 'shared', 'table' => 'legacy-table', 'existing' => true, 'ttl' => null],
        ], $json['tables']);
        self::assertSame(['tables', 'permissions_boundary', 'endpoint_auth'], array_slice(array_keys($json), -3), 'tables was added last (0.10); permissions_boundary follows it (0.11), then endpoint_auth (0.11.4)');
    }

    public function test_info_json_without_tables_has_an_empty_tables_key(): void
    {
        $this->project();
        $tester = $this->run_(new InfoCommand(deployerFactory: fn () => throw new \LogicException('no AWS expected')), ['--env' => 'production', '--json' => true]);
        $json = json_decode($tester->getDisplay(), true);
        self::assertSame([], $json['tables']);
        self::assertSame(['tables', 'permissions_boundary', 'endpoint_auth'], array_slice(array_keys($json), -3));
    }
}
