<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\E2e;

use Aws\Route53\Route53Client;
use Multiapi\Arco\Tests\Support\TempPaths;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Deploys examples/laravel (environment `floci`) to a throwaway local Floci and checks every feature
 * through the real CLI. Run only by `bin/test-docker e2e` (composer test:e2e), which starts Floci, runs
 * this suite in Floci's network namespace and removes everything on exit. See docs/maintainers/e2e.md.
 *
 * A check that cannot pass because Floci lacks or breaks a whole feature is skipped with
 * "Known Floci gap: …" and the observed error; Arco gets no Floci-only code path.
 */
#[Group('e2e')]
final class FlociE2eTest extends TestCase
{
    /** The exact environment bin/test-docker e2e sets: anything else could reach a real AWS account. */
    private const REQUIRED_ENV = [
        'AWS_ENDPOINT_URL' => 'http://localhost:4566',
        'AWS_ACCESS_KEY_ID' => 'test',
        'AWS_SECRET_ACCESS_KEY' => 'test',
        'AWS_REGION' => 'us-east-1',
        'AWS_CONFIG_FILE' => '/dev/null',
        'AWS_SHARED_CREDENTIALS_FILE' => '/dev/null',
    ];
    /** Set in the environment would override the keys above in the SDK's credential chain. */
    private const FORBIDDEN_ENV = ['AWS_PROFILE', 'AWS_SESSION_TOKEN', 'AWS_ROLE_ARN', 'AWS_WEB_IDENTITY_TOKEN_FILE', 'AWS_CONTAINER_CREDENTIALS_FULL_URI', 'AWS_CONTAINER_CREDENTIALS_RELATIVE_URI'];

    private const ENV = 'floci';
    private const ZONE = 'arco-e2e.test';
    private const DOMAIN = 'app.arco-e2e.test';
    private const ACL_GAP = 'AccessControlListNotSupported';

    private static ?string $app = null;
    private static ?string $url = null;
    private static ?string $distribution = null;
    private static bool $deployed = false;
    private static float $started = 0.0;

    private E2eHttp $http;

    public static function setUpBeforeClass(): void
    {
        self::refuseUnlessFloci();

        $began = microtime(true);
        self::createHostedZone();
        self::$app = self::copyExample();

        $deploy = self::arco(['deploy', '--env=' . self::ENV, '--yes', '--no-interaction'], 1800);
        self::report('deploy', $began, $deploy->isSuccessful() ? 'ok' : 'FAILED');
        // Set before checking, so tearDownAfterClass removes whatever a failed deploy left behind.
        self::$deployed = true;
        if (! $deploy->isSuccessful()) {
            self::fail("[deploy] arco deploy failed:\n" . $deploy->getOutput() . $deploy->getErrorOutput());
        }
        fwrite(STDERR, "\n" . self::tail($deploy->getOutput(), 40) . "\n");

        $info = self::arco(['info', '--env=' . self::ENV, '--json'], 120);
        $data = json_decode($info->getOutput(), true);
        if (! is_array($data) || ! is_string($data['url'] ?? null)) {
            self::fail("[deploy] arco info --json gave no URL:\n" . $info->getOutput() . $info->getErrorOutput());
        }
        self::$url = rtrim($data['url'], '/');
        $distribution = $data['distribution'] ?? null;
        self::$distribution = is_array($distribution) && is_string($distribution['domain'] ?? null) ? $distribution['domain'] : null;
        fwrite(STDERR, sprintf("[deploy] url %s, distribution %s\n", self::$url, self::$distribution ?? '-'));
    }

    public static function tearDownAfterClass(): void
    {
        if (! self::$deployed) {
            return;
        }
        $began = microtime(true);
        $remove = self::arco(['remove', '--env=' . self::ENV, '--force', '--no-interaction'], 900);
        self::report('remove', $began, $remove->isSuccessful() ? 'ok' : 'FAILED');
        if (! $remove->isSuccessful()) {
            // Not a failure of the run: bin/test-docker discards the whole Floci anyway.
            fwrite(STDERR, "[remove] arco remove failed:\n" . self::tail($remove->getOutput() . $remove->getErrorOutput(), 40) . "\n");
        }
    }

    protected function setUp(): void
    {
        $this->http = new E2eHttp();
        self::$started = microtime(true);
    }

    protected function tearDown(): void
    {
        self::report($this->name(), self::$started, $this->status()->asString());
    }

    public function test_up(): void
    {
        // The first request after a deploy may pull the runtime image and cold-start (20-30 s on Floci).
        $answer = $this->http->get('up', self::url('/up'), deadlineSeconds: 180);
        self::assertSame(200, $answer['status'], '[up] /up answered ' . $answer['status'] . ': ' . E2eHttp::excerpt($answer['body']));
    }

