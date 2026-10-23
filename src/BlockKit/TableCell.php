<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

final readonly class TableCell implements \JsonSerializable
{
    private function __construct(
        private ?string $text = null,
        private ?RichText $richText = null,
    ) {}

    public static function text(string $text): self
    {
        if ($text === '') {
            throw new \InvalidArgumentException('A table text cell needs text.');
        }

        return new self(text: $text);
    }

    public static function richText(RichText $richText): self
    {
        return new self(richText: $richText);
    }

    public function toArray(): array
    {
        return $this->richText?->toArray() ?? ['type' => 'raw_text', 'text' => $this->text];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
