<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Integration;

use Multiapi\Arco\Binary\BinaryProvider;
use Multiapi\Arco\Binary\RuntimeManifest;
use Multiapi\Arco\Build\PresetRegistry;
use Multiapi\Arco\Config\ConfigLoader;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Console\CommandResult;
use Multiapi\Arco\Deploy\DeployPipeline;
use Multiapi\Arco\Package\ConsoleArtifacts;
use Multiapi\Arco\Package\ConsolePackager;
use Multiapi\Arco\Package\PackageReader;
use Multiapi\Arco\Tests\Support\RuntimeApiEmulator;
use Multiapi\Arco\Tests\Support\S3Emulator;
use Multiapi\Arco\Tinker\TinkerReply;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use ZipArchive;

/** The console zip of a packaged Laravel app, run in the provided.al2023 image the way Lambda runs it. */
#[Group('integration')]
final class ConsoleRuntimeTest extends TestCase
{
    private const EXAMPLE = __DIR__ . '/../../examples/laravel';
    private const IMAGE = 'public.ecr.aws/lambda/provided:al2023';
    /** Every external tool runtime/bootstrap calls. */
    private const BOOTSTRAP_TOOLS = ['curl', 'sha256sum', 'cut', 'mkdir', 'date', 'chmod', 'mv', 'rm', 'wc', 'tr', 'sed'];
    private ?string $container = null;
    private ?RuntimeApiEmulator $api = null;
    private ?S3Emulator $s3 = null;
    private static ?string $webPackage = null;

    protected function setUp(): void
    {
        $docker = new Process(['docker', 'info']);
        $docker->run();
        if (! $docker->isSuccessful()) {
            self::markTestSkipped('Docker is not available.');
        }
    }

    protected function tearDown(): void
    {
        if ($this->container !== null) {
            (new Process(['docker', 'rm', '-f', $this->container]))->run();
        }
        $this->api?->stop();
        $this->s3?->stop();
    }

    /** The console zip with the runtime build's PHP CLI as bin/php: nothing to download, no S3. */
    public function test_the_console_runs_the_packaged_php_cli(): void
    {
        $build = RuntimeManifest::load()->resolve(null, null);
        $phpCli = BinaryProvider::default()->phpCli($build);
        self::assertNotNull($phpCli, "The default build {$build->id} has no PHP CLI.");
        $work = self::workDir();
        $console = ConsolePackager::fromWeb(PackageReader::open(self::webPackage($build->id)), "$work/console", $phpCli);
        self::assertNull($console->download);
        $task = $this->unzip($console, $work);
        self::assertFileExists("$task/bin/php");
        self::assertSame(0755, fileperms("$task/bin/php") & 0777);

        $this->start($task, []);
        $this->assertConsoleWorks('bin/php');

        self::assertStringNotContainsString('"arco":"binary_ready"', $this->logs(), 'bin/php mode never downloads a binary');
        self::assertNull($this->api?->initError());
    }

    /** No PHP CLI (a custom binary or a build published before php_cli): FrankenPHP is downloaded from S3. */
    public function test_the_console_downloads_frankenphp_without_a_php_cli(): void
    {
        $build = RuntimeManifest::load()->resolve(null, null);
        $work = self::workDir();
        // What DeployPipeline does when the build has no php_cli: the console zip carries no PHP at all.
        $console = ConsolePackager::fromWeb(PackageReader::open(self::webPackage($build->id)), "$work/console");
        $download = $console->download;
        self::assertNotNull($download);
        $task = $this->unzip($console, $work);
        self::assertFileDoesNotExist("$task/bin/php");

        // Local S3 with the binary the bootstrap downloads.
        $this->s3 = new S3Emulator('0.0.0.0');
        $this->s3->put('arco-111111111111-us-east-1', $download->key, $download->path);
        $this->start($task, [
            "ARCO_S3_ENDPOINT=http://host.docker.internal:{$this->s3->port}",
            'ARCO_BINARY_BUCKET=arco-111111111111-us-east-1',
            "ARCO_BINARY_KEY={$download->key}",
            "ARCO_BINARY_SHA256={$download->sha256}",
        ]);
        $this->assertConsoleWorks('fallback');

        // The binary came from S3, signed, once; download + checksum must fit Lambda's 10 s init.
        self::assertCount(1, $this->s3->requests(), 'the binary is downloaded once per execution environment');
        self::assertSame("/arco-111111111111-us-east-1/{$download->key}", $this->s3->requests()[0]['path']);
        self::assertStringStartsWith('AWS4-HMAC-SHA256 ', $this->s3->requests()[0]['authorization']);
        self::assertSame('token', $this->s3->requests()[0]['token']);
        self::assertSame(1, preg_match('/\{"arco":"binary_ready","seconds":(\d+),"bytes":(\d+)\}/', $this->logs(), $ready), $this->logs());
        self::assertSame($download->bytes, (int) $ready[2]);
        $this->note(sprintf('[fallback] console binary download + SHA-256: %d s (bootstrap, whole seconds), %.2F s (container start to binary_ready) for %.1F MB (local S3)',
            $ready[1], $this->secondsToLogLine('binary_ready'), $ready[2] / 1048576));
        self::assertLessThan(10, (int) $ready[1], 'Lambda limits init to 10 s');
    }

