<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use Multiapi\Arco\Arco;
use Multiapi\Arco\Build\PresetRegistry;
use Multiapi\Arco\Config\ConfigLoader;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Deploy\DeployPipeline;
use Multiapi\Arco\Tests\Support\SiblingContainers;
use Multiapi\Arco\Tests\Support\TelemetryCollector;
use Multiapi\Arco\Tests\Support\TempPaths;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use ZipArchive;

#[Group('integration')]
final class LambdaSimulationTest extends TestCase
{
    private const EXAMPLE = __DIR__ . '/../../examples/laravel';
    /** The example app packaged once per run: [shared work directory, task directory]. @var array{string, string}|null */
    private static ?array $package = null;
    /** @var list<string> */
    private array $containers = [];
    /** @var list<TelemetryCollector> */
    private array $collectors = [];

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
        foreach ($this->containers as $container) {
            (new Process(['docker', 'rm', '-f', $container]))->run();
        }
        foreach ($this->collectors as $collector) {
            $collector->close();
        }
    }

    public function test_packaged_laravel_app_runs_like_on_lambda(): void
    {
        $collector = $this->collector();
        [$container, $http, $responses] = $this->start(['ARCO_TELEMETRY_HOST' => $collector->forContainers()]);

        // 4. Laravel runs on FrankenPHP with storage in /tmp, and trusts the front end's proxy headers.
        $check = json_decode((string) $http->get('/arco-check?secret=1', ['headers' => ['X-Forwarded-Proto' => 'https', 'X-Amzn-RequestId' => 'it-request-1']])->getBody(), true);
        self::assertSame('frankenphp', $check['sapi']);
        self::assertSame('/tmp/arco/storage', $check['storage']);
        self::assertSame('ci', $check['environment']);
        self::assertSame('https', $check['scheme']);
        self::assertSame('it-request-1', $check['requestId'], 'the caller\'s id is kept');
        // Lambda Web Functions send no request id: the runtime makes one up, which Laravel and the access log share.
        $generated = json_decode((string) $http->get('/arco-check')->getBody(), true)['requestId'];
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', (string) $generated);
        $responses += 2;

        // 5. Concurrent slow requests are served in parallel by the Octane worker pool.
        $requests = (function () {
            for ($i = 0; $i < 20; $i++) {
                yield new Request('GET', '/arco-slow?ms=300');
            }
        })();
        $started = microtime(true);
        (new Pool($http, $requests, ['concurrency' => 20, 'fulfilled' => static function () use (&$responses): void { $responses++; }]))->promise()->wait();
        self::assertLessThan(2.5, microtime(true) - $started);

        // 6. accessLog(): one line per request, Caddy's own entry (headers, IP) never reaches the log. A request
        // that never reaches Laravel (a static file) is logged too.
        $http->get('/robots.txt');
        $responses++;
        $logs = '';
        $deadline = microtime(true) + 5;
        do {
            $logs = $this->logs($container);
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
        self::assertCount(2, $check, $logs);
        self::assertSame($generated, $check[1]['requestId'], 'the access line has the id Laravel logged with');
        self::assertSame(['arco', 'status', 'method', 'path', 'ms', 'requestId'], array_keys($check[0]));
        self::assertSame(['arco' => 'request', 'status' => 200, 'method' => 'GET', 'path' => '/arco-check', 'requestId' => 'it-request-1'], array_diff_key($check[0], ['ms' => true]));
        self::assertIsInt($check[0]['ms']);
        self::assertCount(20, array_filter($lines, static fn (array $line): bool => $line['path'] === '/arco-slow'));
        self::assertGreaterThanOrEqual(300, max(array_map(static fn (array $line): int => $line['path'] === '/arco-slow' ? $line['ms'] : 0, $lines)));
        self::assertNotEmpty(array_filter($lines, static fn (array $line): bool => $line['path'] === '/robots.txt' && is_string($line['requestId'])), $logs);
        self::assertStringNotContainsString('http.log.access', $logs);
        // Caddy's arco_count logger keeps no request data, and never prints an entry to the function log.
        self::assertStringNotContainsString('arco_count', $logs);

        // 7. Telemetry: the first response sends the cold-start packet at once; the rest wait for the next minute or,
        // here, for SIGTERM. Every response is counted exactly once.
        $first = $collector->receive();
        self::assertNotNull($first, 'the first response sends a packet: ' . $logs);
        self::assertSame(1, preg_match(self::packet('web', cold: true), $first, $match), $first);
        self::assertNull($collector->receive(0.5), 'nothing more is sent within the minute');
        (new Process(['docker', 'stop', '-t', '10', $container]))->mustRun();
        $rest = $collector->drain();
        self::assertCount(1, $rest, 'SIGTERM sends what is pending: ' . implode("\n", $rest));
        self::assertSame(1, preg_match(self::packet('web', cold: false), $rest[0], $flushed), $rest[0]);
        self::assertSame($responses, (int) $match[1] + (int) $flushed[1], "$first\n$rest[0]");
    }

    /**
     * ARCO_TELEMETRY_DISABLE=1 in the function and `"telemetry": false` in arco-runtime.json (what ->telemetry(false)
     * packages) send nothing and leave Caddy without the counting logger. Also measures what counting costs a request.
     */
    public function test_telemetry_switches_send_nothing_and_counting_costs_next_to_nothing(): void
    {
        $on = $this->collector();
        $offByEnv = $this->collector();
        $offByConfig = $this->collector();
        [$onContainer, $onHttp, $onResponses] = $this->start(['ARCO_TELEMETRY_HOST' => $on->forContainers()]);
        [$envContainer, $envHttp] = $this->start(['ARCO_TELEMETRY_HOST' => $offByEnv->forContainers(), 'ARCO_TELEMETRY_DISABLE' => '1']);
        [$configContainer, $configHttp] = $this->start(['ARCO_TELEMETRY_HOST' => $offByConfig->forContainers()], ['telemetry' => false]);

        self::assertStringContainsString('arco_count', $this->caddyfile($onContainer));
        self::assertStringNotContainsString('arco_count', $this->caddyfile($envContainer));
        self::assertStringNotContainsString('arco_count', $this->caddyfile($configContainer));

        // 1,000 sequential requests per path and container, interleaved in rounds so drift hits both alike. A static
        // file is Caddy alone (the counting logger is the whole difference); /up runs Laravel.
        $paths = ['/robots.txt', '/up'];
        $seconds = ['on' => array_fill_keys($paths, 0.0), 'off' => array_fill_keys($paths, 0.0)];
        for ($round = 0; $round < 5; $round++) {
            foreach ($paths as $path) {
                foreach (['on' => $onHttp, 'off' => $envHttp] as $mode => $http) {
                    $started = hrtime(true);
                    for ($i = 0; $i < 200; $i++) {
                        self::assertSame(200, $http->get($path)->getStatusCode());
                    }
                    $seconds[$mode][$path] += (hrtime(true) - $started) / 1e9;
                }
            }
        }
        $onResponses += 2000;
        foreach ($paths as $path) {
            fwrite(STDERR, sprintf("\n[telemetry overhead] 1,000 x GET %s: on %.3F ms/request, off %.3F ms/request (difference %+.3F ms)\n",
                $path, $seconds['on'][$path], $seconds['off'][$path], $seconds['on'][$path] - $seconds['off'][$path]));
        }
        for ($i = 0; $i < 3; $i++) {
            self::assertSame(200, $configHttp->get('/robots.txt')->getStatusCode());
        }

        self::assertSame(1, preg_match(self::packet('web', cold: true), (string) $on->receive(), $first));
        foreach ([$onContainer, $envContainer, $configContainer] as $container) {
            (new Process(['docker', 'stop', '-t', '10', $container]))->mustRun();
        }
        $rest = $on->drain();
        self::assertCount(1, $rest, implode("\n", $rest));
        self::assertSame(1, preg_match(self::packet('web', cold: false), $rest[0], $flushed), $rest[0]);
        self::assertSame($onResponses, (int) $first[1] + (int) $flushed[1]);
        self::assertSame([], $offByEnv->drain(), 'ARCO_TELEMETRY_DISABLE=1 sends nothing');
        self::assertSame([], $offByConfig->drain(), '"telemetry": false sends nothing');
    }

    /**
     * The default web path: direct mode without accessLog(). Caddy's site logger outputs to discard and the arco_count
     * logger is the only one that sees an entry, so every response is counted and none reaches the function log.
     */
    public function test_direct_mode_without_access_log_counts_every_response_and_logs_none(): void
    {
        $collector = $this->collector();
        [$container, $http, $responses] = $this->start(['ARCO_TELEMETRY_HOST' => $collector->forContainers()], ['accessLog' => false]);
        $caddyfile = $this->caddyfile($container);
        self::assertStringContainsString('arco_count', $caddyfile);
        self::assertStringContainsString('output discard', $caddyfile);

        // Laravel, a static file, a 404 and concurrent slow requests, each with a URI the log must never show.
        foreach (['/arco-check?secret=it-uri-marker', '/robots.txt?it-uri-marker', '/it-uri-marker-missing', '/up'] as $path) {
            $http->get($path);
            $responses++;
        }
        $requests = (function () {
            for ($i = 0; $i < 10; $i++) {
                yield new Request('GET', "/arco-slow?ms=100&it-uri-marker=$i");
            }
        })();
        (new Pool($http, $requests, ['concurrency' => 10, 'fulfilled' => static function () use (&$responses): void { $responses++; }]))->promise()->wait();

        $first = $collector->receive();
        self::assertNotNull($first, 'the first response sends a packet: ' . $this->logs($container, stderr: true));
        self::assertSame(1, preg_match(self::packet('web', cold: true), $first, $match), $first);
        (new Process(['docker', 'stop', '-t', '10', $container]))->mustRun();
        $rest = $collector->drain();
        self::assertCount(1, $rest, 'SIGTERM sends what is pending: ' . implode("\n", $rest));
        self::assertSame(1, preg_match(self::packet('web', cold: false), $rest[0], $flushed), $rest[0]);
        self::assertSame($responses, (int) $match[1] + (int) $flushed[1], "$first\n$rest[0]");

        // stdout and stderr: no Caddy access entry (logged, or printed after a lost connection), no access line.
        $logs = $this->logs($container, stderr: true);
        self::assertStringContainsString('serving initial configuration', $logs, 'Caddy\'s own log (stderr) is read too');
        self::assertStringNotContainsString('http.log.access', $logs);
        self::assertStringNotContainsString('handled request', $logs);
        self::assertStringNotContainsString('"uri"', $logs);
        self::assertStringNotContainsString('it-uri-marker', $logs);
        self::assertStringNotContainsString('"arco":"request"', $logs);
        self::assertStringNotContainsString('arco_count', $logs);
    }

    /** A packet of this run's Arco version from us-east-1; group 1 is the count. */
    private static function packet(string $kind, bool $cold): string
    {
        $version = preg_quote(Arco::version(), '/');
        return "/^arco1 r=laravel k=$kind v=$version g=us-east-1 i=([1-9][0-9]*) " . ($cold ? 'c=1 b=[0-3]' : 'c=0') . '$/';
    }

    private function collector(): TelemetryCollector
    {
        return $this->collectors[] = new TelemetryCollector(SiblingContainers::BIND);
    }

    /** The example app packaged with the real CLI and unzipped like Lambda, once per run. @return array{string, string} */
    private static function package(): array
    {
        if (self::$package !== null) {
            return self::$package;
        }
        $example = (string) realpath(self::EXAMPLE);
        // A shared temp directory: the container bind-mounts the task unzipped there.
        $work = TempPaths::shared('arco-it-', untilRunEnds: true);
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
        return self::$package = [$work, "$work/task"];
    }

    /**
     * 3. Runs the package the way Lambda does: read-only code at /var/task, read-only root, 512 MB /tmp. Waits for /up.
     *
     * @param array<string, string> $env extra variables
     * @param array<string, mixed> $runtimeConfig keys that replace arco-runtime.json's (mounted over the package's)
     * @return array{string, Client, int} the container, a client for it, and how many responses /up polling got
     */
    private function start(array $env, array $runtimeConfig = []): array
    {
        [$work, $task] = self::package();
        $example = (string) realpath(self::EXAMPLE);
        $config = ConfigResolver::resolve((new ConfigLoader())->load("$example/arco.php"), 'ci');
        $variables = DeployPipeline::variables($config, PresetRegistry::default()->detect($example));
        $container = $this->containers[] = 'arco-it-' . bin2hex(random_bytes(3));
        $command = ['docker', 'run', '-d', '--name', $container, '--platform', 'linux/arm64',
            '--read-only', '--tmpfs', '/tmp:size=512m,exec', '--cpus', '2', '--memory', '2g',
            '-v', "$task:/var/task:ro", '-w', '/var/task',
            '-e', 'AWS_LAMBDA_HTTP_ENDPOINT=0.0.0.0:8080', '-e', 'AWS_REGION=us-east-1'];
        if ($runtimeConfig !== []) {
            $file = "$work/arco-runtime-$container.json";
            $packaged = json_decode((string) file_get_contents("$task/arco-runtime.json"), true, flags: JSON_THROW_ON_ERROR);
            file_put_contents($file, json_encode([...$packaged, ...$runtimeConfig], JSON_UNESCAPED_SLASHES));
            array_push($command, '-v', "$file:/var/task/arco-runtime.json:ro");
        }
        // On the test network the container is reached by name; from the host, through a published port.
        $network = SiblingContainers::network();
        if ($network !== null) {
            array_push($command, '--network', $network);
            $baseUri = "http://$container:8080";
        } else {
            $port = random_int(20000, 29999);
            array_push($command, '-p', "$port:8080", '--add-host', 'host.docker.internal:host-gateway');
            $baseUri = "http://127.0.0.1:$port";
        }
        foreach ([...$variables, ...$env] as $name => $value) {
            array_push($command, '-e', "$name=$value");
        }
        array_push($command, 'node:24-slim', 'node', '/var/task/index.js');
        $run = new Process($command);
        $run->run();
        self::assertTrue($run->isSuccessful(), $run->getErrorOutput());

        $http = new Client(['base_uri' => $baseUri, 'http_errors' => false, 'timeout' => 10]);
        $responses = 0;
        $deadline = microtime(true) + 30;
        do {
            try {
                $status = $http->get('/up')->getStatusCode();
                $responses++;
            } catch (\Throwable) {
                $status = 0;
            }
            if ($status === 200) {
                break;
            }
            usleep(250_000);
        } while (microtime(true) < $deadline);
        self::assertSame(200, $status, $this->logs($container));
        return [$container, $http, $responses];
    }

    private function caddyfile(string $container): string
    {
        return (new Process(['docker', 'exec', $container, 'cat', '/tmp/arco/Caddyfile']))->mustRun()->getOutput();
    }

    /** The container's stdout, and its stderr after it when $stderr. */
    private function logs(string $container, bool $stderr = false): string
    {
        $process = (new Process(['docker', 'logs', $container]))->mustRun();
        return $process->getOutput() . ($stderr ? $process->getErrorOutput() : '');
    }
}
