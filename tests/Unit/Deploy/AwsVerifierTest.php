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
    /** 2026-10-15 16:26:20 UTC: the deploy starts. */
    private const T0 = 1792081580;
    private const MIDNIGHT = 1792108800; // 2026-10-16 00:00:00 UTC

    /** @var list<array{0: string, 1: array<string, string|int>}> */
    private array $steps = [];

    private function config(bool $iam = false): AppConfig
    {
        $app = App::make('shop')->region('us-east-1');
        return ConfigResolver::resolve(($iam ? $app->auth('iam') : $app)->environment('production')->toArray(), 'production');
    }

    /** @param list<Response|ConnectException> $responses */
    private function verifier(FakeLogGroup $group, array $responses = [new Response(200)], int $attempts = 5): AwsVerifier
    {
        return new AwsVerifier($group->client(), new Client(['handler' => HandlerStack::create(new HttpMock($responses))]), $group, attempts: $attempts);
    }

    private function deployResult(): DeployResult
    {
        return new DeployResult('abc.on.aws', 'rev-2', 'rev-1', 'bucket', 'key');
    }

    private function onStep(): \Closure
    {
        return function (string $key, array $params = []): void {
            $this->steps[] = [$key, $params];
        };
    }

    /** Verifies at $verifyAt, spends $domainsSeconds on custom domains, then reads the cold start. */
    private function deploy(FakeLogGroup $group, int $since = self::T0, int $domainsSeconds = 0): VerificationResult
    {
        $verifier = $this->verifier($group);
        $verification = $verifier->verify($this->config(), $this->deployResult(), '/up', $since * 1000, $this->onStep());
        $group->sleep($domainsSeconds);
        return $verifier->coldStart($this->config(), $this->deployResult(), $since * 1000, $verification, $this->onStep());
    }

    /** The stream name format of Lambda Web Functions. */
    private static function stream(string $hex, string $revision = 'rev-2', string $date = '2026/10/15'): string
    {
        return "$date/shop-production[$revision]$hex-111111111111-us-east-1";
    }

    private static function initStart(string $instance, string $revision = 'rev-2'): array
    {
        return ['type' => 'platform.initStart', 'record' => ['revisionId' => $revision, 'instanceId' => $instance]];
    }

    private static function initReport(string $instance, float $ms, string $status = 'success', ?string $errorType = null): array
    {
        return ['type' => 'platform.initReport', 'record' => ['status' => $status, 'instanceId' => $instance, 'metrics' => ['totalDurationMs' => $ms]] + ($errorType === null ? [] : ['errorType' => $errorType])];
    }

    /** One new instance whose init started at T0+10 s and whose events arrive $lag seconds late. */
    private static function newInstance(FakeLogGroup $group, string $hex, int $atMs, float $ms, float $lag, string $date = '2026/10/15', string $status = 'success'): FakeLogGroup
    {
        return $group
            ->add(self::stream($hex, date: $date), $atMs, self::initStart("i-$hex"), $lag)
            ->add(self::stream($hex, date: $date), $atMs + (int) $ms, self::initReport("i-$hex", $ms, $status, $status === 'success' ? null : 'Runtime.ExitError'), $lag);
    }

    public function test_a_public_endpoint_is_verified_by_the_probe_alone(): void
    {
        $group = new FakeLogGroup(self::T0 + 10);

        $verification = $this->verifier($group)->verify($this->config(), $this->deployResult(), '/up', self::T0 * 1000, $this->onStep());

        self::assertTrue($verification->ok);
        self::assertSame(200, $verification->httpStatus);
        self::assertNull($verification->initDurationMs);
        self::assertSame([], $group->queries, 'verification does not wait for CloudWatch');
        self::assertSame([], $this->steps);
    }

    /** The rev-0zdki redeploy: the first initStart was ingested 36 s after it happened. */
    public function test_the_cold_start_is_found_despite_36_s_of_ingestion_lag(): void
    {
        $group = self::newInstance(new FakeLogGroup(self::T0 + 10), 'aaa', (self::T0 + 10) * 1000 + 176, 1234.0, 36);
        $group->add(self::stream('old', 'rev-1'), (self::T0 + 12) * 1000, self::initReport('i-old', 50.0));

        $result = $this->deploy($group, domainsSeconds: 20);

        self::assertSame(1234, $result->initDurationMs);
        self::assertSame('success', $result->initStatus);
        self::assertTrue($result->ok);
        self::assertSame([['deploy.cold_start_lookup', ['seconds' => 60]]], $this->steps, 'the lookup has its own budget');
        foreach ($group->queriesOf('FilterLogEvents') as $query) {
            self::assertSame('2026/10/15/shop-production[rev-2]', $query['logStreamNamePrefix']);
            self::assertStringContainsString('platform.initReport', $query['filterPattern']);
        }
        self::assertSame([], $group->queriesOf('DescribeLogStreams'));
    }

    public function test_the_lookup_ends_60_s_after_it_started_within_the_call_budget(): void
    {
        $group = self::newInstance(new FakeLogGroup(self::T0 + 10), 'aaa', (self::T0 + 10) * 1000, 900.0, 500);

        $result = $this->deploy($group, domainsSeconds: 50);

        self::assertTrue($result->ok);
        self::assertNull($result->initDurationMs);
        self::assertLessThanOrEqual(self::T0 + 10 + 50 + 60 + 5, $group->clock);
        self::assertGreaterThanOrEqual(self::T0 + 10 + 50 + 56, $group->clock);
        self::assertLessThanOrEqual(40, count($group->queries));
    }

    /** Live test: a slow CloudFront update during domain attachment left "up to 0 s" and no lookup at all. */
    public function test_a_slow_domain_attachment_does_not_eat_the_cold_start_budget(): void
    {
        $group = self::newInstance(new FakeLogGroup(self::T0 + 10), 'aaa', (self::T0 + 10) * 1000 + 176, 1234.0, 36);

        $result = $this->deploy($group, domainsSeconds: 300);

        self::assertSame(1234, $result->initDurationMs);
        self::assertSame([['deploy.cold_start_lookup', ['seconds' => 60]]], $this->steps);
    }

    public function test_slow_calls_and_a_busy_group_stay_within_the_deadline_and_budget(): void
    {
        $group = new FakeLogGroup(self::T0 + 10, streamsPerPage: 1, latency: 1.5);
        for ($i = 0; $i < 30; $i++) {
            self::newInstance($group, "n$i", (self::T0 + 10) * 1000, 900.0, 500);
        }

        $result = $this->deploy($group);

        self::assertNull($result->initDurationMs);
        self::assertLessThanOrEqual(self::T0 + 10 + 60 + 5 + 1.5, $group->clock);
        self::assertLessThanOrEqual(40, count($group->queries));
    }

    public function test_the_cold_start_is_the_earliest_init_even_when_a_later_one_is_ingested_first(): void
    {
        $group = new FakeLogGroup(self::T0 + 10);
        self::newInstance($group, 'early', (self::T0 + 10) * 1000, 1500.0, 30);
        self::newInstance($group, 'late', (self::T0 + 14) * 1000, 300.0, 20);

        self::assertSame(1500, $this->deploy($group)->initDurationMs);
    }

    public function test_a_deploy_across_midnight_also_searches_the_previous_days_streams(): void
    {
        $since = self::MIDNIGHT - 10;
        $group = self::newInstance(new FakeLogGroup(self::MIDNIGHT + 5), 'aaa', ($since + 8) * 1000, 700.0, 25, date: '2026/10/15');

        $result = $this->deploy($group, since: $since);

        self::assertSame(700, $result->initDurationMs);
        $prefixes = array_unique(array_column($group->queriesOf('FilterLogEvents'), 'logStreamNamePrefix'));
        sort($prefixes);
        self::assertSame(['2026/10/15/shop-production[rev-2]', '2026/10/16/shop-production[rev-2]'], $prefixes);
    }

    public function test_a_deploy_started_just_after_midnight_also_searches_the_previous_day(): void
    {
        $since = self::MIDNIGHT + 120;
        $group = self::newInstance(new FakeLogGroup($since + 10), 'aaa', ($since + 10) * 1000, 700.0, 5, date: '2026/10/16');

        $result = $this->deploy($group, since: $since);

        self::assertSame(700, $result->initDurationMs);
        $prefixes = array_unique(array_column($group->queriesOf('FilterLogEvents'), 'logStreamNamePrefix'));
        sort($prefixes);
        self::assertSame(['2026/10/15/shop-production[rev-2]', '2026/10/16/shop-production[rev-2]'], $prefixes);
    }

    public function test_a_daytime_deploy_searches_only_todays_streams(): void
    {
        $group = self::newInstance(new FakeLogGroup(self::T0 + 10), 'aaa', (self::T0 + 10) * 1000, 700.0, 0);

        $this->deploy($group);

        self::assertSame(['2026/10/15/shop-production[rev-2]'], array_values(array_unique(array_column($group->queriesOf('FilterLogEvents'), 'logStreamNamePrefix'))));
    }

    public function test_cloudwatch_errors_leave_the_cold_start_unknown(): void
    {
        foreach (['ThrottlingException', 'AccessDeniedException'] as $code) {
            $group = new FakeLogGroup(self::T0 + 10);
            $group->failWith = $code;

            $result = $this->deploy($group);

            self::assertTrue($result->ok, $code);
            self::assertNull($result->initDurationMs);
            self::assertCount(1, $group->queries, 'the lookup stops at the first error');
        }
    }

    public function test_a_log_group_that_does_not_exist_yet_is_polled_until_the_deadline(): void
    {
        $group = new FakeLogGroup(self::T0 + 10);
        $group->missing = true;

        $result = $this->deploy($group);

        self::assertNull($result->initDurationMs);
        self::assertGreaterThanOrEqual(self::T0 + 10 + 56, $group->clock);
    }

    public function test_iam_auth_waits_up_to_75_s_for_the_report_before_deciding(): void
    {
        $group = self::newInstance(new FakeLogGroup(self::T0 + 10), 'aaa', (self::T0 + 10) * 1000, 800.0, 65);

        $result = $this->verifier($group, [])->verify($this->config(iam: true), $this->deployResult(), '/up', self::T0 * 1000, $this->onStep());

        self::assertTrue($result->ok);
        self::assertNull($result->httpStatus);
        self::assertSame(800, $result->initDurationMs);
        self::assertSame([['deploy.cold_start_lookup', ['seconds' => 75]]], $this->steps);
    }

    public function test_iam_auth_without_an_init_report_fails_after_about_75_s(): void
    {
        $group = new FakeLogGroup(self::T0 + 10);

        $result = $this->verifier($group, [])->verify($this->config(iam: true), $this->deployResult(), '/up', self::T0 * 1000, $this->onStep());

        self::assertFalse($result->ok);
        self::assertSame('deploy.verify_iam_no_report', $result->reason);
        self::assertGreaterThanOrEqual(self::T0 + 10 + 71, $group->clock);
        self::assertLessThanOrEqual(self::T0 + 10 + 80, $group->clock);
        self::assertLessThanOrEqual(40, count($group->queries));
    }

    public function test_iam_auth_with_unreadable_logs_fails_with_a_distinct_reason(): void
    {
        $group = new FakeLogGroup(self::T0 + 10);
        $group->failWith = 'ThrottlingException';

        $result = $this->verifier($group, [])->verify($this->config(iam: true), $this->deployResult(), '/up', self::T0 * 1000, $this->onStep());

        self::assertFalse($result->ok);
        self::assertSame('deploy.verify_iam_logs_unreadable', $result->reason);
        self::assertSame(['error' => 'ThrottlingException'], $result->reasonParams);
    }

    public function test_iam_auth_fails_when_any_new_instance_failed_its_init(): void
    {
        $group = new FakeLogGroup(self::T0 + 10);
        self::newInstance($group, 'aaa', (self::T0 + 10) * 1000, 900.0, 0);
        self::newInstance($group, 'bbb', (self::T0 + 11) * 1000, 40.0, 0, status: 'error');

        $result = $this->verifier($group, [])->verify($this->config(iam: true), $this->deployResult(), '/up', self::T0 * 1000);

        self::assertFalse($result->ok);
        self::assertSame('error', $result->initStatus);
    }

    public function test_a_failed_probe_reads_the_init_error_and_log_tail_without_waiting(): void
    {
        $group = new FakeLogGroup(self::T0 + 10);
        $group->add(self::stream('aaa'), (self::T0 + 9) * 1000, ['arco' => 'secrets_missing', 'missing' => ['arco-ssm:/x']]);
        self::newInstance($group, 'aaa', (self::T0 + 9) * 1000, 120.0, 0, status: 'failure');

        $result = $this->verifier($group, [new Response(502), new Response(502), new Response(502)], attempts: 3)
            ->verify($this->config(), $this->deployResult(), '/up', self::T0 * 1000);

        self::assertFalse($result->ok);
        self::assertSame(502, $result->httpStatus);
        self::assertSame('failure', $result->initStatus);
        self::assertSame('Runtime.ExitError', $result->initErrorType);
        self::assertContains('{"arco":"secrets_missing","missing":["arco-ssm:\/x"]}', $result->logTail);
        self::assertSame((float) self::T0 + 10 + 6, $group->clock, 'only the probe retries took time');
    }

    public function test_connection_errors_are_retried_then_reported(): void
    {
        $error = new ConnectException('Could not resolve host', new Request('GET', 'https://abc.on.aws/up'));

        $result = $this->verifier(new FakeLogGroup(self::T0 + 10), [$error, $error], attempts: 2)
            ->verify($this->config(), $this->deployResult(), '/up', self::T0 * 1000);

        self::assertFalse($result->ok);
        self::assertNull($result->httpStatus);
        self::assertStringContainsString('Could not resolve host', (string) $result->httpError);
    }

    public function test_group_tail_returns_the_last_30_across_pages(): void
    {
        $group = new FakeLogGroup(self::T0 + 100, streamsPerPage: 1);
        foreach (range(1, 40) as $n) {
            $group->add($n <= 20 ? 'a' : 'b', (self::T0 + $n) * 1000, ['line' => $n]);
        }

        $result = $this->verifier($group, [new Response(500)], attempts: 1)->verify($this->config(), $this->deployResult(), '/up', self::T0 * 1000);

        self::assertCount(30, $result->logTail);
        self::assertSame('{"line":11}', $result->logTail[0]);
        self::assertSame('{"line":40}', $result->logTail[29]);
    }

    public function test_the_cold_start_is_not_looked_up_again_when_verification_already_has_it(): void
    {
        $group = new FakeLogGroup(self::T0 + 10);
        $known = new VerificationResult(true, null, null, 'success', null, 640, []);

        $result = $this->verifier($group)->coldStart($this->config(iam: true), $this->deployResult(), self::T0 * 1000, $known, $this->onStep());

        self::assertSame($known, $result);
        self::assertSame([], $group->queries);
        self::assertSame([], $this->steps);
    }
}
