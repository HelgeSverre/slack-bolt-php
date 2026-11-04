<?php

declare(strict_types=1);

namespace Thunder\Bolt\Assistant\Streaming;

use Thunder\Bolt\BlockKit\Block;
use Thunder\Bolt\BlockKit\Internal\BlockRules;

final readonly class Blocks implements Chunk
{
    /** @param list<Block> $blocks */
    private function __construct(
        public array $blocks,
    ) {}

    public static function make(Block ...$blocks): self
    {
        if ($blocks === [] || count($blocks) > 50) {
            throw new \InvalidArgumentException('A blocks chunk needs 1 to 50 blocks.');
        }
        BlockRules::message(array_map(static fn(Block $block): array => $block->toArray(), array_values($blocks)));
        return new self(array_values($blocks));
    }

    public function toArray(): array
    {
        return [
            'type' => 'blocks',
            'blocks' => array_map(static fn(Block $block): array => $block->toArray(), $this->blocks),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