    /**
     * The web zip of the example app, packaged once per run with the real CLI (`arco package`), after
     * checking the image has every tool the bootstrap needs.
     */
    private static function webPackage(string $build): string
    {
        if (self::$webPackage !== null) {
            return self::$webPackage;
        }
        $script = 'for t in ' . implode(' ', self::BOOTSTRAP_TOOLS) . '; do command -v "$t" >/dev/null || echo "missing $t"; done';
        $tools = new Process(['docker', 'run', '--rm', '--platform', 'linux/arm64', '--entrypoint', 'sh', self::IMAGE, '-c', $script], null, null, null, 120);
        $tools->run();
        self::assertTrue($tools->isSuccessful(), $tools->getErrorOutput());
        self::assertSame('', trim($tools->getOutput()));

        $path = self::workDir() . '/package.zip';
        $package = new Process([PHP_BINARY, __DIR__ . '/../../packages/arco/bin/arco', 'package', '--env=ci', "--output=$path", '--no-interaction'], (string) realpath(self::EXAMPLE), null, null, 900);
        $package->run();
        self::assertTrue($package->isSuccessful(), $package->getOutput() . $package->getErrorOutput());
        self::assertSame($build, PackageReader::open($path)->manifest?->runtimeBuild, 'the example app packages the default build');
        return self::$webPackage = $path;
    }

    private static function workDir(): string
    {
        $work = sys_get_temp_dir() . '/arco-console-it-' . bin2hex(random_bytes(4));
        mkdir($work, 0777, true);
        return $work;
    }

    /** Unzips the console zip like Lambda, with phpIni() set and this checkout's arco-laravel. */
    private function unzip(ConsoleArtifacts $console, string $work): string
    {
        $task = "$work/task";
        $zip = new ZipArchive();
        self::assertTrue($zip->open($console->package->path));
        $zip->extractTo($task);
        $zip->close();
        // ZipArchive drops the mode; Lambda keeps the zip's Unix mode, so restore it from the entry.
        $zip->open($console->package->path);
        $stat = $zip->statName('bin/php');
        if ($stat !== false) {
            self::assertTrue($zip->getExternalAttributesIndex($stat['index'], $os, $attributes));
            chmod("$task/bin/php", ($attributes >> 16) & 0777);
        }
        $zip->close();
        self::assertFileExists("$task/bootstrap");
        self::assertFileDoesNotExist("$task/bin/frankenphp");
        // phpIni() as Packager writes it into arco-runtime.json (the example app sets none).
        $runtimeConfig = json_decode((string) file_get_contents("$task/arco-runtime.json"), true, flags: JSON_THROW_ON_ERROR);
        $runtimeConfig['phpIni'] = ['memory_limit' => '321M'];
        file_put_contents("$task/arco-runtime.json", json_encode($runtimeConfig, JSON_UNESCAPED_SLASHES));
        // Test this checkout's arco-laravel, not the released one Composer installed.
        (new Filesystem())->mirror(__DIR__ . '/../../packages/arco-laravel/src', "$task/app/vendor/multiapi/arco-laravel/src", null, ['override' => true]);
        return $task;
    }

    /**
     * The Runtime API emulator and the runtime in a read-only arm64 provided.al2023 container
     * (1024 MB /tmp, like EphemeralStorage).
     *
     * @param list<string> $env extra NAME=value variables
     */
    private function start(string $task, array $env): void
    {
        $this->api = new RuntimeApiEmulator('0.0.0.0');
        $example = (string) realpath(self::EXAMPLE);
        $config = ConfigResolver::resolve((new ConfigLoader())->load("$example/arco.php"), 'ci');
        $variables = DeployPipeline::variables($config, PresetRegistry::default()->detect($example));
        $this->container = 'arco-console-it-' . bin2hex(random_bytes(3));
        $command = ['docker', 'run', '-d', '--name', $this->container, '--platform', 'linux/arm64',
            '--read-only', '--tmpfs', '/tmp:size=1024m,exec', '--cpus', '2', '--memory', '2g',
            '--add-host', 'host.docker.internal:host-gateway',
            '-v', "$task:/var/task:ro", '-w', '/var/task',
            '-e', "AWS_LAMBDA_RUNTIME_API=host.docker.internal:{$this->api->port}",
            '-e', 'LAMBDA_TASK_ROOT=/var/task', '-e', 'AWS_REGION=us-east-1',
            '-e', 'AWS_ACCESS_KEY_ID=AKIDTEST', '-e', 'AWS_SECRET_ACCESS_KEY=secret', '-e', 'AWS_SESSION_TOKEN=token'];
        foreach ($env as $variable) {
            array_push($command, '-e', $variable);
        }
        foreach ($variables as $name => $value) {
            array_push($command, '-e', "$name=$value");
        }
        array_push($command, '--entrypoint', 'sh', self::IMAGE, '/var/task/bootstrap');
        $run = new Process($command);
        $run->run();
        self::assertTrue($run->isSuccessful(), $run->getErrorOutput());
    }

