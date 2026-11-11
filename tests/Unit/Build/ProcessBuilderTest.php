<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Build;

use Multiapi\Arco\Build\BuildException;
use Multiapi\Arco\Build\ProcessBuilder;
use Multiapi\Arco\Build\ProcessPreset;
use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ConfigException;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Tests\Support\TempPaths;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Process;

final class ProcessBuilderTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        $this->project = TempPaths::path('arco-go-') . '/api';
        mkdir($this->project, 0777, true);
    }

    private function write(string $path, string $contents = ''): void
    {
        $dir = dirname("$this->project/$path");
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents("$this->project/$path", $contents);
    }

    /** @param array<string, mixed> $process @param list<string> $include */
    private function config(array $process = ['language' => 'go'], array $include = []): AppConfig
    {
        $raw = App::make('api')->region('us-east-1')->environment('production')->toArray();
        $raw['process'] = $process;
        if ($include !== []) {
            $raw['include'] = $include;
        }
        return ConfigResolver::resolve($raw, 'production');
    }

    private function builder(FakeRunner $runner, bool $goExists = true): ProcessBuilder
    {
        return new ProcessBuilder($runner, 'go', fn (): bool => $goExists);
    }

    public function test_main_package_is_the_root_when_it_declares_package_main(): void
    {
        $this->write('main.go', "// Command api.\n/* block\n package other */\npackage main\n");

        self::assertSame('.', ProcessBuilder::mainPackage($this->project, null));
    }

    public function test_test_files_do_not_make_the_root_the_main_package(): void
    {
        $this->write('x_test.go', "package main\n");
        $this->write('cmd/api/main.go', "package main\n");

        self::assertSame('./cmd/api', ProcessBuilder::mainPackage($this->project, null));
    }

    public function test_two_commands_need_a_configured_main(): void
    {
        $this->write('cmd/a/main.go', "package main\n");
        $this->write('cmd/b/main.go', "package main\n");

        try {
            ProcessBuilder::mainPackage($this->project, null);
            self::fail('expected runtime.main_required');
        } catch (BuildException $e) {
            self::assertSame('runtime.main_required', $e->key);
        }
        self::assertSame('./cmd/b', ProcessBuilder::mainPackage($this->project, './cmd/b'));
    }

    public function test_nothing_to_build_needs_a_configured_main(): void
    {
        $this->expectException(BuildException::class);
        ProcessBuilder::mainPackage($this->project, null);
    }

    public function test_build_runs_go_in_the_project_and_stages_the_binary_and_includes(): void
    {
        $this->write('cmd/api/main.go', "package main\n");
        $this->write('templates/a.html', '<p>');
        $this->write('.env', 'SECRET=1');
        $this->write('main_test.go', "package main\n");
        $runner = new FakeRunner(onRun: function (array $command): void {
            $out = $command[4];
            is_dir(dirname($out)) || mkdir(dirname($out), 0777, true);
            file_put_contents($out, 'ELF');
        });
        $steps = [];

        $result = $this->builder($runner)->build(
            $this->config(['language' => 'go'], ['templates/**', '.env']),
            $this->project,
            function (string $key, array $params) use (&$steps): void {
                $steps[] = [$key, $params];
            },
        );

        try {
            self::assertCount(1, $runner->calls, 'go build only: no composer');
            self::assertSame(['go', 'build', '-trimpath', '-o', "$result->appDir/bin/app", './cmd/api'], $runner->calls[0]['command']);
            self::assertSame($this->project, $runner->calls[0]['cwd']);
            self::assertSame(['CGO_ENABLED' => '0', 'GOOS' => 'linux', 'GOARCH' => 'arm64'], $runner->calls[0]['env']);
            self::assertFileExists("$result->appDir/bin/app");
            self::assertFileExists("$result->appDir/templates/a.html");
            self::assertFileDoesNotExist("$result->appDir/.env");
            self::assertFileDoesNotExist("$result->appDir/cmd/api/main.go");
            self::assertSame([['build.copying', []], ['build.go', ['main' => './cmd/api']]], $steps);
        } finally {
            $result->cleanup();
        }
    }

    public function test_exclude_applies_to_what_include_matched(): void
    {
        $this->write('main.go', "package main\n");
        $this->write('templates/a.html', '');
        $this->write('templates/draft.html', '');
        $raw = App::make('api')->region('us-east-1')->environment('production')->toArray();
        $raw['process'] = ['language' => 'go'];
        $raw['include'] = ['templates/**'];
        $raw['exclude'] = ['templates/draft.html'];
        $config = ConfigResolver::resolve($raw, 'production');

        $result = $this->builder(new FakeRunner())->build($config, $this->project);
        try {
            self::assertFileExists("$result->appDir/templates/a.html");
            self::assertFileDoesNotExist("$result->appDir/templates/draft.html");
        } finally {
            $result->cleanup();
        }
    }

    public function test_a_missing_go_is_reported_before_building(): void
    {
        $this->write('main.go', "package main\n");
        $runner = new FakeRunner();

        try {
            $this->builder($runner, goExists: false)->build($this->config(), $this->project);
            self::fail('expected build.go_missing');
        } catch (BuildException $e) {
            self::assertSame('build.go_missing', $e->key);
        }
        self::assertSame([], $runner->calls);
    }

    public function test_a_runner_that_cannot_start_go_is_reported_as_missing(): void
    {
        $this->write('main.go', "package main\n");
        $runner = new FakeRunner(onRun: function (): void {
            throw new ProcessStartFailedException(new Process(['go']), 'not found');
        });

        try {
            $this->builder($runner)->build($this->config(), $this->project);
            self::fail('expected build.go_missing');
        } catch (BuildException $e) {
            self::assertSame('build.go_missing', $e->key);
        }
    }

    public function test_a_failing_build_keeps_the_command_failure_and_cleans_up(): void
    {
        $this->write('main.go', "package main\n");
        $before = glob(sys_get_temp_dir() . '/arco-build-*') ?: [];

        try {
            $this->builder(new FakeRunner(['build' => 'syntax error']))->build($this->config(), $this->project);
            self::fail('expected build.command_failed');
        } catch (BuildException $e) {
            self::assertSame('build.command_failed', $e->key);
        }
        self::assertSame($before, glob(sys_get_temp_dir() . '/arco-build-*') ?: []);
    }

    public function test_the_process_preset_is_neutral(): void
    {
        $preset = new ProcessPreset(new \Multiapi\Arco\Config\ProcessRuntime('go', null, ['bin/app'], '/up'));

        self::assertSame('go', $preset->name());
        self::assertSame('/up', $preset->healthPath());
        self::assertSame('.', $preset->publicDir());
        self::assertNull($preset->workerScript());
        self::assertSame([], $preset->buildSteps());
        self::assertSame([], $preset->excludeRules());
        self::assertSame([], $preset->defaultVariables());
        self::assertFalse($preset->detects($this->project));
    }
}
