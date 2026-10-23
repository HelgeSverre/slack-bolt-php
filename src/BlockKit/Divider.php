<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

final readonly class Divider implements Block
{
    private function __construct(
        private ?string $blockId = null,
    ) {}

    public static function make(): self
    {
        return new self();
    }

    #[\NoDiscard]
    public function id(string $id): self
    {
        return clone($this, ['blockId' => $id]);
    }

    public function toArray(): array
    {
        return array_filter(['type' => 'divider', 'block_id' => $this->blockId], static fn($v) => $v !== null);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
