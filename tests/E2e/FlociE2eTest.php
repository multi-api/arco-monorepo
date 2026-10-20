<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\E2e;

use Aws\Route53\Route53Client;
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
 * "Known Floci gap: …" and the observed error; Arco gets no Floci-only code path. There are none now.
 */
#[Group('e2e')]
final class FlociE2eTest extends TestCase
{
    use E2eChecks;

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

    private static ?string $distribution = null;
    private static bool $deployed = false;

    protected static function environment(): string
    {
        return self::ENV;
    }

    public static function setUpBeforeClass(): void
    {
        self::refuseUnlessFloci();

        self::createHostedZone();
        self::$app = self::copyExample();

        $deploy = self::deploy();
        // Set before checking, so tearDownAfterClass removes whatever a failed deploy left behind.
        self::$deployed = true;
        if (! $deploy->isSuccessful()) {
            self::fail("[deploy] arco deploy failed:\n" . $deploy->getOutput() . $deploy->getErrorOutput());
        }
        fwrite(STDERR, "\n" . self::tail($deploy->getOutput(), 40) . "\n");

        $data = self::readInfo();
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
        $remove = self::arco(['remove', '--env=' . self::ENV, '--yes', '--no-interaction'], 900);
        self::report('remove', $began, $remove->isSuccessful() ? 'ok' : 'FAILED');
        if (! $remove->isSuccessful()) {
            // Not a failure of the run: bin/test-docker discards the whole Floci anyway.
            fwrite(STDERR, "[remove] arco remove failed:\n" . self::tail($remove->getOutput() . $remove->getErrorOutput(), 40) . "\n");
        }
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
        self::assertSame(200, $answer['status'], "[custom domain] $url with Host " . self::DOMAIN . ' (distribution ' . self::$distribution . ') answered '
            . $answer['status'] . ': ' . E2eHttp::excerpt($answer['body']) . "\nFloci's CloudFront log:\n" . self::flociLog('CloudFront'));
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
        self::fail("[storage] /e2e/storage answered {$answer['status']}: " . E2eHttp::excerpt($answer['body'])
            . ($error === null ? "\nLogs:\n" . self::tail($logs, 30) : ''));
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
}
