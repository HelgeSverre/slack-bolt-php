<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

final readonly class RichText implements Block
{
    /** @param list<array<string, mixed>> $elements */
    private function __construct(
        private array $elements,
        private ?string $blockId = null,
    ) {}

    /**
     * Builds from a raw Slack payload for forwards-compatible extension elements.
     *
     * @param array<string, mixed>|list<array<string, mixed>> $elements
     */
    public static function fromElements(array $elements): self
    {
        if (!array_is_list($elements)) {
            /** @var array<string, mixed> $element */
            $element = $elements;

            return self::fromSerialized([$element]);
        }

        return self::fromSerialized($elements);
    }

    /** Builds a rich-text block from typed rich-text containers. */
    public static function make(RichTextBlockElement ...$elements): self
    {
        return self::fromSerialized(array_values(array_map(
            static fn(RichTextBlockElement $element): array => $element->toArray(),
            $elements,
        )));
    }

    #[\NoDiscard]
    public function id(string $id): self
    {
        if ($id === '' || mb_strlen($id) > 255) {
            throw new \InvalidArgumentException('A rich text block ID needs between 1 and 255 characters.');
        }

        return clone($this, ['blockId' => $id]);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = ['type' => 'rich_text', 'elements' => $this->elements];
        if ($this->blockId !== null) {
            $data['block_id'] = $this->blockId;
        }

        return $data;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** @param list<array<string, mixed>> $elements */
    private static function fromSerialized(array $elements): self
    {
        if ($elements === []) {
            throw new \InvalidArgumentException('A rich text block needs elements.');
        }
        foreach ($elements as $element) {
            if (!is_string($element['type'] ?? null) || $element['type'] === '') {
                throw new \InvalidArgumentException('Every rich text element needs a type.');
            }
        }

        return new self(array_values($elements));
    }
}
