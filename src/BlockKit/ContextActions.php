<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

final readonly class ContextActions implements Block
{
    /** @param list<FeedbackButtons|IconButton> $elements */
    private function __construct(
        private array $elements,
    ) {}

    public static function make(FeedbackButtons|IconButton ...$elements): self
    {
        if ($elements === [] || count($elements) > 5) {
            throw new \InvalidArgumentException('Context actions need between 1 and 5 elements.');
        }

        return new self(array_values($elements));
    }

    public function toArray(): array
    {
        return [
            'type' => 'context_actions',
            'elements' => array_map(
                static fn(FeedbackButtons|IconButton $element): array => $element->toArray(),
                $this->elements,
            ),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
