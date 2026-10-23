<?php

declare(strict_types=1);

namespace Thunder\Bolt\WebApi;

use Psr\Http\Message\StreamInterface;
use Thunder\Bolt\WebApi\Uploads\BytesUploadBody;
use Thunder\Bolt\WebApi\Uploads\PathUploadBody;
use Thunder\Bolt\WebApi\Uploads\StreamUploadBody;
use Thunder\Bolt\WebApi\Uploads\UploadBody;

final readonly class MultipartFile
{
    private function __construct(
        private UploadBody $body,
        public string $filename,
        public string $contentType,
        public bool $replayable,
    ) {
        if ($filename === '' || preg_match('/[\r\n]/', $filename . $contentType)) {
            throw new \InvalidArgumentException(
                'Multipart filenames and content types must be nonempty single-line values.',
            );
        }
        if ($contentType === '') {
            throw new \InvalidArgumentException('A multipart content type is required.');
        }
    }

    public static function bytes(
        string $bytes,
        string $filename,
        string $contentType = 'application/octet-stream',
    ): self {
        return new self(new BytesUploadBody($bytes), $filename, $contentType, true);
    }

    public static function path(
        string $path,
        ?string $filename = null,
        string $contentType = 'application/octet-stream',
    ): self {
        return new self(new PathUploadBody($path), $filename ?? basename($path), $contentType, true);
    }

    /** @param resource $stream */
    public static function stream(
        mixed $stream,
        int $length,
        string $filename,
        string $contentType = 'application/octet-stream',
    ): self {
        return new self(new StreamUploadBody($stream, $length), $filename, $contentType, false);
    }

    /** @internal */
    public function open(): StreamInterface
    {
        return $this->body->open();
    }
}
