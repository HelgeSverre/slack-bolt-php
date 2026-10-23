<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

final readonly class Markdown implements Block
{
    private function __construct(
        private string $text,
    ) {}

    public static function make(string $text): self
    {
        if ($text === '' || mb_strlen($text) > 12_000) {
            throw new \InvalidArgumentException('Markdown needs between 1 and 12000 characters.');
        }

        return new self($text);
    }

    public function toArray(): array
    {
        return ['type' => 'markdown', 'text' => $this->text];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
