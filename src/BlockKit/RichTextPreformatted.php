<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

final readonly class RichTextPreformatted implements RichTextBlockElement
{
    /** @param list<RichTextText|RichTextLink> $elements */
    private function __construct(
        private array $elements,
        private ?int $preformattedBorder = null,
    ) {}

    public static function make(RichTextText|RichTextLink ...$elements): self
    {
        if ($elements === []) {
            throw new \InvalidArgumentException('Preformatted rich text needs at least one text or link element.');
        }

        return new self(array_values($elements));
    }

    #[\NoDiscard]
    public function border(bool $visible = true): self
    {
        return clone($this, ['preformattedBorder' => $visible ? 1 : 0]);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'type' => 'rich_text_preformatted',
            'elements' => array_map(
                static fn(RichTextText|RichTextLink $element): array => $element->toArray(),
                $this->elements,
            ),
        ];
        if ($this->preformattedBorder !== null) {
            $data['border'] = $this->preformattedBorder;
        }

        return $data;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
