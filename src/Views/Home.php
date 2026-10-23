<?php

declare(strict_types=1);

namespace Thunder\Bolt\Views;

use Thunder\Bolt\BlockKit\Block;

final readonly class Home implements View
{ /** @param list<Block> $blocks */
    private function __construct(
        private array $blocks,
        private ?string $privateMetadata = null,
        private ?string $callback = null,
        private ?string $external = null,
    ) {}

    public static function make(Block ...$blocks): self
    {
        if (count($blocks) > 100)
            throw new \InvalidArgumentException('A home view accepts at most 100 blocks.');
        return new self(array_values($blocks));
    }

    #[\NoDiscard]
    public function privateMetadata(string $value): self
    {
        if (mb_strlen($value) > 3000) {
            throw new \InvalidArgumentException('View private metadata must not exceed 3000 characters.');
        }
        return clone($this, ['privateMetadata' => $value]);
    }

    #[\NoDiscard]
    public function callbackId(string $id): self
    {
        if (mb_strlen($id) > 255) {
            throw new \InvalidArgumentException('View callback IDs must not exceed 255 characters.');
        }
        return clone($this, ['callback' => $id]);
    }

    #[\NoDiscard]
    public function externalId(string $id): self
    {
        return clone($this, ['external' => $id]);
    }

    public function toArray(): array
    {
        $d = ['type' => 'home', 'blocks' => array_map(static fn(Block $b) => $b->toArray(), $this->blocks)];
        if ($this->privateMetadata !== null)
            $d['private_metadata'] = $this->privateMetadata;
        if ($this->callback !== null)
            $d['callback_id'] = $this->callback;
        if ($this->external !== null)
            $d['external_id'] = $this->external;
        return $d;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
