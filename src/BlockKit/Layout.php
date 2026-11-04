<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

use Thunder\Bolt\BlockKit\Internal\BlockRules;

final readonly class Layout implements Block
{
    /**
     * @param list<LayoutRow> $rows
     * @param null|list<Block> $fallbackBlocks
     */
    private function __construct(
        private string $label,
        private array $rows,
        private ?bool $isLabelVisible = null,
        private ?string $lines = null,
        private ?array $fallbackBlocks = null,
        private ?string $blockId = null,
    ) {}

    public static function make(string $label, LayoutRow ...$rows): self
    {
        BlockRules::text($label, 150, 'Layout label');
        if ($rows === [] || count($rows) > 5) {
            throw new \InvalidArgumentException('A layout needs between 1 and 5 rows.');
        }
        $layout = new self($label, array_values($rows));
        BlockRules::flatten([$layout->toArray()]);
        return $layout;
    }

    #[\NoDiscard]
    public function labelVisible(bool $enabled = true): self
    {
        return clone($this, ['isLabelVisible' => $enabled]);
    }

    #[\NoDiscard]
    public function gridLines(string $lines): self
    {
        BlockRules::choice($lines, ['none', 'rows', 'outer'], 'layout grid lines');
        return clone($this, ['lines' => $lines]);
    }

    #[\NoDiscard]
    public function fallback(Block ...$blocks): self
    {
        BlockRules::children(array_values($blocks), [
            'context',
            'divider',
            'header',
            'image',
            'rich_text',
            'section',
            'table',
            'video',
        ]);
        return clone($this, ['fallbackBlocks' => array_values($blocks)]);
    }

    #[\NoDiscard]
    public function id(string $id): self
    {
        BlockRules::text($id, 255, 'Block ID');
        return clone($this, ['blockId' => $id]);
    }

    public function toArray(): array
    {
        return array_filter(
            [
                'type' => 'layout',
                'label' => $this->label,
                'rows' => array_map(static fn(LayoutRow $row): array => $row->toArray(), $this->rows),
                'is_label_visible' => $this->isLabelVisible,
                'grid_lines' => $this->lines,
                'fallback' => $this->fallbackBlocks === null
                    ? null
                    : array_map(static fn(Block $block): array => $block->toArray(), $this->fallbackBlocks),
                'block_id' => $this->blockId,
            ],
            static fn(mixed $value): bool => $value !== null,
        );
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
