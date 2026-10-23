<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

final readonly class Image implements Block
{
    private function __construct(
        private ImageElement $image,
        private ?Text $title = null,
        private ?string $blockId = null,
    ) {}

    public static function make(ImageElement $image): self
    {
        return new self($image);
    }

    #[\NoDiscard]
    public function title(string|Text $text): self
    {
        $text = is_string($text) ? Text::plain($text) : $text;
        if ($text->type !== 'plain_text' || mb_strlen($text->value) > 2000) {
            throw new \InvalidArgumentException('An image title requires plain text of at most 2000 characters.');
        }
        return clone($this, ['title' => $text]);
    }

    #[\NoDiscard]
    public function id(string $id): self
    {
        if ($id === '' || mb_strlen($id) > 255) {
            throw new \InvalidArgumentException('A block ID needs between 1 and 255 characters.');
        }
        return clone($this, ['blockId' => $id]);
    }

    public function toArray(): array
    {
        $d = ['type' => 'image', ...$this->image->toArray()];
        unset($d['type']);
        $d = ['type' => 'image', ...$d];
        if ($this->title !== null)
            $d['title'] = $this->title->toArray();
        if ($this->blockId !== null)
            $d['block_id'] = $this->blockId;
        return $d;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
