<?php

declare(strict_types=1);

namespace Thunder\Bolt\WebApi\Uploads;

use GuzzleHttp\Psr7\LimitStream;
use Psr\Http\Message\StreamInterface;

/** @internal Caller-owned, single-use stream body. */
final readonly class StreamUploadBody implements UploadBody
{
    /** @param resource $resource */
    public function __construct(
        private mixed $resource,
        private int $length,
    ) {
        if (!is_resource($resource) || get_resource_type($resource) !== 'stream') {
            throw new \InvalidArgumentException('An upload stream must be a PHP stream resource.');
        }
        if ($length < 0) {
            throw new \InvalidArgumentException('An upload stream length must not be negative.');
        }
    }

    public function length(): int
    {
        return $this->length;
    }

    public function open(): StreamInterface
    {
        $stream = new BorrowedResourceStream($this->resource);
        if (!$stream->isSeekable()) {
            return new NonSeekableLimitStream($stream, $this->length);
        }

        return new LimitStream($stream, $this->length, $stream->tell());
    }
}
