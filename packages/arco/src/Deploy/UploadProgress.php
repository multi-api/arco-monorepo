<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Aws\CommandInterface;
use Closure;

/**
 * Bytes sent of one S3 upload, single PutObject or multipart, from Guzzle's `progress` request option.
 * Each request reports its own uploaded bytes, so they are kept per part (key 0 for a PutObject) and
 * summed. A part sent again (an SDK retry, or CodeBucket's own) restarts from 0: the highest value
 * seen per part is kept, so the total never goes backwards and pauses until the new attempt catches up.
 */
final class UploadProgress
{
    /** @var array<int, int> uploaded bytes by part number */
    private array $parts = [];
    private int $reported = 0;

    /** @param Closure(int, int): void $onProgress receives (bytes sent, file size) */
    public function __construct(private readonly int $total, private readonly Closure $onProgress)
    {
    }

    /** The `before_upload` hook of ObjectUploader and MultipartUploader: wires a PutObject or UploadPart command. */
    public function attach(CommandInterface $command): void
    {
        $part = is_numeric($command['PartNumber'] ?? null) ? (int) $command['PartNumber'] : 0;
        $http = is_array($command['@http'] ?? null) ? $command['@http'] : [];
        $http['progress'] = function (mixed $downloadTotal, mixed $downloaded, mixed $uploadTotal, mixed $uploaded) use ($part): void {
            $this->update($part, is_numeric($uploaded) ? (int) $uploaded : 0);
        };
        $command['@http'] = $http;
    }

    /** After a successful upload: the size, when the SDK did not report the last chunk. */
    public function finish(): void
    {
        if ($this->reported < $this->total) {
            $this->report($this->total);
        }
    }

    private function update(int $part, int $uploaded): void
    {
        $this->parts[$part] = max($this->parts[$part] ?? 0, $uploaded);
        $this->report(min(array_sum($this->parts), $this->total));
    }

    private function report(int $sent): void
    {
        if ($sent <= $this->reported) {
            return;
        }
        $this->reported = $sent;
        ($this->onProgress)($sent, $this->total);
    }
}
