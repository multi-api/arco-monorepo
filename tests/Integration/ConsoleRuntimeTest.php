<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Integration;

use Multiapi\Arco\Build\PresetRegistry;
use Multiapi\Arco\Config\ConfigLoader;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Console\CommandResult;
use Multiapi\Arco\Deploy\DeployPipeline;
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
    private const BOOTSTRAP_TOOLS = ['curl', 'sha256sum', 'cut', 'mkdir', 'date', 'chmod', 'mv', 'rm', 'wc', 'tr'];
    private ?string $container = null;
    private ?RuntimeApiEmulator $api = null;
    private ?S3Emulator $s3 = null;

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

    public function test_runs_artisan_and_tinker_like_on_lambda(): void
    {
        $example = (string) realpath(self::EXAMPLE);
        $work = sys_get_temp_dir() . '/arco-console-it-' . bin2hex(random_bytes(4));
        mkdir("$work/task", 0777, true);

        // 0. The image has every tool the bootstrap needs.
        $script = 'for t in ' . implode(' ', self::BOOTSTRAP_TOOLS) . '; do command -v "$t" >/dev/null || echo "missing $t"; done';
        $tools = new Process(['docker', 'run', '--rm', '--platform', 'linux/arm64', '--entrypoint', 'sh', self::IMAGE, '-c', $script], null, null, null, 120);
        $tools->run();
        self::assertTrue($tools->isSuccessful(), $tools->getErrorOutput());
        self::assertSame('', trim($tools->getOutput()));

        // 1. Package with the real CLI, derive the console zip like a deploy does, unzip it like Lambda.
        $package = new Process([PHP_BINARY, __DIR__ . '/../../packages/arco/bin/arco', 'package', '--env=ci', "--output=$work/package.zip", '--no-interaction'], $example, null, null, 900);
        $package->run();
        self::assertTrue($package->isSuccessful(), $package->getOutput() . $package->getErrorOutput());
        $console = ConsolePackager::fromWeb(PackageReader::open("$work/package.zip"), "$work/console");
        $zip = new ZipArchive();
        self::assertTrue($zip->open($console->package->path));
        $zip->extractTo("$work/task");
        $zip->close();
        self::assertFileExists("$work/task/bootstrap");
        self::assertFileDoesNotExist("$work/task/bin/frankenphp");
        // Test this checkout's arco-laravel, not the released one Composer installed.
        (new Filesystem())->mirror(__DIR__ . '/../../packages/arco-laravel/src', "$work/task/app/vendor/multiapi/arco-laravel/src", null, ['override' => true]);

        // 2. Local S3 with the binary, the Runtime API emulator, and the runtime in a read-only arm64
        //    provided.al2023 container (1024 MB /tmp, like EphemeralStorage).
        $this->s3 = new S3Emulator('0.0.0.0');
        $this->s3->put('arco-111111111111-us-east-1', $console->binaryKey, $console->binaryPath);
        $this->api = new RuntimeApiEmulator('0.0.0.0');
        $config = ConfigResolver::resolve((new ConfigLoader())->load("$example/arco.php"), 'ci');
        $variables = DeployPipeline::variables($config, PresetRegistry::default()->detect($example));
        $this->container = 'arco-console-it-' . bin2hex(random_bytes(3));
        $command = ['docker', 'run', '-d', '--name', $this->container, '--platform', 'linux/arm64',
            '--read-only', '--tmpfs', '/tmp:size=1024m,exec', '--cpus', '2', '--memory', '2g',
            '--add-host', 'host.docker.internal:host-gateway',
            '-v', "$work/task:/var/task:ro", '-w', '/var/task',
            '-e', "AWS_LAMBDA_RUNTIME_API=host.docker.internal:{$this->api->port}",
            '-e', 'LAMBDA_TASK_ROOT=/var/task', '-e', 'AWS_REGION=us-east-1',
            '-e', 'AWS_ACCESS_KEY_ID=AKIDTEST', '-e', 'AWS_SECRET_ACCESS_KEY=secret', '-e', 'AWS_SESSION_TOKEN=token',
            '-e', "ARCO_S3_ENDPOINT=http://host.docker.internal:{$this->s3->port}",
            '-e', 'ARCO_BINARY_BUCKET=arco-111111111111-us-east-1',
            '-e', "ARCO_BINARY_KEY={$console->binaryKey}",
            '-e', "ARCO_BINARY_SHA256={$console->binarySha256}"];
        foreach ($variables as $name => $value) {
            array_push($command, '-e', "$name=$value");
        }
        array_push($command, '--entrypoint', 'sh', self::IMAGE, '/var/task/bootstrap');
        $run = new Process($command);
        $run->run();
        self::assertTrue($run->isSuccessful(), $run->getErrorOutput());

        // 3. A command, a failing command, a tinker round-trip.
        [$about, $seconds] = $this->timed(fn () => $this->respond($this->api?->enqueue(['command' => 'about --only=environment']) ?? ''));
        self::assertSame(0, $about['exitCode'], $about['output'] . $this->logs());
        self::assertStringContainsString('Laravel Version', $about['output']);
        $this->note(sprintf('first invocation (cold: download + checksum + artisan about): %.2F s', $seconds));

        // The binary came from S3, signed, once; download + checksum must fit Lambda's 10 s init.
        self::assertCount(1, $this->s3->requests());
        self::assertSame("/arco-111111111111-us-east-1/{$console->binaryKey}", $this->s3->requests()[0]['path']);
        self::assertStringStartsWith('AWS4-HMAC-SHA256 ', $this->s3->requests()[0]['authorization']);
        self::assertSame('token', $this->s3->requests()[0]['token']);
        self::assertSame(1, preg_match('/\{"arco":"binary_ready","seconds":(\d+),"bytes":(\d+)\}/', $this->logs(), $ready), $this->logs());
        self::assertSame($console->binaryBytes, (int) $ready[2]);
        $this->note(sprintf('console binary download + SHA-256: %d s (bootstrap, whole seconds), %.2F s (container start to binary_ready) for %.1F MB (local S3)',
            $ready[1], $this->secondsToBinaryReady(), $ready[2] / 1048576));
        self::assertLessThan(10, (int) $ready[1], 'Lambda limits init to 10 s');

        // Not in the arco: namespace: Symfony names only the namespace when it holds hidden commands alone (arco:tinker).
        [$failing, $seconds] = $this->timed(fn () => $this->respond($this->api?->enqueue(['command' => 'arco-does-not-exist']) ?? ''));
        self::assertNotSame(0, $failing['exitCode']);
        self::assertStringContainsString('Command "arco-does-not-exist" is not defined', $failing['output']);
        self::assertStringContainsString('"arco":"command_failed"', $this->logs());
        $this->note(sprintf('failing command (warm): %.2F s', $seconds));

        [$first, $seconds] = $this->timed(fn () => $this->tinker('$x = 41;', null));
        self::assertNotNull($first->context);
        $this->note(sprintf('tinker statement 1 (warm): %.2F s', $seconds));
        [$second, $seconds] = $this->timed(fn () => $this->tinker('$x + 1', $first->context));
        self::assertSame('42', $second->presented);
        $this->note(sprintf('tinker statement 2 (warm): %.2F s', $seconds));
        foreach (['$x = 41;', '$x + 1'] as $code) {
            $audit = substr((string) json_encode(['arco' => 'tinker', 'code' => $code], JSON_UNESCAPED_SLASHES), 0, -1);
            self::assertStringContainsString($audit, $this->logs(), 'every statement is audited in the function log');
        }
        self::assertCount(1, $this->s3->requests(), 'the binary is downloaded once per execution environment');
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

    /** Sub-second download + verification time: the container's start to the timestamp Docker gave the binary_ready line. */
    private function secondsToBinaryReady(): float
    {
        $inspect = new Process(['docker', 'inspect', '-f', '{{.State.StartedAt}}', (string) $this->container]);
        $inspect->mustRun();
        $logs = new Process(['docker', 'logs', '--timestamps', (string) $this->container]);
        $logs->mustRun();
        self::assertSame(1, preg_match('/^(\S+) \{"arco":"binary_ready"/m', $logs->getOutput(), $line));
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
