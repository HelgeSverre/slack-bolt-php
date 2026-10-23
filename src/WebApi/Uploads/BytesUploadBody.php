<?php

declare(strict_types=1);

namespace Thunder\Bolt\WebApi\Uploads;

use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

/** @internal */
final readonly class BytesUploadBody implements UploadBody
{
    public function __construct(
        private string $bytes,
    ) {}

    public function length(): int
    {
        return strlen($this->bytes);
    }

    public function open(): StreamInterface
    {
        return Utils::streamFor($this->bytes);
    }
}
