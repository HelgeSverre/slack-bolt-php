<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

use Thunder\Bolt\BlockKit\Internal\BlockRules;

final readonly class Container implements Block
{
    private const CHILDREN = [
        'actions',
        'context',
        'divider',
        'file',
        'header',
        'image',
        'input',
        'layout',
        'rich_text',
        'section',
        'table',
        'video',
    ];

    /** @param list<Block> $children */
    private function __construct(
        private Text|RichText $heading,
        private array $children,
        private ?Text $subtitleText = null,
        private ?RichText $richHeading = null,
        private ?ImageElement $iconImage = null,
        private ?string $blockId = null,
        private ?string $containerWidth = null,
        private ?bool $isCollapsible = null,
        private ?bool $defaultCollapsed = null,
        private ?bool $hasHeaderDivider = null,
    ) {}

    public static function make(string|Text|RichText $title, Block ...$children): self
    {
        $title = is_string($title) ? Text::plain($title) : $title;
        if ($title instanceof Text) {
            if ($title->type !== 'plain_text') {
                throw new \InvalidArgumentException('A container title requires plain text or RichText.');
            }
            BlockRules::text($title->value, 150, 'Container title');
        }
        if (count($children) > 10) {
            throw new \InvalidArgumentException('A container accepts at most 10 child blocks.');
        }
        BlockRules::children(array_values($children), self::CHILDREN);
        return new self($title, array_values($children));
    }

    #[\NoDiscard]
    public function subtitle(string|Text $text): self
    {
        $text = is_string($text) ? Text::plain($text) : $text;
        BlockRules::text($text->value, 150, 'Container subtitle');
        return clone($this, ['subtitleText' => $text]);
    }

    #[\NoDiscard]
    public function richTextTitle(RichText $title): self
    {
        return clone($this, ['richHeading' => $title]);
    }

    #[\NoDiscard]
    public function icon(ImageElement $image): self
    {
        $data = $image->toArray();
        if (isset($data['image_url']) && is_string($data['image_url']) && mb_strlen($data['image_url']) > 3000) {
            throw new \InvalidArgumentException('A container icon URL accepts at most 3000 characters.');
        }
        return clone($this, ['iconImage' => $image]);
    }

    #[\NoDiscard]
    public function width(string $width): self
    {
        BlockRules::choice($width, ['narrow', 'standard', 'wide', 'full'], 'container width');
        return clone($this, ['containerWidth' => $width]);
    }

    #[\NoDiscard]
    public function id(string $id): self
    {
        BlockRules::text($id, 255, 'Block ID');
        return clone($this, ['blockId' => $id]);
    }

    #[\NoDiscard]
    public function collapsible(bool $enabled = true): self
    {
        return clone($this, ['isCollapsible' => $enabled]);
    }

    #[\NoDiscard]
    public function collapsed(bool $enabled = true): self
    {
        return clone($this, ['defaultCollapsed' => $enabled]);
    }

    #[\NoDiscard]
    public function headerDivider(bool $enabled = true): self
    {
        return clone($this, ['hasHeaderDivider' => $enabled]);
    }

    public function toArray(): array
    {
        return array_filter(
            [
                'type' => 'container',
                'title' => $this->heading instanceof Text ? $this->heading->toArray() : null,
                'rich_text_title' => ($this->richHeading
                ?? ($this->heading instanceof RichText ? $this->heading : null))?->toArray(),
                'child_blocks' => array_map(static fn(Block $block): array => $block->toArray(), $this->children),
                'subtitle' => $this->subtitleText?->toArray(),
                'icon' => $this->iconImage?->toArray(),
                'block_id' => $this->blockId,
                'width' => $this->containerWidth,
                'is_collapsible' => $this->isCollapsible,
                'default_collapsed' => $this->defaultCollapsed,
                'has_header_divider' => $this->hasHeaderDivider,
            ],
            static fn(mixed $value): bool => $value !== null,
        );
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
