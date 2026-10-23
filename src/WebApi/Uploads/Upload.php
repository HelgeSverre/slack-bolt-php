<?php

declare(strict_types=1);

namespace Thunder\Bolt\WebApi\Uploads;

/** One source file plus its optional Slack presentation metadata. */
final readonly class Upload
{
    private function __construct(
        private UploadBody $body,
        public string $filename,
        public UploadMetadata $metadata,
    ) {
        if ($filename === '') {
            throw new \InvalidArgumentException('An upload filename is required.');
        }
    }

    #[\NoDiscard]
    public static function bytes(string $bytes, string $filename): self
    {
        return new self(new BytesUploadBody($bytes), $filename, UploadMetadata::make());
    }

    #[\NoDiscard]
    public static function path(string $path, ?string $filename = null): self
    {
        return new self(new PathUploadBody($path), $filename ?? basename($path), UploadMetadata::make());
    }

    /** @param resource $stream */
    #[\NoDiscard]
    public static function stream(mixed $stream, int $length, string $filename): self
    {
        return new self(new StreamUploadBody($stream, $length), $filename, UploadMetadata::make());
    }

    #[\NoDiscard]
    public function with(UploadMetadata $metadata): self
    {
        return new self($this->body, $this->filename, $metadata);
    }

    #[\NoDiscard]
    public function title(string $title): self
    {
        return $this->with($this->metadata->title($title));
    }

    #[\NoDiscard]
    public function altText(string $altText): self
    {
        return $this->with($this->metadata->altText($altText));
    }

    #[\NoDiscard]
    public function snippetType(string $snippetType): self
    {
        return $this->with($this->metadata->snippetType($snippetType));
    }

    #[\NoDiscard]
    public function highlightType(string $highlightType): self
    {
        return $this->with($this->metadata->highlightType($highlightType));
    }

    #[\NoDiscard]
    public function toChannel(string $channelId): self
    {
        return $this->with($this->metadata->toChannel($channelId));
    }

    #[\NoDiscard]
    public function inThread(string $threadTs): self
    {
        return $this->with($this->metadata->inThread($threadTs));
    }

    #[\NoDiscard]
    public function comment(string $comment): self
    {
        return $this->with($this->metadata->comment($comment));
    }

    #[\NoDiscard]
    public function blocks(\Thunder\Bolt\BlockKit\Block ...$blocks): self
    {
        return $this->with($this->metadata->blocks(...$blocks));
    }

    /** @internal */
    public function length(): int
    {
        return $this->body->length();
    }

    /** @internal */
    public function body(): UploadBody
    {
        return $this->body;
    }
}
