<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

final readonly class RichTextList implements RichTextBlockElement
{
    /** @param list<RichTextSection> $elements */
    private function __construct(
        private string $listStyle,
        private array $elements,
        private ?int $listIndent = null,
        private ?int $listBorder = null,
    ) {}

    public static function bullet(RichTextSection ...$elements): self
    {
        return self::make('bullet', array_values($elements));
    }

    public static function ordered(RichTextSection ...$elements): self
    {
        return self::make('ordered', array_values($elements));
    }

    #[\NoDiscard]
    public function indent(int $indent): self
    {
        if ($indent < 0 || $indent > 8) {
            throw new \InvalidArgumentException('A rich-text list indent must be between 0 and 8.');
        }

        return clone($this, ['listIndent' => $indent]);
    }

    #[\NoDiscard]
    public function border(bool $visible = true): self
    {
        return clone($this, ['listBorder' => $visible ? 1 : 0]);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'type' => 'rich_text_list',
            'style' => $this->listStyle,
            'elements' => array_map(static fn(RichTextSection $element): array => $element->toArray(), $this->elements),
        ];
        if ($this->listIndent !== null) {
            $data['indent'] = $this->listIndent;
        }
        if ($this->listBorder !== null) {
            $data['border'] = $this->listBorder;
        }

        return $data;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** @param list<RichTextSection> $elements */
    private static function make(string $style, array $elements): self
    {
        if ($elements === []) {
            throw new \InvalidArgumentException('A rich-text list needs at least one section.');
        }

        return new self($style, array_values($elements));
    }
}
