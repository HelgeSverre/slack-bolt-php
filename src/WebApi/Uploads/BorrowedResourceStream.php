<?php

declare(strict_types=1);

namespace Thunder\Bolt\WebApi\Uploads;

use Psr\Http\Message\StreamInterface;

/** @internal A PSR-7 view over a resource which this library must never close. */
final class BorrowedResourceStream implements StreamInterface
{
    /** @param resource $resource */
    public function __construct(
        private mixed $resource,
    ) {}

    public function __toString(): string
    {
        try {
            $position = $this->tell();
            if ($this->isSeekable()) {
                $this->rewind();
            }

            $contents = $this->getContents();
            if ($this->isSeekable()) {
                $this->seek($position);
            }

            return $contents;
        } catch (\Throwable) {
            return '';
        }
    }

    /** Intentionally a no-op: the caller retains the stream resource. */
    public function close(): void {}

    /** @return null The borrowed resource is never transferred to the transport. */
    public function detach(): mixed
    {
        return null;
    }

    public function getSize(): ?int
    {
        $stat = $this->available() ? fstat($this->resource) : false;

        return is_array($stat) ? $stat['size'] : null;
    }

    public function tell(): int
    {
        $position = $this->available() ? ftell($this->resource) : false;
        if ($position === false) {
            throw new \RuntimeException('Unable to determine the upload stream position.');
        }

        return $position;
    }

    public function eof(): bool
    {
        return !$this->available() || feof($this->resource);
    }

    public function isSeekable(): bool
    {
        $metadata = $this->metadata();

        return ($metadata['seekable'] ?? false) === true;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        if (!$this->isSeekable() || fseek($this->resource, $offset, $whence) !== 0) {
            throw new \RuntimeException('Unable to seek the upload stream.');
        }
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        $mode = (string) ($this->metadata()['mode'] ?? '');

        return strpbrk($mode, characters: 'waxc+') !== false;
    }

    public function write(string $string): int
    {
        $written = $this->available() ? fwrite($this->resource, $string) : false;
        if ($written === false) {
            throw new \RuntimeException('Unable to write to the upload stream.');
        }

        return $written;
    }

    public function isReadable(): bool
    {
        $mode = (string) ($this->metadata()['mode'] ?? '');

        return strpbrk($mode, characters: 'r+') !== false;
    }

    public function read(int $length): string
    {
        if ($length < 0) {
            throw new \RuntimeException('The upload stream read length must not be negative.');
        }
        if ($length === 0) {
            return '';
        }

        $contents = $this->available() ? fread($this->resource, $length) : false;
        if ($contents === false) {
            throw new \RuntimeException('Unable to read the upload stream.');
        }

        return $contents;
    }

    public function getContents(): string
    {
        $contents = $this->available() ? stream_get_contents($this->resource) : false;
        if ($contents === false) {
            throw new \RuntimeException('Unable to read the upload stream.');
        }

        return $contents;
    }

    public function getMetadata(?string $key = null): mixed
    {
        $metadata = $this->metadata();

        return $key === null ? $metadata : $metadata[$key] ?? null;
    }

    private function available(): bool
    {
        return is_resource($this->resource) && get_resource_type($this->resource) === 'stream';
    }

    /** @return array<string, mixed> */
    private function metadata(): array
    {
        return $this->available() ? stream_get_meta_data($this->resource) : [];
    }
}
