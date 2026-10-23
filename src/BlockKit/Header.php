<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

final readonly class Header implements Block
{
    private function __construct(
        private Text $text,
        private ?string $blockId = null,
    ) {}

    public static function make(string|Text $text): self
    {
        $text = is_string($text) ? Text::plain($text) : $text;
        if ($text->type !== 'plain_text' || mb_strlen($text->value) > 150)
            throw new \InvalidArgumentException('A header needs plain text up to 150 characters.');
        return new self($text);
    }

    #[\NoDiscard]
    public function id(string $id): self
    {
        if ($id === '' || mb_strlen($id) > 255)
            throw new \InvalidArgumentException('A block ID needs between 1 and 255 characters.');
        return clone($this, ['blockId' => $id]);
    }

    public function toArray(): array
    {
        $d = ['type' => 'header', 'text' => $this->text->toArray()];
        if ($this->blockId !== null)
            $d['block_id'] = $this->blockId;
        return $d;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
