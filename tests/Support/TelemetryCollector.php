<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Support;

/**
 * A UDP socket standing in for Arco's telemetry collector: ARCO_TELEMETRY_HOST=$collector->host on this machine. The
 * integration tests bind it to SiblingContainers::BIND and hand a Lambda container forContainers() instead.
 */
final class TelemetryCollector
{
    public readonly string $host;
    public readonly int $port;
    /** @var resource */
    private $socket;

    public function __construct(string $bind = '127.0.0.1')
    {
        $socket = stream_socket_server("udp://$bind:0", $errno, $error, STREAM_SERVER_BIND);
        if ($socket === false) {
            throw new \RuntimeException("Cannot listen on UDP: $error");
        }
        $this->socket = $socket;
        $name = (string) stream_socket_get_name($socket, false);
        $this->port = (int) substr($name, (int) strrpos($name, ':') + 1);
        $this->host = "127.0.0.1:$this->port";
    }

    /** ARCO_TELEMETRY_HOST for a container started by an integration test (SiblingContainers). */
    public function forContainers(): string
    {
        return SiblingContainers::testHost() . ":$this->port";
    }

    /** The next packet, or null when none arrives within $seconds. */
    public function receive(float $seconds = 10.0): ?string
    {
        $read = [$this->socket];
        $write = $except = null;
        $ready = stream_select($read, $write, $except, (int) $seconds, (int) (fmod($seconds, 1) * 1e6));
        if ($ready !== 1) {
            return null;
        }
        $packet = stream_socket_recvfrom($this->socket, 1500);
        return $packet === false ? null : $packet;
    }

    /** Every packet that arrives until none has for $quiet seconds. @return list<string> */
    public function drain(float $quiet = 1.0): array
    {
        $packets = [];
        while (($packet = $this->receive($quiet)) !== null) {
            $packets[] = $packet;
        }
        return $packets;
    }

    public function close(): void
    {
        fclose($this->socket);
    }
}
