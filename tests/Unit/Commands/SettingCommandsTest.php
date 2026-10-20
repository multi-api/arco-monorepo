<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Commands;

use Multiapi\Arco\Commands\SetCommand;
use Multiapi\Arco\Commands\UnsetCommand;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ConfigLoader;
use Multiapi\Arco\Config\ConfigResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class SettingCommandsTest extends TestCase
{
    private ?ProjectFixture $project = null;

    protected function tearDown(): void
    {
        $this->project?->restore();
    }

    private function project(?string $php = null): ProjectFixture
    {
        return $this->project = new ProjectFixture($php ?? ProjectFixture::standardConfig());
    }

    /** @param array<string, mixed> $input */
    private function arco(string $command, array $input): CommandTester
    {
        $tester = $this->project?->tester($command === 'set' ? new SetCommand() : new UnsetCommand())
            ?? throw new \LogicException('No project.');
        $tester->execute($input, ['interactive' => false]);
        return $tester;
    }

    private function config(string $environment = 'production'): AppConfig
    {
        return ConfigResolver::resolve((new ConfigLoader())->load($this->project?->dir . '/arco.php'), $environment);
    }

    private function source(): string
    {
        return (string) file_get_contents($this->project?->dir . '/arco.php');
    }

    private static function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    public function test_sets_each_key_in_an_environment(): void
    {
        $this->project();

        foreach ([['concurrency', '16'], ['max-instances', '20'], ['rate-limit', '100'], ['timeout', '28'], ['log-level', 'debug']] as [$key, $value]) {
            $tester = $this->arco('set', ['key' => $key, 'value' => $value, '--env' => 'production']);
            $tester->assertCommandIsSuccessful();
            self::assertStringContainsString('Run `arco deploy --env=production` to apply.', self::display($tester));
        }
        self::assertStringContainsString("->logLevel('debug')", self::display($tester));
        $config = $this->config();
        self::assertSame([16, 20, 100, 28, 'debug'], [$config->concurrency, $config->maxInstances, $config->rateLimit, $config->timeout, $config->logLevel]);
        self::assertSame('environment', $config->sources['concurrency']);
        self::assertSame(48, $this->config('staging')->concurrency);
    }

    public function test_prints_the_resulting_line(): void
    {
        $this->project();

        $tester = $this->arco('set', ['key' => 'concurrency', 'value' => '16', '--env' => 'production']);

        self::assertStringContainsString('->concurrency(16)', $tester->getDisplay());
    }

    public function test_console_timeout_and_memory_are_written_as_the_console_call(): void
    {
        $this->project();

        $tester = $this->arco('set', ['key' => 'console-timeout', 'value' => '600', '--env' => 'production']);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('->console(timeout: 600)', self::display($tester));
        $this->arco('set', ['key' => 'console-memory', 'value' => '4096', '--env' => 'production'])->assertCommandIsSuccessful();

        self::assertStringContainsString('->console(timeout: 600, memory: 4096)', $this->source());
        self::assertStringNotContainsString('consoleTimeout', $this->source());
        $config = $this->config();
        self::assertSame([true, 600, 4096], [$config->console, $config->consoleTimeout, $config->consoleMemory]);

        $this->arco('unset', ['key' => 'console-timeout', '--env' => 'production'])->assertCommandIsSuccessful();
        self::assertStringContainsString('->console(memory: 4096)', $this->source());
        self::assertSame(900, $this->config()->consoleTimeout);
    }

    public function test_console_timeout_is_validated_before_writing(): void
    {
        $this->project();
        $before = $this->source();

        $tester = $this->arco('set', ['key' => 'console-timeout', 'value' => '901', '--env' => 'production']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertSame($before, $this->source());
    }

    public function test_sets_and_unsets_at_app_level_and_warns_about_environment_overrides(): void
    {
        $this->project();

        $tester = $this->arco('set', ['key' => 'timeout', 'value' => '90', '--app' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertSame([90, 'app'], [$this->config('staging')->timeout, $this->config('staging')->sources['timeout']]);
        self::assertSame(60, $this->config()->timeout);
        self::assertStringContainsString('->timeout(90)', self::display($tester));
        self::assertStringContainsString('production sets its own timeout', self::display($tester));
        self::assertStringContainsString('arco deploy --env=staging', self::display($tester));

        $this->arco('unset', ['key' => 'concurrency', '--app' => true])->assertCommandIsSuccessful();
        self::assertSame('default', $this->config('staging')->sources['concurrency']);
    }

    public function test_unset_removes_the_call(): void
    {
        $this->project();

        $tester = $this->arco('unset', ['key' => 'timeout', '--env' => 'production']);

        $tester->assertCommandIsSuccessful();
        self::assertSame([30, 'default'], [$this->config()->timeout, $this->config()->sources['timeout']]);
        self::assertStringNotContainsString('->timeout(', $this->source());
        self::assertStringContainsString('Run `arco deploy --env=production` to apply.', self::display($tester));
    }

    public function test_unset_of_a_setting_that_is_not_there_writes_nothing(): void
    {
        $this->project();
        $before = $this->source();

        $tester = $this->arco('unset', ['key' => 'rate-limit', '--env' => 'production']);

        $tester->assertCommandIsSuccessful();
        self::assertSame($before, $this->source());
        self::assertStringContainsString('rate-limit is not set in environment production', self::display($tester));

        // concurrency is set at app level, not in the environment
        $this->arco('unset', ['key' => 'concurrency', '--env' => 'production'])->assertCommandIsSuccessful();
        self::assertSame($before, $this->source());
    }

    public function test_rejects_unknown_keys_and_invalid_values_without_writing(): void
    {
        $this->project();
        $before = $this->source();

        $cases = [
            [['key' => 'memory', 'value' => '1024', '--env' => 'production'], 'Valid settings: concurrency, max-instances, rate-limit, timeout, log-level'],
            [['key' => 'concurrency', 'value' => '200', '--env' => 'production'], 'concurrency must be between 1 and 128, got 200.'],
            [['key' => 'concurrency', 'value' => 'lots', '--env' => 'production'], 'concurrency must be between 1 and 128, got lots.'],
            [['key' => 'timeout', 'value' => '2', '--env' => 'production'], 'timeout must be between 3 and 900 seconds, got 2.'],
            [['key' => 'max-instances', 'value' => '0', '--env' => 'production'], 'must be between 2 and 10000, got 0.'],
            [['key' => 'log-level', 'value' => 'loud', '--env' => 'production'], 'logLevel must be one of trace'],
        ];
        foreach ($cases as [$input, $message]) {
            $tester = $this->arco('set', $input);
            self::assertSame(1, $tester->getStatusCode(), $message);
            self::assertStringContainsString($message, self::display($tester));
        }
        $tester = $this->arco('unset', ['key' => 'memory', '--env' => 'production']);
        self::assertSame(1, $tester->getStatusCode());
        self::assertSame($before, $this->source());
    }

    public function test_needs_exactly_one_target(): void
    {
        $this->project();

        $tester = $this->arco('set', ['key' => 'timeout', 'value' => '10', '--env' => 'production', '--app' => true]);
        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('either --env=<name> or --app', self::display($tester));

        $tester = $this->arco('set', ['key' => 'timeout', 'value' => '10']);
        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('staging, production', self::display($tester));

        $tester = $this->arco('set', ['key' => 'timeout', 'value' => '10', '--env' => 'qa']);
        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('qa', self::display($tester));
    }

    public function test_uses_the_only_environment_and_says_so(): void
    {
        $this->project("<?php\nreturn Multiapi\\Arco\\Config\\App::make('shop')->region('us-east-1')->environment('production');\n");

        $tester = $this->arco('set', ['key' => 'concurrency', 'value' => '8']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Using the only environment, production.', self::display($tester));
        self::assertSame(8, $this->config()->concurrency);
    }

    public function test_an_unknown_shape_prints_the_line_to_add_by_hand(): void
    {
        $this->project(str_replace('->timeout(60)', "->timeout((int) getenv('T') ?: 60)", ProjectFixture::standardConfig()));
        $before = $this->source();

        $tester = $this->arco('set', ['key' => 'timeout', 'value' => '28', '--env' => 'production']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertSame($before, $this->source());
        self::assertStringContainsString('Nothing was changed', self::display($tester));
        self::assertStringContainsString('->timeout(28)', self::display($tester));
    }
}
