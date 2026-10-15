<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler as HttpMock;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Deploy\AwsVerifier;
use Multiapi\Arco\Deploy\DeployResult;
use Multiapi\Arco\Deploy\VerificationResult;
use PHPUnit\Framework\TestCase;

final class AwsVerifierTest extends TestCase
{
    /** The deploy started here; older events belong to earlier revisions. */
    private const SINCE = 4000;

    private function config(): AppConfig
    {
        return ConfigResolver::resolve(App::make('shop')->region('us-east-1')->environment('production')->toArray(), 'production');
    }

    private function iamConfig(): AppConfig
    {
        return ConfigResolver::resolve(App::make('shop')->region('us-east-1')->auth('iam')->environment('production')->toArray(), 'production');
    }

    /** @param list<Response|ConnectException> $responses */
    private function http(array $responses): Client
    {
        return new Client(['handler' => HandlerStack::create(new HttpMock($responses))]);
    }

    private static function initStart(string $instance, string $revision = 'rev-2'): array
    {
        return ['type' => 'platform.initStart', 'record' => ['revisionId' => $revision, 'instanceId' => $instance]];
    }

    private static function initReport(string $instance, float $ms, string $status = 'success', ?string $errorType = null): array
    {
        return ['type' => 'platform.initReport', 'record' => ['status' => $status, 'instanceId' => $instance, 'metrics' => ['totalDurationMs' => $ms]] + ($errorType === null ? [] : ['errorType' => $errorType])];
    }

    /** @param list<Response|ConnectException> $responses */
    private function verify(FakeLogGroup $group, array $responses = [new Response(200)], bool $iam = false, int $attempts = 5, ?array &$steps = null): VerificationResult
    {
        $steps = [];
        $verifier = new AwsVerifier($group->client(), $this->http($responses), $group, attempts: $attempts);
        return $verifier->verify($iam ? $this->iamConfig() : $this->config(), new DeployResult('abc.on.aws', 'rev-2', 'rev-1', 'bucket', 'key'), '/up', self::SINCE, function (string $key) use (&$steps): void {
            $steps[] = $key;
        });
    }

    public function test_healthy_deploy(): void
    {
        $group = (new FakeLogGroup())
            ->add('new-a', 5000, self::initStart('i-a'))
            ->add('new-a', 5100, self::initReport('i-other', 9999.0))
            ->add('new-a', 5900, self::initReport('i-a', 874.0));

        $result = $this->verify($group, steps: $steps);

        self::assertTrue($result->ok);
        self::assertSame(200, $result->httpStatus);
        self::assertSame('success', $result->initStatus);
        self::assertSame(874, $result->initDurationMs);
        self::assertSame([], $result->logTail);
        self::assertSame(['deploy.cold_start_lookup'], $steps, 'the user sees that Arco is waiting for the logs');
    }

    public function test_health_requires_a_2xx_status(): void
    {
        foreach ([301, 404] as $status) {
            $result = $this->verify(new FakeLogGroup(), [new Response($status)], attempts: 1);

            self::assertFalse($result->ok, "HTTP $status");
            self::assertSame($status, $result->httpStatus);
        }
    }

    public function test_iam_auth_skips_the_probe_and_trusts_a_successful_init_report(): void
    {
        $group = (new FakeLogGroup())->add('new-a', 5000, self::initStart('i-a'))->add('new-a', 5500, self::initReport('i-a', 500.0));

        // No HTTP responses queued: any probe would fail the test with an empty-queue error.
        $result = $this->verify($group, [], iam: true);

        self::assertTrue($result->ok);
        self::assertNull($result->httpStatus);
        self::assertSame('success', $result->initStatus);
        self::assertNull($result->reason);
    }

    public function test_iam_auth_without_an_init_report_fails_with_a_reason(): void
    {
        $result = $this->verify(new FakeLogGroup(), [], iam: true);

        self::assertFalse($result->ok);
        self::assertNull($result->initStatus);
        self::assertSame('deploy.verify_iam_no_report', $result->reason);
    }

    public function test_iam_auth_with_unreadable_logs_fails_with_a_distinct_reason_instead_of_throwing(): void
    {
        $group = new FakeLogGroup();
        $group->failWith = 'ThrottlingException';

        $result = $this->verify($group, [], iam: true);

        self::assertFalse($result->ok);
        self::assertSame('deploy.verify_iam_logs_unreadable', $result->reason);
        self::assertSame(['error' => 'ThrottlingException'], $result->reasonParams);
        self::assertCount(1, $group->queriesOf('DescribeLogStreams'), 'the lookup stops at the first CloudWatch error');
    }

