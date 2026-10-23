<?php

declare(strict_types=1);

namespace Thunder\Bolt\WebApi;

use GuzzleHttp\Psr7\MultipartStream;

/** @internal Keeps binary bodies streamed and recreates replayable sources for retries. */
final readonly class MultipartForm
{
    public string $boundary;
    public bool $replayable;

    public function __construct(
        private array $arguments,
    ) {
        $this->boundary = bin2hex(random_bytes(16));
        $replayable = true;
        foreach ($arguments as $name => $value) {
            if ((string) $name === '' || preg_match('/[\r\n"\\\\]/', (string) $name)) {
                throw new \InvalidArgumentException(
                    'Multipart field names must be nonempty and must not contain header delimiters.',
                );
            }
            if ($value instanceof MultipartFile && !$value->replayable) {
                $replayable = false;
            }
        }
        $this->replayable = $replayable;
    }

    public function stream(): MultipartStream
    {
        $parts = [];
        foreach ($this->arguments as $name => $value) {
            if ($value instanceof MultipartFile) {
                $parts[] = [
                    'name' => (string) $name,
                    'contents' => $value->open(),
                    'filename' => $value->filename,
                    'headers' => [
                        'Content-Type' => $value->contentType,
                        'Content-Disposition' =>
                            'form-data; name="'
                                . $name
                                . '"; filename="'
                                . addcslashes(basename($value->filename), '\\"')
                                . '"',
                    ],
                ];
            } else {
                if (is_resource($value)) {
                    throw new \InvalidArgumentException('Wrap binary fields in MultipartFile.');
                }
                $parts[] = [
                    'name' => (string) $name,
                    'contents' => is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR),
                ];
            }
        }
        return new MultipartStream($parts, $this->boundary);
    }
}
