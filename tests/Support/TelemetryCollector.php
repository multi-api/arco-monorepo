<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Support;

/** A UDP socket on 127.0.0.1 standing in for Arco's telemetry collector: ARCO_TELEMETRY_HOST=$collector->host. */
final class TelemetryCollector
{
    public readonly string $host;
    /** @var resource */
    private $socket;

    public function __construct()
    {
        $socket = stream_socket_server('udp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND);
        if ($socket === false) {
            throw new \RuntimeException("Cannot listen on UDP: $error");
        }
        $this->socket = $socket;
        $this->host = (string) stream_socket_get_name($socket, false);
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

    public function close(): void
    {
        fclose($this->socket);
    }
}
