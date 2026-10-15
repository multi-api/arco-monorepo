<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Support;

use Symfony\Component\Process\Process;

/** Starts tests/Support/runtime-api-emulator.php with `php -S` and talks to it through its state directory. */
final class RuntimeApiEmulator
{
    public readonly string $dir;
    public readonly int $port;
    private readonly Process $server;
    private int $sequence = 0;

    public function __construct(string $bind = '127.0.0.1')
    {
        $this->dir = sys_get_temp_dir() . '/arco-emulator-' . bin2hex(random_bytes(4));
        foreach (['queue', 'responses', 'errors'] as $folder) {
            mkdir("$this->dir/$folder", 0777, true);
        }
        $this->port = random_int(30000, 39999);
        // Several workers: a runtime parked on /next must never block another request (posting a result, an init error).
        $this->server = new Process([PHP_BINARY, '-S', "$bind:$this->port", __DIR__ . '/runtime-api-emulator.php'], null, [
            'ARCO_EMULATOR_DIR' => $this->dir,
            'PHP_CLI_SERVER_WORKERS' => '4',
        ]);
        $this->server->start();
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', $this->port);
            if (is_resource($socket)) {
                fclose($socket);
                return;
            }
            usleep(50_000);
        }
        throw new \RuntimeException('The Runtime API emulator did not start: ' . $this->server->getErrorOutput());
    }

    /** @param array<string, mixed> $event */
    public function enqueue(array $event): string
    {
        return $this->enqueueRaw((string) json_encode($event, JSON_UNESCAPED_SLASHES));
    }

    public function enqueueRaw(string $body): string
    {
        $id = sprintf('req-%04d', ++$this->sequence);
        file_put_contents("$this->dir/queue/$id.tmp", $body);
        rename("$this->dir/queue/$id.tmp", "$this->dir/queue/$id.json");
        return $id;
    }

    /** @return array{type: string, errorType: ?string, body: string} */
    public function await(string $id, float $timeout = 30): array
    {
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            foreach (['responses' => 'response', 'errors' => 'error'] as $folder => $type) {
                $file = "$this->dir/$folder/$id.json";
                if (is_file($file)) {
                    /** @var array{type: ?string, body: string} $data */
                    $data = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
                    return ['type' => $type, 'errorType' => $data['type'], 'body' => $data['body']];
                }
            }
            usleep(50_000);
        }
        throw new \RuntimeException("No answer for $id within $timeout s.");
    }

    /** @return array{type: ?string, body: string}|null */
    public function initError(): ?array
    {
        $file = "$this->dir/init-error.json";
        /** @var array{type: ?string, body: string}|null */
        return is_file($file) ? json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR) : null;
    }

    public function stop(): void
    {
        touch("$this->dir/stop");
        // The workers outlive a stopped `php -S` master (neither SIGTERM nor SIGINT reaches them): end them first.
        $pid = $this->server->getPid();
        if ($pid !== null) {
            (new Process(['pkill', '-TERM', '-P', (string) $pid]))->run();
        }
        $this->server->stop(1);
    }
}
