<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\ConsoleRuntime;

use Multiapi\Arco\ConsoleRuntime\Telemetry;
use Multiapi\Arco\Tests\Support\TelemetryCollector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TelemetryTest extends TestCase
{
    private const ENV = ['AWS_REGION' => 'us-east-1'];
    private const CONFIG = ['arcoVersion' => '0.21.0'];

    /** @var list<string> */
    private array $sent = [];
    private float $clock = 1000.0;

    /**
     * @param array<string, mixed> $env
     * @param array<string, mixed> $config
     */
    private function telemetry(array $env = self::ENV, array $config = self::CONFIG, string $runtime = 'laravel', float $bootMs = 120.0): Telemetry
    {
        return Telemetry::fromRuntime(
            $env,
            $config,
            $runtime,
            $bootMs,
            function (string $packet): void { $this->sent[] = $packet; },
            fn (): float => $this->clock,
        );
    }

    public function test_encode_matches_the_packets_shared_with_the_node_runtime(): void
    {
        $fixture = json_decode((string) file_get_contents(__DIR__ . '/../../Fixtures/telemetry-packets.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($fixture);
        self::assertIsArray($fixture['packets']);
        self::assertNotEmpty($fixture['packets']);
        foreach ($fixture['packets'] as $case) {
            /** @var array{fields: array{runtime: string, kind: string, version: string, region: string, invocations: int, cold: bool, boot: int}, packet: string} $case */
            self::assertSame($case['packet'], Telemetry::encode($case['fields']));
        }
    }

    public function test_the_first_tick_sends_a_cold_start_then_at_most_once_a_minute(): void
    {
        $telemetry = $this->telemetry(bootMs: 1500);

        $telemetry->count('console');
        $telemetry->tick();
        self::assertSame(['arco1 r=laravel k=console v=0.21.0 g=us-east-1 i=1 c=1 b=2'], $this->sent);

        $telemetry->count('console');
        $this->clock += 30;
        $telemetry->count('console');
        $telemetry->tick();
        self::assertCount(1, $this->sent, 'nothing before a minute has passed');

        $this->clock += 30;
        $telemetry->count('console');
        $telemetry->tick();
        self::assertSame('arco1 r=laravel k=console v=0.21.0 g=us-east-1 i=3 c=0', $this->sent[1]);

        $this->clock += 120;
        $telemetry->tick();
        self::assertCount(2, $this->sent, 'nothing is sent with nothing counted');
    }

    public function test_one_packet_per_kind_and_only_one_cold_start(): void
    {
        $telemetry = $this->telemetry();

        $telemetry->count('schedule');
        $telemetry->count('console');
        $telemetry->count('console');
        $telemetry->flush();
        $telemetry->count('schedule');
        $telemetry->flush();

        self::assertSame([
            'arco1 r=laravel k=schedule v=0.21.0 g=us-east-1 i=1 c=1 b=0',
            'arco1 r=laravel k=console v=0.21.0 g=us-east-1 i=2 c=0',
            'arco1 r=laravel k=schedule v=0.21.0 g=us-east-1 i=1 c=0',
        ], $this->sent);
    }

    public function test_an_unknown_kind_is_ignored(): void
    {
        $telemetry = $this->telemetry();

        $telemetry->count('cron');
        $telemetry->tick();
        $telemetry->flush();

        self::assertSame([], $this->sent);
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>, string}> */
    public static function disabled(): iterable
    {
        yield 'ARCO_TELEMETRY_DISABLE=1' => [[...self::ENV, 'ARCO_TELEMETRY_DISABLE' => '1'], self::CONFIG, 'laravel'];
        yield 'telemetry: false' => [self::ENV, [...self::CONFIG, 'telemetry' => false], 'laravel'];
        yield 'no arcoVersion' => [self::ENV, [], 'laravel'];
        yield 'odd arcoVersion' => [self::ENV, ['arcoVersion' => '0.21 0'], 'laravel'];
        yield 'no AWS_REGION' => [[], self::CONFIG, 'laravel'];
        yield 'odd AWS_REGION' => [['AWS_REGION' => 'US EAST'], self::CONFIG, 'laravel'];
        yield 'unknown runtime' => [self::ENV, self::CONFIG, 'node'];
    }

    /**
     * @param array<string, mixed> $env
     * @param array<string, mixed> $config
     */
    #[DataProvider('disabled')]
    public function test_off_counts_and_sends_nothing(array $env, array $config, string $runtime): void
    {
        $telemetry = $this->telemetry($env, $config, $runtime);

        $telemetry->count('console');
        $telemetry->tick();
        $telemetry->flush();

        self::assertFalse($telemetry->enabled());
        self::assertSame([], $this->sent);
    }

    public function test_other_values_keep_it_on(): void
    {
        $telemetry = $this->telemetry([...self::ENV, 'ARCO_TELEMETRY_DISABLE' => '0'], [...self::CONFIG, 'telemetry' => true], 'process');

        $telemetry->count('queue');
        $telemetry->tick();

        self::assertSame(['arco1 r=process k=queue v=0.21.0 g=us-east-1 i=1 c=1 b=0'], $this->sent);
    }

    public function test_a_failing_send_turns_it_off_without_throwing(): void
    {
        $calls = 0;
        $telemetry = Telemetry::fromRuntime(self::ENV, self::CONFIG, 'laravel', 0, static function () use (&$calls): void {
            $calls++;
            throw new \RuntimeException('unreachable');
        });

        $telemetry->count('console');
        $telemetry->tick();
        $telemetry->count('console');
        $telemetry->flush();

        self::assertSame(1, $calls);
        self::assertFalse($telemetry->enabled());
    }

    public function test_boot_buckets(): void
    {
        self::assertSame([0, 0, 1, 1, 2, 2, 3, 3], array_map(
            Telemetry::bootBucket(...),
            [0.0, 499.9, 500.0, 999.9, 1000.0, 2999.9, 3000.0, 60_000.0],
        ));
        self::assertSame(0, Telemetry::bootBucket(NAN));
    }

    public function test_sends_udp_datagrams_to_arco_telemetry_host(): void
    {
        $collector = new TelemetryCollector();
        try {
            $telemetry = Telemetry::fromRuntime([...self::ENV, 'ARCO_TELEMETRY_HOST' => $collector->host], self::CONFIG, 'laravel', 700);

            $telemetry->count('queue');
            $telemetry->tick();
            $telemetry->count('queue');
            $telemetry->count('queue');
            $telemetry->flush();

            self::assertSame('arco1 r=laravel k=queue v=0.21.0 g=us-east-1 i=1 c=1 b=1', $collector->receive());
            self::assertSame('arco1 r=laravel k=queue v=0.21.0 g=us-east-1 i=2 c=0', $collector->receive());
        } finally {
            $collector->close();
        }
    }

    public function test_an_unreachable_collector_never_throws(): void
    {
        // Nothing listens there: the second datagram meets the first one's ICMP port unreachable.
        $closed = new TelemetryCollector();
        $host = $closed->host;
        $closed->close();
        $telemetry = Telemetry::fromRuntime([...self::ENV, 'ARCO_TELEMETRY_HOST' => $host], self::CONFIG, 'laravel', 0);

        for ($i = 0; $i < 3; $i++) {
            $telemetry->count('console');
            $telemetry->flush();
        }

        self::assertTrue($telemetry->enabled(), 'a lost datagram is a lost count, not an error');
    }

    public function test_a_host_that_does_not_resolve_turns_it_off(): void
    {
        // Longer than a host name can be, so PHP fails it without asking a DNS server (none in the test container).
        $telemetry = Telemetry::fromRuntime([...self::ENV, 'ARCO_TELEMETRY_HOST' => str_repeat('a', 300) . '.invalid:41700'], self::CONFIG, 'laravel', 0);

        $telemetry->count('console');
        $telemetry->tick();

        self::assertFalse($telemetry->enabled());
    }
}
