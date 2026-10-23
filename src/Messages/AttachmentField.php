<?php

declare(strict_types=1);

namespace Thunder\Bolt\Messages;

final readonly class AttachmentField implements \JsonSerializable
{
    private function __construct(
        private string $title,
        private string $value,
        private bool $isShort = false,
    ) {}

    public static function make(string $title, string $value): self
    {
        if ($title === '' || $value === '') {
            throw new \InvalidArgumentException('Attachment field titles and values cannot be empty.');
        }

        return new self($title, $value);
    }

    #[\NoDiscard]
    public function short(bool $enabled = true): self
    {
        return clone($this, ['isShort' => $enabled]);
    }

    public function toArray(): array
    {
        $data = ['title' => $this->title, 'value' => $this->value];
        if ($this->isShort)
            $data['short'] = true;

        return $data;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
