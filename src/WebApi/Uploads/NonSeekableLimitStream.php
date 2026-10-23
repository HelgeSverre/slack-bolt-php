<?php

declare(strict_types=1);

namespace Thunder\Bolt\WebApi\Uploads;

use Psr\Http\Message\StreamInterface;

/** @internal Applies an explicit length to a borrowed stream that cannot seek. */
final class NonSeekableLimitStream implements StreamInterface
{
    private int $remaining;

    public function __construct(
        private StreamInterface $stream,
        private readonly int $length,
    ) {
        $this->remaining = $length;
    }

    public function __toString(): string
    {
        try {
            return $this->getContents();
        } catch (\Throwable) {
            return '';
        }
    }

    public function close(): void
    {
        $this->stream->close();
    }

    public function detach(): mixed
    {
        return $this->stream->detach();
    }

    public function getSize(): ?int
    {
        return $this->length;
    }

    public function tell(): int
    {
        return $this->stream->tell();
    }

    public function eof(): bool
    {
        return $this->remaining === 0 || $this->stream->eof();
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new \RuntimeException('The upload stream cannot seek.');
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new \RuntimeException('The upload stream is read-only.');
    }

    public function isReadable(): bool
    {
        return $this->stream->isReadable();
    }

    public function read(int $length): string
    {
        if ($length < 0) {
            throw new \RuntimeException('The upload stream read length must not be negative.');
        }
        if ($this->remaining === 0 || $length === 0) {
            return '';
        }

        $contents = $this->stream->read(min($length, $this->remaining));
        $this->remaining -= strlen($contents);

        return $contents;
    }

    public function getContents(): string
    {
        $contents = '';
        while (!$this->eof()) {
            $chunk = $this->read(min(8192, $this->remaining));
            if ($chunk === '') {
                break;
            }
            $contents .= $chunk;
        }

        return $contents;
    }

    public function getMetadata(?string $key = null): mixed
    {
        return $this->stream->getMetadata($key);
    }
}
