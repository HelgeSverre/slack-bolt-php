<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

use Thunder\Bolt\BlockKit\Internal\BlockRules;

final readonly class LayoutItem implements \JsonSerializable
{
    private const CHILDREN = [
        'actions',
        'alert',
        'card',
        'carousel',
        'container',
        'context',
        'data_table',
        'data_visualization',
        'divider',
        'file',
        'header',
        'image',
        'input',
        'rich_text',
        'section',
        'table',
        'video',
    ];

    private function __construct(
        private Block $block,
        private ?string $itemWidth = null,
        private ?string $alignment = null,
    ) {}

    public static function make(Block $block): self
    {
        BlockRules::children([$block], self::CHILDREN);
        return new self($block);
    }

    #[\NoDiscard]
    public function width(string $width): self
    {
        BlockRules::choice($width, ['narrow', 'standard', 'wide'], 'layout item width');
        return clone($this, ['itemWidth' => $width]);
    }

    #[\NoDiscard]
    public function horizontalAlignment(string $alignment): self
    {
        BlockRules::choice($alignment, ['start', 'center', 'end'], 'layout item alignment');
        return clone($this, ['alignment' => $alignment]);
    }

    public function toArray(): array
    {
        return array_filter(
            [
                'block' => $this->block->toArray(),
                'width' => $this->itemWidth,
                'horizontal_alignment' => $this->alignment,
            ],
            static fn(mixed $value): bool => $value !== null,
        );
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
