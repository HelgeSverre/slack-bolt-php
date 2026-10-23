<?php

declare(strict_types=1);

namespace Thunder\Bolt\WebApi\Uploads;

use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

/** @internal */
final readonly class PathUploadBody implements UploadBody
{
    private int $length;

    public function __construct(
        private string $path,
    ) {
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException('An upload path must be a readable regular file.');
        }
        $length = filesize($path);
        if ($length === false) {
            throw new \InvalidArgumentException('Unable to determine the upload file size.');
        }
        $this->length = $length;
    }

    public function length(): int
    {
        return $this->length;
    }

    public function open(): StreamInterface
    {
        $resource = fopen(filename: $this->path, mode: 'rb');
        if ($resource === false) {
            throw new \RuntimeException('Unable to open the upload file.');
        }

        return Utils::streamFor($resource);
    }
}
