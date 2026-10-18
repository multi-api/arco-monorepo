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
        $this->dir = TempPaths::path('arco-s3-');
        mkdir("$this->dir/objects", 0777, true);
        touch("$this->dir/requests.jsonl");
        // A random port may be taken by another process: retry with a new one when the server dies or never answers.
        $lastError = '';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $port = random_int(40000, 49999);
            $server = new Process([PHP_BINARY, '-S', "$bind:$port", __DIR__ . '/s3-emulator.php'], null, ['ARCO_S3_DIR' => $this->dir]);
            $server->start();
            $deadline = microtime(true) + 5;
            while ($server->isRunning() && microtime(true) < $deadline) {
                $socket = @fsockopen('127.0.0.1', $port);
                if (is_resource($socket)) {
                    fclose($socket);
                    if (! $server->isRunning()) {
                        break; // the answer came from another process that owns the port
                    }
                    $this->port = $port;
                    $this->server = $server;
                    return;
                }
                usleep(50_000);
            }
            $lastError = $server->getErrorOutput();
            $server->stop(1);
        }
        throw new \RuntimeException('The S3 emulator did not start after 5 attempts: ' . $lastError);
    }

    public function put(string $bucket, string $key, string $sourcePath): void
    {
        $target = "$this->dir/objects/$bucket/$key";
        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0777, true);
        }
        copy($sourcePath, $target);
    }

    /** @return list<array{path: string, query: string, authorization: string, token: string}> */
    public function requests(): array
    {
        $lines = array_filter(explode("\n", (string) file_get_contents("$this->dir/requests.jsonl")));
        /** @var list<array{path: string, query: string, authorization: string, token: string}> */
        return array_values(array_map(fn (string $line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines));
    }

    public function stop(): void
    {
        $this->server->stop(1);
    }
}
