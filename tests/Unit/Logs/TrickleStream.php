<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Logs;

use Psr\Http\Message\StreamInterface;

final class TrickleStream implements StreamInterface
{
    public int $delivered = 0;
    private bool $hitEnd = false;

    public function __construct(private readonly string $data, private readonly int $chunk)
    {
    }

    public function read(int $length): string
    {
        $part = substr($this->data, $this->delivered, min($length, $this->chunk));
        $this->delivered += strlen($part);
        if ($part === '') {
            $this->hitEnd = true;
        }
        return $part;
    }

    public function eof(): bool { return $this->hitEnd; }
    public function isSeekable(): bool { return false; }
    public function isReadable(): bool { return true; }
    public function isWritable(): bool { return false; }
    public function getSize(): ?int { return null; }
    public function tell(): int { return $this->delivered; }
    public function close(): void {}
    public function detach() { return null; }
    public function seek(int $offset, int $whence = SEEK_SET): void { throw new \RuntimeException('Not seekable.'); }
    public function rewind(): void { throw new \RuntimeException('Not seekable.'); }
    public function write(string $string): int { throw new \RuntimeException('Not writable.'); }
    public function getMetadata(?string $key = null) { return $key === null ? [] : null; }

    public function getContents(): string
    {
        $rest = substr($this->data, $this->delivered);
        $this->delivered = strlen($this->data);
        $this->hitEnd = true;
        return $rest;
    }

    public function __toString(): string
    {
        return $this->getContents();
    }
}
