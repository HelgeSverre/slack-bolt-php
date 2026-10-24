<?php

declare(strict_types=1);

namespace Thunder\Bolt\WorkObjects;

use Thunder\Bolt\BlockKit\RichText;

final readonly class CommentBody implements \JsonSerializable
{
    private function __construct(
        private array $data,
    ) {}

    public static function text(string $text): self
    {
        Guard::text($text, 12000);
        return new self(['value' => $text]);
    }

    public static function markdown(string $text): self
    {
        Guard::text($text, 12000);
        return new self(['value' => $text, 'format' => 'markdown']);
    }

    public static function richText(RichText $text): self
    {
        return new self(['blocks' => $text->toArray()]);
    }

    public static function fromArray(array $data): self
    {
        if (isset($data['blocks'], $data['value'])) {
            throw new \InvalidArgumentException('Choose text or rich text for a comment.');
        }
        if (
            is_array($data['blocks'] ?? null)
            && ($data['blocks']['type'] ?? '') === 'rich_text'
            && is_array($data['blocks']['elements'] ?? null)
        ) {
            $elements = array_values($data['blocks']['elements']);
            foreach ($elements as $element) {
                if (!is_array($element) || array_any(array_keys($element), static fn($key): bool => !is_string($key))) {
                    throw new \InvalidArgumentException('A rich-text element must be an object.');
                }
            }
            /** @var list<array<string, mixed>> $elements */
            return self::richText(RichText::fromElements($elements));
        }
        if (!is_string($data['value'] ?? null)) {
            throw new \InvalidArgumentException('A comment needs text or a rich-text block.');
        }
        return ($data['format'] ?? null) === 'markdown' ? self::markdown($data['value']) : self::text($data['value']);
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
