<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Build;

use Multiapi\Arco\Build\BuildException;
use Multiapi\Arco\Build\CommandRunner;
use Multiapi\Arco\Build\ScheduleInspector;
use Multiapi\Arco\Tests\Support\TempPaths;
use PHPUnit\Framework\TestCase;

final class ScheduleInspectorTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        $this->project = TempPaths::path('arco-inspect-');
        mkdir($this->project, 0777, true);
        file_put_contents("$this->project/artisan", '<?php');
    }

    /** @param list<array{command: list<string>, cwd: string, env: array<string, string>}> $calls */
    private static function runner(string|\Throwable $output, array &$calls = []): CommandRunner
    {
        return new class ($output, $calls) implements CommandRunner {
            /** @param list<array{command: list<string>, cwd: string, env: array<string, string>}> $calls */
            public function __construct(private readonly string|\Throwable $output, private array &$calls)
            {
            }

            public function run(array $command, string $cwd, array $env = []): string
            {
                $this->calls[] = ['command' => $command, 'cwd' => $cwd, 'env' => $env];
                if ($this->output instanceof \Throwable) {
                    throw $this->output;
                }
                return $this->output;
            }
        };
    }

    public function test_valid_json_is_parsed(): void
    {
        $calls = [];
        $json = "Some deprecation notice\n" . '[{"task":"reports:send","minutes":[0,30]},{"task":"Closure","minutes":[0]}]' . "\n";

        $tasks = (new ScheduleInspector(self::runner($json, $calls)))->tasks($this->project, '/usr/bin/php');

        self::assertSame([['task' => 'reports:send', 'minutes' => [0, 30]], ['task' => 'Closure', 'minutes' => [0]]], $tasks);
        self::assertSame(['/usr/bin/php', 'artisan', 'arco:schedule-intervals'], $calls[0]['command']);
        self::assertSame($this->project, $calls[0]['cwd']);
        self::assertSame('1', $calls[0]['env']['ARCO'] ?? null);
    }

    public function test_a_failing_command_gives_null(): void
    {
        $error = BuildException::make('build.command_failed', ['command' => 'php artisan arco:schedule-intervals', 'output' => 'Command "arco:schedule-intervals" is not defined.']);

        self::assertNull((new ScheduleInspector(self::runner($error)))->tasks($this->project, 'php'));
    }

    public function test_garbage_gives_null(): void
    {
        self::assertNull((new ScheduleInspector(self::runner('not json')))->tasks($this->project, 'php'));
        self::assertNull((new ScheduleInspector(self::runner('[{"task":1,"minutes":"x"}]')))->tasks($this->project, 'php'));
        self::assertNull((new ScheduleInspector(self::runner('[{"task":"a","minutes":[75]}]')))->tasks($this->project, 'php'));
    }

    public function test_a_project_without_artisan_is_not_run(): void
    {
        unlink("$this->project/artisan");
        $calls = [];

        self::assertNull((new ScheduleInspector(self::runner('[]', $calls)))->tasks($this->project, 'php'));
        self::assertSame([], $calls);
    }
}
