<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

final readonly class RichTextSection implements RichTextBlockElement
{
    /** @param list<RichTextElement> $elements */
    private function __construct(
        private array $elements,
    ) {}

    public static function make(RichTextElement ...$elements): self
    {
        if ($elements === []) {
            throw new \InvalidArgumentException('A rich-text section needs at least one element.');
        }

        return new self(array_values($elements));
    }

    /** @return array{type: 'rich_text_section', elements: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return [
            'type' => 'rich_text_section',
            'elements' => array_map(static fn(RichTextElement $element): array => $element->toArray(), $this->elements),
        ];
    }

    /** @return array{type: 'rich_text_section', elements: list<array<string, mixed>>} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
