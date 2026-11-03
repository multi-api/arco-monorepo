<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use Multiapi\Arco\Build\PresetRegistry;
use Multiapi\Arco\Config\ConfigLoader;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Deploy\DeployPipeline;
use Multiapi\Arco\Tests\Support\SiblingContainers;
use Multiapi\Arco\Tests\Support\TempPaths;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use ZipArchive;

#[Group('integration')]
final class LambdaSimulationTest extends TestCase
{
    private const EXAMPLE = __DIR__ . '/../../examples/laravel';
    private ?string $container = null;

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
    }

    public function test_packaged_laravel_app_runs_like_on_lambda(): void
    {
        $example = (string) realpath(self::EXAMPLE);
        // A shared temp directory: the container bind-mounts the task unzipped there.
        $work = TempPaths::shared('arco-it-');
        mkdir("$work/task", 0777, true);

        // 1. Package with the real CLI.
        $package = new Process([PHP_BINARY, __DIR__ . '/../../packages/arco/bin/arco', 'package', '--env=ci', "--output=$work/package.zip", '--no-interaction'], $example, null, null, 900);
        $package->run();
        self::assertTrue($package->isSuccessful(), $package->getOutput() . $package->getErrorOutput());

        // 2. Unzip (ZipArchive drops the executable bit, which exercises the runtime's /tmp fallback).
        $zip = new ZipArchive();
        self::assertTrue($zip->open("$work/package.zip"));
        $zip->extractTo("$work/task");
        $zip->close();

        // 3. Run it the way Lambda does: read-only code at /var/task, read-only root, 512 MB /tmp.
        $config = ConfigResolver::resolve((new ConfigLoader())->load("$example/arco.php"), 'ci');
        $variables = DeployPipeline::variables($config, PresetRegistry::default()->detect($example));
        $this->container = 'arco-it-' . bin2hex(random_bytes(3));
        $command = ['docker', 'run', '-d', '--name', $this->container, '--platform', 'linux/arm64',
            '--read-only', '--tmpfs', '/tmp:size=512m,exec', '--cpus', '2', '--memory', '2g',
            '-v', "$work/task:/var/task:ro", '-w', '/var/task',
            '-e', 'AWS_LAMBDA_HTTP_ENDPOINT=0.0.0.0:8080', '-e', 'AWS_REGION=us-east-1'];
        // On the test network the container is reached by name; from the host, through a published port.
        $network = SiblingContainers::network();
        if ($network !== null) {
            array_push($command, '--network', $network);
            $baseUri = "http://{$this->container}:8080";
        } else {
            $port = random_int(20000, 29999);
            array_push($command, '-p', "$port:8080");
            $baseUri = "http://127.0.0.1:$port";
        }
        foreach ($variables as $name => $value) {
            array_push($command, '-e', "$name=$value");
        }
        array_push($command, 'node:24-slim', 'node', '/var/task/index.js');
        $run = new Process($command);
        $run->run();
        self::assertTrue($run->isSuccessful(), $run->getErrorOutput());

        $http = new Client(['base_uri' => $baseUri, 'http_errors' => false, 'timeout' => 10]);
        $deadline = microtime(true) + 30;
        do {
            try {
                $status = $http->get('/up')->getStatusCode();
            } catch (\Throwable) {
                $status = 0;
            }
            if ($status === 200) {
                break;
            }
            usleep(250_000);
        } while (microtime(true) < $deadline);
        self::assertSame(200, $status, (string) (new Process(['docker', 'logs', $this->container]))->mustRun()->getOutput());

        // 4. Laravel runs on FrankenPHP with storage in /tmp, and trusts the front end's proxy headers.
        $check = json_decode((string) $http->get('/arco-check?secret=1', ['headers' => ['X-Forwarded-Proto' => 'https', 'X-Amzn-RequestId' => 'it-request-1']])->getBody(), true);
        self::assertSame('frankenphp', $check['sapi']);
        self::assertSame('/tmp/arco/storage', $check['storage']);
        self::assertSame('ci', $check['environment']);
        self::assertSame('https', $check['scheme']);

        // 5. Concurrent slow requests are served in parallel by the Octane worker pool.
        $requests = (function () {
            for ($i = 0; $i < 20; $i++) {
                yield new Request('GET', '/arco-slow?ms=300');
            }
        })();
        $started = microtime(true);
        (new Pool($http, $requests, ['concurrency' => 20]))->promise()->wait();
        self::assertLessThan(2.5, microtime(true) - $started);

        // 6. accessLog(): one line per request, Caddy's own entry (headers, IP) never reaches the log. A request
        // that never reaches Laravel (a static file) is logged too.
        $http->get('/robots.txt');
        $logs = '';
        $deadline = microtime(true) + 5;
        do {
            $logs = (new Process(['docker', 'logs', $this->container]))->mustRun()->getOutput();
            if (str_contains($logs, '"path":"/robots.txt"')) {
                break;
            }
            usleep(200_000);
        } while (microtime(true) < $deadline);
        $lines = array_values(array_filter(
            array_map(static fn (string $line): mixed => json_decode($line, true), explode("\n", $logs)),
            static fn (mixed $line): bool => is_array($line) && ($line['arco'] ?? null) === 'request',
        ));
        $check = array_values(array_filter($lines, static fn (array $line): bool => $line['path'] === '/arco-check'));
        self::assertCount(1, $check, $logs);
        self::assertSame(['arco', 'status', 'method', 'path', 'ms', 'requestId'], array_keys($check[0]));
        self::assertSame(['arco' => 'request', 'status' => 200, 'method' => 'GET', 'path' => '/arco-check', 'requestId' => 'it-request-1'], array_diff_key($check[0], ['ms' => true]));
        self::assertIsInt($check[0]['ms']);
        self::assertCount(20, array_filter($lines, static fn (array $line): bool => $line['path'] === '/arco-slow'));
        self::assertGreaterThanOrEqual(300, max(array_map(static fn (array $line): int => $line['path'] === '/arco-slow' ? $line['ms'] : 0, $lines)));
        self::assertNotEmpty(array_filter($lines, static fn (array $line): bool => $line['path'] === '/robots.txt'), $logs);
        self::assertStringNotContainsString('http.log.access', $logs);
    }
}
