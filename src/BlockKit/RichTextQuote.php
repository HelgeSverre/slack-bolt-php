<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

final readonly class RichTextQuote implements RichTextBlockElement
{
    /** @param list<RichTextElement> $elements */
    private function __construct(
        private array $elements,
        private ?int $quoteBorder = null,
    ) {}

    public static function make(RichTextElement ...$elements): self
    {
        if ($elements === []) {
            throw new \InvalidArgumentException('A rich-text quote needs at least one element.');
        }

        return new self(array_values($elements));
    }

    #[\NoDiscard]
    public function border(bool $visible = true): self
    {
        return clone($this, ['quoteBorder' => $visible ? 1 : 0]);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'type' => 'rich_text_quote',
            'elements' => array_map(static fn(RichTextElement $element): array => $element->toArray(), $this->elements),
        ];
        if ($this->quoteBorder !== null) {
            $data['border'] = $this->quoteBorder;
        }

        return $data;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
