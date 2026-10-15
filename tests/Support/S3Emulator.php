<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Support;

use Symfony\Component\Process\Process;

/** Starts tests/Support/s3-emulator.php with `php -S`; objects and the request log live in its directory. */
final class S3Emulator
{
    public readonly string $dir;
    public readonly int $port;
    private readonly Process $server;

    public function __construct(string $bind = '127.0.0.1')
    {
        $this->dir = sys_get_temp_dir() . '/arco-s3-' . bin2hex(random_bytes(4));
        mkdir("$this->dir/objects", 0777, true);
        touch("$this->dir/requests.jsonl");
        $this->port = random_int(40000, 49999);
        $this->server = new Process([PHP_BINARY, '-S', "$bind:$this->port", __DIR__ . '/s3-emulator.php'], null, ['ARCO_S3_DIR' => $this->dir]);
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
        throw new \RuntimeException('The S3 emulator did not start: ' . $this->server->getErrorOutput());
    }

    public function put(string $bucket, string $key, string $sourcePath): void
    {
        $target = "$this->dir/objects/$bucket/$key";
        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0777, true);
        }
        copy($sourcePath, $target);
    }

    /** @return list<array{path: string, authorization: string, token: string}> */
    public function requests(): array
    {
        $lines = array_filter(explode("\n", (string) file_get_contents("$this->dir/requests.jsonl")));
        /** @var list<array{path: string, authorization: string, token: string}> */
        return array_values(array_map(fn (string $line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines));
    }

    public function stop(): void
    {
        $this->server->stop(1);
    }
}