    public function test_cloudwatch_errors_leave_the_cold_start_unknown_when_the_probe_passed(): void
    {
        foreach (['ThrottlingException', 'AccessDeniedException'] as $code) {
            $group = new FakeLogGroup();
            $group->failWith = $code;

            $result = $this->verify($group);

            self::assertTrue($result->ok, $code);
            self::assertNull($result->initDurationMs);
            self::assertNull($result->reason);
        }
    }

    public function test_a_log_group_that_does_not_exist_yet_is_polled_until_the_deadline(): void
    {
        $group = new FakeLogGroup();
        $group->missing = true;

        $result = $this->verify($group);

        self::assertTrue($result->ok);
        self::assertNull($result->initDurationMs);
        self::assertGreaterThanOrEqual(27, $group->clock);
        self::assertLessThanOrEqual(40, count($group->queries));
    }

    public function test_failed_init_returns_the_log_tail_of_its_stream(): void
    {
        $group = (new FakeLogGroup())
            ->add('new-a', 5000, self::initStart('i-a'))
            ->add('new-a', 5050, ['arco' => 'secrets_missing', 'missing' => ['arco-ssm:/x']])
            ->add('new-a', 5100, self::initReport('i-a', 120.0, 'failure', 'Application.InitCodeError'));

        $result = $this->verify($group, [new Response(502), new Response(502), new Response(502)], attempts: 3);

        self::assertFalse($result->ok);
        self::assertSame(502, $result->httpStatus);
        self::assertSame('failure', $result->initStatus);
        self::assertSame('Application.InitCodeError', $result->initErrorType);
        self::assertContains('{"arco":"secrets_missing","missing":["arco-ssm:\/x"]}', $result->logTail);
    }

    public function test_connection_errors_are_retried_then_reported(): void
    {
        $error = new ConnectException('Could not resolve host', new Request('GET', 'https://abc.on.aws/up'));

        $result = $this->verify(new FakeLogGroup(), [$error, $error], attempts: 2);

        self::assertFalse($result->ok);
        self::assertNull($result->httpStatus);
        self::assertStringContainsString('Could not resolve host', (string) $result->httpError);
    }

    public function test_group_tail_returns_the_last_30_across_pages(): void
    {
        $group = new FakeLogGroup(streamsPerPage: 1);
        foreach (range(1, 40) as $n) {
            $group->add($n <= 20 ? 'a' : 'b', 5000 + $n, ['line' => $n]);
        }

        $result = $this->verify($group, [new Response(500)], attempts: 1);

        self::assertCount(30, $result->logTail);
        self::assertSame('{"line":11}', $result->logTail[0]);
        self::assertSame('{"line":40}', $result->logTail[29]);
    }

    /**
     * A redeploy: the group holds 300 streams of earlier instances (150 pages of the old
     * whole-group scan), 30 of them still serving traffic. Only recent streams are searched.
     */
    public function test_cold_start_is_found_in_a_busy_log_group_beyond_50_pages_of_a_whole_group_scan(): void
    {
        $group = (new FakeLogGroup())->noise(300);
        for ($i = 0; $i < 30; $i++) {
            $group->add("a-old-$i", 4500 + $i, ['type' => 'app', 'message' => 'request']);
        }
        $group->add('z-new', 5000, self::initStart('i-a'))->add('z-new', 5900, self::initReport('i-a', 874.0));

        $result = $this->verify($group);

        self::assertSame(874, $result->initDurationMs);
        self::assertSame(0.0, $group->clock, 'found on the first attempt');
        self::assertLessThanOrEqual(20, count($group->queries));
        foreach ($group->queriesOf('FilterLogEvents') as $query) {
            self::assertArrayHasKey('logStreamNames', $query, 'no whole-group scan');
        }
        $describe = $group->queriesOf('DescribeLogStreams')[0];
        self::assertSame(['LastEventTime', true, 50], [$describe['orderBy'], $describe['descending'], $describe['limit']]);
        $filters = $group->queriesOf('FilterLogEvents');
        self::assertContains('z-new', $filters[0]['logStreamNames']);
        self::assertCount(31, $filters[0]['logStreamNames'], 'only streams written since the deploy started');
        $reports = array_values(array_filter($filters, fn (array $q) => str_contains($q['filterPattern'], 'initReport')));
        self::assertStringContainsString('$.record.instanceId = "i-a"', $reports[0]['filterPattern']);
        self::assertSame(['z-new'], $reports[0]['logStreamNames']);
    }