    /** A command, a failing command, a tinker round trip, phpIni() and the audit log: the same in both modes. */
    private function assertConsoleWorks(string $mode): void
    {
        [$about, $seconds] = $this->timed(fn () => $this->respond($this->api?->enqueue(['command' => 'about --only=environment']) ?? ''));
        self::assertSame(0, $about['exitCode'], $about['output'] . $this->logs());
        self::assertStringContainsString('Laravel Version', $about['output']);
        $this->note(sprintf('[%s] init (container start to console_ready): %.2F s', $mode, $this->secondsToLogLine('console_ready')));
        $this->note(sprintf('[%s] first invocation (cold: init + artisan about): %.2F s', $mode, $seconds));

        // Not in the arco: namespace: Symfony names only the namespace when it holds hidden commands alone (arco:tinker).
        [$failing, $seconds] = $this->timed(fn () => $this->respond($this->api?->enqueue(['command' => 'arco-does-not-exist']) ?? ''));
        self::assertNotSame(0, $failing['exitCode']);
        self::assertStringContainsString('Command "arco-does-not-exist" is not defined', $failing['output']);
        self::assertStringContainsString('"arco":"command_failed"', $this->logs());
        $this->note(sprintf('[%s] failing command (warm): %.2F s', $mode, $seconds));

        [$first, $seconds] = $this->timed(fn () => $this->tinker('$x = 41;', null));
        self::assertNotNull($first->context);
        $this->note(sprintf('[%s] tinker statement 1 (warm): %.2F s', $mode, $seconds));
        [$second, $seconds] = $this->timed(fn () => $this->tinker('$x + 1', $first->context));
        self::assertSame('42', $second->presented);
        $this->note(sprintf('[%s] tinker statement 2 (warm): %.2F s', $mode, $seconds));
        self::assertSame('"321M"', $this->tinker('ini_get("memory_limit")', null)->presented, 'phpIni() applies to the console function');
        self::assertStringNotContainsString('ARCO-TINKER', $this->logs(), 'tinker replies stay out of the function log');
        foreach (['$x = 41;', '$x + 1'] as $code) {
            $audit = substr((string) json_encode(['arco' => 'tinker', 'code' => $code], JSON_UNESCAPED_SLASHES), 0, -1);
            self::assertStringContainsString($audit, $this->logs(), 'every statement is audited in the function log');
        }
    }

    /** @return array{exitCode: int, output: string} */
    private function respond(string $id): array
    {
        $reply = $this->api?->await($id, 90) ?? throw new \LogicException('No emulator.');
        self::assertSame('response', $reply['type'], $reply['body'] . $this->logs());
        /** @var array{exitCode: int, output: string} */
        return json_decode($reply['body'], true, flags: JSON_THROW_ON_ERROR);
    }

    private function tinker(string $code, ?string $context): TinkerReply
    {
        $response = $this->respond(($this->api ?? throw new \LogicException('No emulator.'))->enqueue(['tinker' => ['code' => base64_encode($code), 'context' => $context]]));
        $reply = TinkerReply::parse(new CommandResult($response['exitCode'], $response['output']));
        self::assertTrue($reply->replied, $response['output'] . $this->logs());
        self::assertNull($reply->exception, $response['output']);
        return $reply;
    }

    /**
     * @template T
     * @param callable(): T $call
     * @return array{0: T, 1: float}
     */
    private function timed(callable $call): array
    {
        $started = microtime(true);
        $result = $call();
        return [$result, microtime(true) - $started];
    }

    /** Sub-second timing: the container's start to the timestamp Docker gave an {"arco":"<event>"} line. */
    private function secondsToLogLine(string $event): float
    {
        $inspect = new Process(['docker', 'inspect', '-f', '{{.State.StartedAt}}', (string) $this->container]);
        $inspect->mustRun();
        $logs = new Process(['docker', 'logs', '--timestamps', (string) $this->container]);
        $logs->mustRun();
        self::assertSame(1, preg_match('/^(\S+) \{"arco":"' . preg_quote($event, '/') . '"/m', $logs->getOutput(), $line), $logs->getOutput());
        return self::timestamp($line[1]) - self::timestamp(trim($inspect->getOutput()));
    }

    /** RFC 3339 with nanoseconds (Docker's format) to Unix seconds. */
    private static function timestamp(string $value): float
    {
        self::assertSame(1, preg_match('/^(.+T\d\d:\d\d:\d\d)(?:\.(\d+))?Z$/', $value, $parts), $value);
        return (float) strtotime("$parts[1]Z") + (float) ('0.' . ($parts[2] ?? '0'));
    }

    private function note(string $line): void
    {
        fwrite(STDERR, "\n[console runtime] $line\n");
    }

    private function logs(): string
    {
        $logs = new Process(['docker', 'logs', (string) $this->container]);
        $logs->run();
        return $logs->getOutput() . $logs->getErrorOutput();
    }
}
