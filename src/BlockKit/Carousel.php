<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

final readonly class Carousel implements Block
{
    /** @param list<Card> $elements */
    private function __construct(
        private array $elements,
    ) {}

    public static function make(Card ...$elements): self
    {
        if ($elements === [] || count($elements) > 10) {
            throw new \InvalidArgumentException('A carousel needs between 1 and 10 cards.');
        }

        return new self(array_values($elements));
    }

    public function toArray(): array
    {
        return [
            'type' => 'carousel',
            'elements' => array_map(static fn(Card $card): array => $card->toArray(), $this->elements),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
