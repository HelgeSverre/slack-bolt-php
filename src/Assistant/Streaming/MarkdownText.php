<?php

declare(strict_types=1);

namespace Thunder\Bolt\Assistant\Streaming;

final readonly class MarkdownText implements Chunk
{
    private function __construct(
        public string $text,
    ) {}

    public static function make(string $text): self
    {
        if ($text === '' || mb_strlen($text) > 12000) {
            throw new \InvalidArgumentException('Stream text must contain 1 to 12000 characters.');
        }
        return new self($text);
    }

    public function toArray(): array
    {
        return ['type' => 'markdown_text', 'text' => $this->text];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
