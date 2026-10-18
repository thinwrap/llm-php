<?php

declare(strict_types=1);

namespace Thinwrap\Llm\Tests\Support;

use Psr\Http\Message\StreamInterface;

/** Delivers its content a few bytes per read, so events straddle chunk boundaries. */
final class ChunkedStream implements StreamInterface
{
    private int $pos = 0;

    public function __construct(private readonly string $content, private readonly int $chunk) {}

    public function __toString(): string
    {
        return $this->content;
    }

    public function close(): void {}

    public function detach()
    {
        return null;
    }

    public function getSize(): ?int
    {
        return strlen($this->content);
    }

    public function tell(): int
    {
        return $this->pos;
    }

    public function eof(): bool
    {
        return $this->pos >= strlen($this->content);
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new \RuntimeException('not seekable');
    }

    public function rewind(): void
    {
        throw new \RuntimeException('not seekable');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new \RuntimeException('not writable');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        $out = substr($this->content, $this->pos, min($length, $this->chunk));
        $this->pos += strlen($out);

        return $out;
    }

    public function getContents(): string
    {
        $out = substr($this->content, $this->pos);
        $this->pos = strlen($this->content);

        return $out;
    }

    public function getMetadata(?string $key = null)
    {
        return null;
    }
}
