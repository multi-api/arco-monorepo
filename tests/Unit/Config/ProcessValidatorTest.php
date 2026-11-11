<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Config;

use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Config\QueueConfig;
use Multiapi\Arco\Config\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProcessValidatorTest extends TestCase
{
    /**
     * @param array<string, mixed> $extra raw keys merged over a process app
     * @return list<array{key: string, params: array<string, string|int>}>
     */
    private static function errors(array $extra, bool $process = true): array
    {
        $raw = App::make('shop')->region('us-east-1')->environment('production')->toArray();
        if ($process) {
            $raw['process'] = ['language' => 'go'];
        }
        $raw = array_replace_recursive($raw, $extra);
        $result = (new Validator())->validate(ConfigResolver::resolve($raw, 'production'), sys_get_temp_dir());
        return $result->errors;
    }

    /** @param array<string, mixed> $extra */
    private static function keys(array $extra, bool $process = true): array
    {
        return array_map(fn (array $e): string => $e['key'], self::errors($extra, $process));
    }

    public function test_a_valid_process_app_has_no_errors(): void
    {
        self::assertSame([], self::errors([
            'process' => ['main' => './cmd/api', 'command' => ['bin/app', '--serve'], 'healthPath' => '/healthz'],
            'queues' => ['emails' => ['route' => '/jobs/emails']],
            'schedules' => ['cleanup' => ['every' => '1h', 'route' => '/jobs/cleanup', 'timeout' => 900], 'nightly' => ['every' => '0 2 * * *']],
        ]));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function unsupported(): iterable
    {
        yield 'console' => [['settings' => ['console' => false]], 'console'];
        yield 'scheduler' => [['settings' => ['scheduler' => 5]], 'scheduler'];
        yield 'scheduler false' => [['settings' => ['scheduler' => false]], 'scheduler'];
        yield 'consoleTimeout' => [['settings' => ['consoleTimeout' => 60]], 'consoleTimeout'];
        yield 'consoleMemory' => [['settings' => ['consoleMemory' => 512]], 'consoleMemory'];
        yield 'cache' => [['settings' => ['cache' => ['table' => 'x']]], 'cache'];
        yield 'failedJobsTable' => [['settings' => ['failedJobsTable' => ['table' => 'x']]], 'failedJobsTable'];
        yield 'deployCommands' => [['deployCommands' => ['migrate']], 'deployCommands'];
        yield 'environment console' => [['environments' => ['production' => ['settings' => ['console' => true]]]], 'console'];
        yield 'runtime php' => [['runtime' => ['php' => '8.4']], 'runtime.php'];
        yield 'runtime frankenphp' => [['runtime' => ['frankenphp' => '1.0']], 'runtime.frankenphp'];
        yield 'runtime build' => [['runtime' => ['build' => 'x']], 'runtime.build'];
        yield 'runtime binary' => [['runtime' => ['binary' => 'x']], 'runtime.binary'];
        yield 'runtime workers' => [['runtime' => ['workers' => 2]], 'runtime.workers'];
        yield 'runtime threads' => [['runtime' => ['threads' => 2]], 'runtime.threads'];
        yield 'runtime phpIni' => [['runtime' => ['phpIni' => ['memory_limit' => '1G']]], 'runtime.phpIni'];
    }

    /** @param array<string, mixed> $extra */
    #[DataProvider('unsupported')]
    public function test_php_only_settings_are_refused(array $extra, string $key): void
    {
        self::assertContains(['key' => 'validation.process_unsupported', 'params' => ['key' => $key]], self::errors($extra));
    }

    public function test_the_same_settings_are_fine_on_a_php_app(): void
    {
        self::assertNotContains('validation.process_unsupported', self::keys(['settings' => ['console' => true]], false));
    }

    public function test_language_must_be_go(): void
    {
        self::assertContains(['key' => 'validation.process_language', 'params' => ['language' => 'ruby']], self::errors(['process' => ['language' => 'ruby']]));
        self::assertContains(['key' => 'validation.process_language', 'params' => ['language' => '']], self::errors(['process' => ['language' => '']]));
    }

    public function test_health_path_and_main(): void
    {
        self::assertContains(['key' => 'validation.route', 'params' => ['kind' => 'healthPath', 'name' => 'healthz']], self::errors(['process' => ['healthPath' => 'healthz']]));
        self::assertContains(['key' => 'validation.route', 'params' => ['kind' => 'healthPath', 'name' => '']], self::errors(['process' => ['healthPath' => '']]));
        self::assertContains('validation.process_main', self::keys(['process' => ['main' => '']]));
    }

    public function test_command_must_be_a_list_of_non_empty_strings(): void
    {
        self::assertContains('validation.process_command', self::keys(['process' => ['command' => ['bin/app', '']]]));
        self::assertNotContains('validation.process_command', self::keys(['process' => ['command' => ['bin/app']]]));
    }

    public function test_schedule_names(): void
    {
        self::assertContains(['key' => 'validation.schedule_name', 'params' => ['name' => 'Daily']], self::errors(['schedules' => ['Daily' => ['every' => '1h']]]));
        $long = str_repeat('a', 41);
        self::assertContains('validation.schedule_name', self::keys(['schedules' => [$long => ['every' => '1h']]]));
        self::assertNotContains('validation.schedule_name', self::keys(['schedules' => [str_repeat('a', 40) => ['every' => '1h']]]));
    }

    public function test_schedule_every(): void
    {
        self::assertContains(['key' => 'validation.schedule_every', 'params' => ['schedule' => 'nightly', 'every' => '2d']], self::errors(['schedules' => ['nightly' => ['every' => '2d']]]));
        self::assertContains('validation.schedule_every', self::keys(['schedules' => ['nightly' => []]]));
    }

    public function test_schedule_timeout_and_route(): void
    {
        foreach ([0, 901] as $timeout) {
            self::assertContains(['key' => 'validation.schedule_timeout', 'params' => ['schedule' => 'nightly', 'value' => $timeout]], self::errors(['schedules' => ['nightly' => ['every' => '1h', 'timeout' => $timeout]]]));
        }
        self::assertContains(['key' => 'validation.route', 'params' => ['kind' => 'schedule', 'name' => 'nightly']], self::errors(['schedules' => ['nightly' => ['every' => '1h', 'route' => 'jobs']]]));
        self::assertContains(['key' => 'validation.route', 'params' => ['kind' => 'queue', 'name' => 'emails']], self::errors(['queues' => ['emails' => ['route' => 'jobs']]]));
    }

    public function test_queue_names_that_share_a_url_variable_are_refused(): void
    {
        self::assertContains(
            ['key' => 'validation.queue_variable_collision', 'params' => ['queues' => 'a-b, a_b', 'variable' => 'ARCO_QUEUE_A_B_URL']],
            self::errors(['queues' => ['a-b' => [], 'a_b' => []]]),
        );
        self::assertNotContains('validation.queue_variable_collision', self::keys(['queues' => ['a-b' => [], 'a-c' => []]]));
    }

    public function test_the_url_variable_name(): void
    {
        self::assertSame('ARCO_QUEUE_EMAILS_URL', QueueConfig::urlVariable('emails'));
        self::assertSame('ARCO_QUEUE_ORDER_EVENTS_URL', QueueConfig::urlVariable('order-events'));
        self::assertSame('ARCO_QUEUE_A_B_URL', QueueConfig::urlVariable('a.b'));
    }

    public function test_php_apps_refuse_the_process_keys(): void
    {
        self::assertContains(['key' => 'validation.queue_route_php', 'params' => ['queue' => 'emails']], self::errors(['queues' => ['emails' => ['route' => '/x']]], false));
        self::assertContains('validation.schedules_php', self::keys(['schedules' => ['nightly' => ['every' => '1h']]], false));
        self::assertNotContains('validation.queue_route_php', self::keys(['queues' => ['emails' => []]], false));
    }

    public function test_a_refused_php_option_reports_only_that_error(): void
    {
        $unsupported = static fn (string $key): array => [['key' => 'validation.process_unsupported', 'params' => ['key' => $key]]];
        self::assertSame($unsupported('runtime.binary'), self::errors(['runtime' => ['binary' => 'missing/bin']]));
        self::assertSame($unsupported('runtime.workers'), self::errors(['runtime' => ['workers' => 0]]));
        self::assertSame($unsupported('runtime.mode'), self::errors(['runtime' => ['mode' => 'bad']]));
        self::assertSame($unsupported('runtime.php'), self::errors(['runtime' => ['php' => '9.9']]));
        self::assertSame(
            [...$unsupported('console'), ...$unsupported('scheduler')],
            self::errors(['settings' => ['console' => false, 'scheduler' => 5]]),
        );
    }

    public function test_a_process_app_does_not_load_the_runtime_manifest(): void
    {
        $raw = App::make('shop')->region('us-east-1')->environment('production')->toArray();
        $raw['process'] = ['language' => 'go'];
        $broken = new Validator(\Multiapi\Arco\Binary\RuntimeManifest::fromArray([
            'default_php' => '8.5',
            'builds' => ['php8.5.11-fp1.13.0-r1' => ['url' => 'https://example.test/f', 'sha256' => str_repeat('a', 64), 'size' => 1, 'frankenphp' => '1.13.0', 'php' => '8.5.11']],
        ]));
        $raw['runtime'] = ['php' => '7.0'];
        $withPhp = $broken->validate(ConfigResolver::resolve($raw, 'production'), sys_get_temp_dir());
        self::assertSame(['validation.process_unsupported'], array_map(fn (array $e): string => $e['key'], $withPhp->errors));
    }
}