    public function test_init_report_that_is_ingested_late_is_still_found_within_about_30_seconds(): void
    {
        $group = (new FakeLogGroup())
            ->add('new-a', 5000, self::initStart('i-a'), visibleAfterSeconds: 6)
            ->add('new-a', 5900, self::initReport('i-a', 1210.0), visibleAfterSeconds: 20);

        $result = $this->verify($group);

        self::assertSame(1210, $result->initDurationMs);
        self::assertGreaterThanOrEqual(20, $group->clock);
        self::assertLessThanOrEqual(30, $group->clock);
    }

    public function test_a_missing_init_report_stops_after_about_30_seconds_within_the_call_budget(): void
    {
        $group = (new FakeLogGroup())->noise(300)->add('new-a', 5000, self::initStart('i-a'));

        $result = $this->verify($group);

        self::assertTrue($result->ok, 'the probe passed; only the cold start is unknown');
        self::assertNull($result->initDurationMs);
        self::assertGreaterThanOrEqual(27, $group->clock);
        self::assertLessThanOrEqual(35, $group->clock);
        self::assertLessThanOrEqual(40, count($group->queries));
    }

    public function test_slow_calls_count_against_the_wall_clock_deadline(): void
    {
        $group = (new FakeLogGroup(streamsPerPage: 2, latency: 2.0))->noise(300)->add('new-a', 5000, self::initStart('i-a'));

        $result = $this->verify($group);

        self::assertNull($result->initDurationMs);
        self::assertLessThanOrEqual(37, $group->clock, 'about 30 s, not minutes');
    }

    public function test_the_cold_start_reported_is_the_earliest_init_of_the_new_revision(): void
    {
        $group = (new FakeLogGroup())->noise(5)
            ->add('new-b', 7000, self::initStart('i-b'))
            ->add('new-b', 7300, self::initReport('i-b', 300.0))
            ->add('new-a', 5000, self::initStart('i-a'))
            ->add('new-a', 6500, self::initReport('i-a', 1500.0))
            ->add('new-c', 9000, self::initStart('i-c'))
            ->add('new-c', 9100, self::initReport('i-c', 100.0));

        self::assertSame(1500, $this->verify($group)->initDurationMs);
    }

    public function test_the_earliest_init_is_awaited_when_a_later_instance_reports_first(): void
    {
        $group = (new FakeLogGroup())
            ->add('new-a', 5000, self::initStart('i-a'))
            ->add('new-a', 6500, self::initReport('i-a', 1500.0), visibleAfterSeconds: 9)
            ->add('new-b', 7000, self::initStart('i-b'))
            ->add('new-b', 7300, self::initReport('i-b', 300.0));

        self::assertSame(1500, $this->verify($group)->initDurationMs);
    }

    public function test_a_failed_init_of_any_new_instance_fails_verification(): void
    {
        $group = (new FakeLogGroup())
            ->add('new-a', 5000, self::initStart('i-a'))
            ->add('new-a', 6500, self::initReport('i-a', 900.0))
            ->add('new-b', 7000, self::initStart('i-b'))
            ->add('new-b', 7100, self::initReport('i-b', 40.0, 'error'));

        $result = $this->verify($group);

        self::assertFalse($result->ok);
        self::assertSame('error', $result->initStatus);
    }

    public function test_instance_ids_that_could_break_the_filter_pattern_are_skipped(): void
    {
        $group = (new FakeLogGroup())
            ->add('new-a', 5000, self::initStart('i-"x'))
            ->add('new-b', 5100, self::initStart('i-b'))
            ->add('new-b', 5600, self::initReport('i-b', 640.0));

        $result = $this->verify($group);

        self::assertSame(640, $result->initDurationMs);
        foreach ($group->queriesOf('FilterLogEvents') as $query) {
            self::assertStringNotContainsString('i-"x', $query['filterPattern'] ?? '');
        }
    }
}
