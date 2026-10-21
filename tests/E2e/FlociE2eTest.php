<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\E2e;

use Aws\CloudFormation\CloudFormationClient;
use Aws\DynamoDb\DynamoDbClient;
use Aws\Iam\IamClient;
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
    /** The second deploy, `arco deploy --json` (a redeploy of the same app): its exit code, stdout and stderr. */
    private static ?Process $jsonDeploy = null;
    /** @var array<string, mixed> `arco info --json` after the deploys */
    private static array $info = [];

    protected static function environment(): string
    {
        return self::ENV;
    }

    protected static function iam(): IamClient
    {
        return new IamClient([
            'region' => self::REQUIRED_ENV['AWS_REGION'],
            'endpoint' => self::REQUIRED_ENV['AWS_ENDPOINT_URL'],
            'credentials' => ['key' => 'test', 'secret' => 'test'],
        ]);
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

        // A redeploy streaming NDJSON: test_deploy_json checks the stream; every later check runs on it.
        self::$jsonDeploy = self::deploy(json: true);
        if (! self::$jsonDeploy->isSuccessful()) {
            self::fail("[deploy --json] arco deploy --json failed (exit " . self::$jsonDeploy->getExitCode() . "):\n"
                . self::tail(self::$jsonDeploy->getOutput(), 40) . "\n" . self::tail(self::$jsonDeploy->getErrorOutput(), 40));
        }

        $data = self::$info = self::readInfo();
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

    /** Every stdout line of `arco deploy --json` is a schema-1 JSON object, and the last is the deployed result. */
    #[Depends('test_up')]
    public function test_deploy_json(): void
    {
        \assert(self::$jsonDeploy !== null);
        $stdout = self::$jsonDeploy->getOutput();
        // Pure NDJSON: nothing but JSON lines, each ended by a newline, no blank or human line in between.
        self::assertStringEndsWith("\n", $stdout, '[deploy --json] stdout does not end with a newline');
        $lines = explode("\n", substr($stdout, 0, -1));
        self::assertNotSame([''], $lines, '[deploy --json] stdout is empty');

        $events = [];
        foreach ($lines as $number => $line) {
            $event = json_decode($line, true);
            self::assertIsArray($event, sprintf('[deploy --json] stdout line %d is not a JSON object: %s', $number + 1, E2eHttp::excerpt($line)));
            self::assertSame(1, $event['schema'] ?? null, sprintf('[deploy --json] stdout line %d has no "schema": 1: %s', $number + 1, E2eHttp::excerpt($line)));
            self::assertContains($event['type'] ?? null, ['step', 'progress', 'result'], sprintf('[deploy --json] stdout line %d has an unknown type: %s', $number + 1, E2eHttp::excerpt($line)));
            $events[] = $event;
        }

        $results = array_values(array_filter($events, static fn (array $event): bool => $event['type'] === 'result'));
        self::assertCount(1, $results, '[deploy --json] expected exactly one result line');
        $result = $events[array_key_last($events)];
        self::assertSame('result', $result['type'], '[deploy --json] the last line is not the result: ' . E2eHttp::excerpt((string) end($lines)));
        self::assertSame('deployed', $result['outcome'] ?? null, '[deploy --json] outcome: ' . E2eHttp::excerpt((string) end($lines)));
        self::assertArrayHasKey('error', $result, '[deploy --json] the result has no error key');
        self::assertNull($result['error'], '[deploy --json] the deployed result carries an error');
        self::assertSame('floci', $result['environment'] ?? null);
        self::assertSame([self::DOMAIN], $result['domains'] ?? null, '[deploy --json] result domains');
        self::assertSame(self::$info['revision'] ?? null, $result['revision'] ?? null, '[deploy --json] result revision is not the live one');
        self::assertSame(self::$url, is_string($result['url'] ?? null) ? rtrim($result['url'], '/') : null, '[deploy --json] result url differs from arco info --json');

        $steps = array_filter($events, static fn (array $event): bool => $event['type'] === 'step');
        self::assertNotSame([], $steps, '[deploy --json] no step events');
        foreach ($steps as $step) {
            self::assertIsString($step['key'] ?? null);
            self::assertContains($step['status'] ?? null, ['started', 'done', 'warning', 'note'], '[deploy --json] step status: ' . json_encode($step));
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) ($step['at'] ?? ''), '[deploy --json] step time');
            if ($step['status'] === 'done') {
                self::assertIsInt($step['elapsed_ms'] ?? null, '[deploy --json] a done step without elapsed_ms: ' . json_encode($step));
                self::assertGreaterThanOrEqual(0, $step['elapsed_ms']);
            } else {
                self::assertArrayNotHasKey('elapsed_ms', $step, '[deploy --json] elapsed_ms on a step that is not done: ' . json_encode($step));
            }
        }
        $done = array_filter($steps, static fn (array $step): bool => $step['status'] === 'done');
        self::assertNotSame([], $done, '[deploy --json] no step reached done');
        // The boundary of arco.php is in the role step's params, unredacted (0.11).
        $role = array_values(array_filter($steps, static fn (array $step): bool => $step['key'] === 'deploy.step_role' && $step['status'] === 'started'));
        self::assertSame(['role' => 'arco-arco-example-floci', 'boundary' => 'arn:aws:iam::aws:policy/PowerUserAccess'], $role[0]['params'] ?? null, '[deploy --json] deploy.step_role params');
        $zip = array_filter($events, static fn (array $event): bool => $event['type'] === 'progress' && ($event['key'] ?? null) === 'build.zip' && ($event['percent'] ?? null) === 100);
        self::assertNotSame([], $zip, '[deploy --json] no build.zip progress reaching 100');
    }

    /** The table ->table('state', ttl: 'expires_at') creates: PK/SK strings, on demand, TTL on expires_at, in info --json. */
    #[Depends('test_up')]
    public function test_table_resource(): void
    {
        $tables = self::$info['tables'] ?? null;
        self::assertIsArray($tables, '[table] arco info --json has no tables key');
        self::assertSame('state', $tables[0]['name'] ?? null, '[table] arco info --json tables: ' . json_encode($tables));
        $name = $tables[0]['table'] ?? null;
        self::assertSame('arco-example-floci-state', $name, '[table] arco info --json tables: ' . json_encode($tables));

        $dynamo = new DynamoDbClient([
            'region' => self::REQUIRED_ENV['AWS_REGION'],
            'endpoint' => self::REQUIRED_ENV['AWS_ENDPOINT_URL'],
            'credentials' => ['key' => 'test', 'secret' => 'test'],
        ]);
        $table = $dynamo->describeTable(['TableName' => $name])['Table'];
        self::assertSame([['AttributeName' => 'PK', 'KeyType' => 'HASH'], ['AttributeName' => 'SK', 'KeyType' => 'RANGE']], $table['KeySchema'], '[table] key schema');
        self::assertEqualsCanonicalizing([['AttributeName' => 'PK', 'AttributeType' => 'S'], ['AttributeName' => 'SK', 'AttributeType' => 'S']], $table['AttributeDefinitions'], '[table] key types');
        if (isset($table['BillingModeSummary'])) {
            self::assertSame('PAY_PER_REQUEST', $table['BillingModeSummary']['BillingMode'] ?? null, '[table] billing mode; DescribeTable: ' . json_encode($table));
        } else {
            // Known Floci gap: DescribeTable of a table CloudFormation created with BillingMode PAY_PER_REQUEST has no
            // BillingModeSummary and reports ProvisionedThroughput 5/5. The billing mode is checked in the template instead.
            fwrite(STDERR, "[table] Known Floci gap: DescribeTable has no BillingModeSummary (ProvisionedThroughput "
                . json_encode($table['ProvisionedThroughput'] ?? null) . "); checking BillingMode in the resources stack template\n");
            $template = (new CloudFormationClient([
                'region' => self::REQUIRED_ENV['AWS_REGION'],
                'endpoint' => self::REQUIRED_ENV['AWS_ENDPOINT_URL'],
                'credentials' => ['key' => 'test', 'secret' => 'test'],
            ]))->getTemplate(['StackName' => 'arco-arco-example-floci-resources'])['TemplateBody'] ?? null;
            $body = is_string($template) ? json_decode($template, true) : $template;
            self::assertSame('PAY_PER_REQUEST', $body['Resources']['AppTableState']['Properties']['BillingMode'] ?? null, '[table] billing mode in the template: ' . E2eHttp::excerpt((string) json_encode($body['Resources']['AppTableState'] ?? $template)));
        }

        $ttl = $dynamo->describeTimeToLive(['TableName' => $name])['TimeToLiveDescription'] ?? [];
        self::assertSame('expires_at', $ttl['AttributeName'] ?? null, '[table] TTL attribute: ' . json_encode($ttl));
        self::assertContains($ttl['TimeToLiveStatus'] ?? null, ['ENABLED', 'ENABLING'], '[table] TTL status: ' . json_encode($ttl));

        $tags = [];
        foreach ($dynamo->listTagsOfResource(['ResourceArn' => $table['TableArn']])['Tags'] ?? [] as $tag) {
            $tags[$tag['Key']] = $tag['Value'];
        }
        $ours = array_intersect_key($tags, array_flip(['arco:app', 'arco:environment', 'arco:resource']));
        ksort($ours);
        self::assertSame(['arco:app' => 'arco-example', 'arco:environment' => 'floci', 'arco:resource' => 'table:state'], $ours, '[table] tags');
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