    #[Depends('test_up')]
    public function test_custom_domain_through_cloudfront(): void
    {
        if (self::$distribution === null) {
            self::fail('[custom domain] arco info --json shows no CloudFront distribution for ' . self::DOMAIN);
        }
        // The distribution's domain is *.cloudfront.net, which does not lead to Floci. Floci serves
        // CloudFront on its own port and picks the distribution by Host, as the alias DNS record would.
        $url = self::REQUIRED_ENV['AWS_ENDPOINT_URL'] . '/up';
        $answer = $this->http->get('custom domain', $url, ['Host' => self::DOMAIN], 30);
        if ($answer['status'] === 502) {
            // Floci refuses loopback origins unless allowed-private-origin-hosts names them exactly (no
            // wildcards) and cannot resolve *.localhost itself, so the Web Function endpoint (a random
            // host under localhost) can never be the origin. Skip only when the log shows exactly that
            // for this deploy's endpoint: then the alias and the origin Arco set up are both right.
            $origin = (string) parse_url((string) self::$url, PHP_URL_HOST);
            if ($origin === '') {
                self::fail('[custom domain] answered 502 and the function URL has no host to match in Floci\'s log: ' . self::$url);
            }
            $log = self::flociLog("custom origin fetch failed for host $origin");
            if (str_contains($log, 'UnknownHostException')) {
                self::markTestSkipped('Known Floci gap: CloudFront cannot fetch from a Web Function endpoint origin '
                    . '(allowed-private-origin-hosts matches exact names only; *.localhost does not resolve in Floci). Observed: '
                    . E2eHttp::excerpt((string) strrchr("\n" . $log, "\n"), 300));
            }
        }
        self::assertSame(200, $answer['status'], "[custom domain] $url with Host " . self::DOMAIN . ' (distribution ' . self::$distribution . ') answered '
            . $answer['status'] . ': ' . E2eHttp::excerpt($answer['body']) . "\nFloci's CloudFront log:\n" . self::flociLog('CloudFront'));
    }

    #[Depends('test_up')]
    public function test_cache(): void
    {
        $data = $this->http->json('cache', self::url('/e2e/cache'));
        self::assertTrue($data['ok'] ?? null, '[cache] the DynamoDB cache did not return the value just stored: ' . json_encode($data));
    }

    #[Depends('test_up')]
    public function test_storage(): void
    {
        $answer = $this->http->get('storage', self::url('/e2e/storage'), retryServerErrors: false);
        if ($answer['status'] === 200) {
            $data = json_decode($answer['body'], true);
            self::assertTrue(is_array($data) && ($data['ok'] ?? null) === true, '[storage] S3 did not return the file just written: ' . E2eHttp::excerpt($answer['body']));
            return;
        }
        // The route answers S3's error in its body; the function's log is only the fallback when it has none.
        $data = json_decode($answer['body'], true);
        $error = is_array($data) && is_string($data['error'] ?? null) && $data['error'] !== '' ? $data['error'] : null;
        $logs = $error === null ? self::arco(['logs', '--env=' . self::ENV, '--since=10m'], 120)->getOutput() : '';
        $evidence = $error ?? $logs;
        if (str_contains($evidence, self::ACL_GAP)) {
            self::markTestSkipped('Known Floci gap: Flysystem sends x-amz-acl private (AccessControlListNotSupported). Observed: '
                . self::firstLineWith($evidence, self::ACL_GAP));
        }
        self::fail("[storage] /e2e/storage answered {$answer['status']}: " . E2eHttp::excerpt($answer['body'])
            . ($error === null ? "\nLogs:\n" . self::tail($logs, 30) : ''));
    }

    #[Depends('test_up')]
    public function test_queue_job(): void
    {
        $dispatched = $this->http->json('queue', self::url('/e2e/dispatch'));
        $id = $dispatched['id'] ?? null;
        self::assertIsString($id, '[queue] /e2e/dispatch returned no job id: ' . json_encode($dispatched));

        $status = null;
        $deadline = time() + 120;
        while (time() < $deadline) {
            // The request's own retries stay within what is left of the 120 s.
            $status = $this->http->json('queue', self::url("/e2e/job/$id"), deadlineSeconds: max(1, $deadline - time()))['status'] ?? null;
            if ($status === 'done') {
                break;
            }
            sleep(5);
        }
        self::assertSame('done', $status, "[queue] job $id was not processed by the queue worker within 120 s");
    }

    #[Depends('test_up')]
    public function test_scheduler(): void
    {
        $last = null;
        $deadline = time() + 150;
        while (time() < $deadline) {
            $last = $this->http->json('scheduler', self::url('/e2e/schedule'), deadlineSeconds: max(1, $deadline - time()))['last'] ?? null;
            // Any non-empty marker counts: DynamoDB may hand a numeric-looking string back as a number.
            if (is_scalar($last) && (string) $last !== '') {
                break;
            }
            sleep(5);
        }
        self::assertTrue(is_scalar($last) && (string) $last !== '', '[scheduler] the scheduled task did not run within 150 s (no e2e-schedule in the cache)');
    }

    #[Depends('test_up')]
    public function test_arco_command(): void
    {
        $command = self::arco(['command', '--env=' . self::ENV, 'about --only=environment', '--no-interaction'], 300);
        $output = $command->getOutput() . $command->getErrorOutput();
        self::assertTrue($command->isSuccessful(), "[arco command] exit {$command->getExitCode()}:\n" . self::tail($output, 40));
        self::assertStringContainsString('production', $output, "[arco command] output does not show the production environment:\n" . self::tail($output, 40));
    }

    #[Depends('test_up')]
    public function test_logs(): void
    {
        $logs = self::arco(['logs', '--env=' . self::ENV, '--since=10m', '--no-interaction'], 120);
        self::assertTrue($logs->isSuccessful(), "[logs] exit {$logs->getExitCode()}:\n" . self::tail($logs->getOutput() . $logs->getErrorOutput(), 40));
        self::assertNotSame('', trim($logs->getOutput()), '[logs] arco logs printed nothing for the last 10 minutes');
    }

    private static function refuseUnlessFloci(): void
    {
        $problems = [];
        foreach (self::REQUIRED_ENV as $name => $expected) {
            $value = getenv($name);
            if ($value !== $expected) {
                $problems[] = sprintf('%s must be %s (is %s)', $name, $expected, $value === false ? 'unset' : "'$value'");
            }
        }
        foreach (self::FORBIDDEN_ENV as $name) {
            if (getenv($name) !== false && getenv($name) !== '') {
                $problems[] = "$name must be unset";
            }
        }
        if ($problems !== []) {
            self::fail("Refusing to run the Floci end-to-end suite, it could reach a real AWS account. Run it with composer test:e2e.\n- "
                . implode("\n- ", $problems));
        }
    }

    /** The Route 53 zone of the custom domain: Arco adds the certificate validation and alias records to it. */
    private static function createHostedZone(): void
    {
        $route53 = new Route53Client([
            'region' => self::REQUIRED_ENV['AWS_REGION'],
            'endpoint' => self::REQUIRED_ENV['AWS_ENDPOINT_URL'],
            'credentials' => ['key' => 'test', 'secret' => 'test'],
        ]);
        $route53->createHostedZone(['Name' => self::ZONE, 'CallerReference' => 'arco-e2e-' . bin2hex(random_bytes(6))]);
    }

    /**
     * Copies examples/laravel without its dependencies to a run-long shared temp path (deploy writes
     * .arco/ into the project) and points its path repository at the monorepo's packages, since the
     * relative ../../packages no longer resolves from the copy.
     */
    private static function copyExample(): string
    {
        $repo = dirname(__DIR__, 2);
        $source = "$repo/examples/laravel";
        $target = TempPaths::shared('e2e-app-', untilRunEnds: true);
        mkdir($target, 0777, true);

        $skip = ['vendor', 'node_modules', '.arco', '.git'];
        $items = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $file, string $path, \RecursiveDirectoryIterator $iterator): bool => ! ($iterator->getSubPath() === '' && in_array($file->getFilename(), $skip, true)),
            ),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($items as $item) {
            \assert($item instanceof \SplFileInfo);
            $destination = $target . substr($item->getPathname(), strlen($source));
            if ($item->isDir() && ! $item->isLink()) {
                mkdir($destination, 0777, true);
            } elseif (! copy($item->getPathname(), $destination)) {
                throw new \RuntimeException("Cannot copy {$item->getPathname()}.");
            }
        }

        foreach (['composer.json', 'composer.lock'] as $file) {
            $contents = (string) file_get_contents("$target/$file");
            file_put_contents("$target/$file", str_replace('"../../packages/', '"' . "$repo/packages/", $contents));
        }

        return $target;
    }

    /** @param list<string> $arguments */
    private static function arco(array $arguments, int $timeout): Process
    {
        \assert(self::$app !== null);
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2) . '/packages/arco/bin/arco', ...$arguments], self::$app, null, null, $timeout);
        $process->run();
        return $process;
    }

    private static function url(string $path): string
    {
        \assert(self::$url !== null);
        return self::$url . $path;
    }

    /** The last lines of the Floci container's log that mention $needle (bin/test-docker names the container). */
    private static function flociLog(string $needle): string
    {
        $container = getenv('ARCO_E2E_FLOCI_CONTAINER');
        if (! is_string($container) || $container === '') {
            return '(ARCO_E2E_FLOCI_CONTAINER is not set)';
        }
        $logs = new Process(['docker', 'logs', '--tail', '2000', $container]);
        $logs->run();
        $lines = array_filter(explode("\n", $logs->getOutput() . $logs->getErrorOutput()), static fn (string $line): bool => str_contains($line, $needle));
        return implode("\n", array_slice($lines, -10)) ?: '(nothing)';
    }

    private static function report(string $check, float $began, string $result): void
    {
        fwrite(STDERR, sprintf("[e2e] %-40s %-10s %7.1f s\n", $check, $result, microtime(true) - $began));
    }

    private static function tail(string $text, int $lines): string
    {
        return implode("\n", array_slice(explode("\n", rtrim($text)), -$lines));
    }

    private static function firstLineWith(string $text, string $needle): string
    {
        foreach (explode("\n", $text) as $line) {
            if (str_contains($line, $needle)) {
                return E2eHttp::excerpt($line, 300);
            }
        }
        return $needle;
    }
}
