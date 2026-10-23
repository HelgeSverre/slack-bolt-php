<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

final readonly class Context implements Block
{ /** @param list<Text|ImageElement> $elements */
    private function __construct(
        private array $elements,
        private ?string $blockId = null,
    ) {}

    public static function make(Text|ImageElement ...$elements): self
    {
        if ($elements === [] || count($elements) > 10)
            throw new \InvalidArgumentException('A context block needs between 1 and 10 elements.');
        return new self(array_values($elements));
    }

    #[\NoDiscard]
    public function id(string $id): self
    {
        return clone($this, ['blockId' => $id]);
    }

    public function toArray(): array
    {
        $d = ['type' => 'context', 'elements' => array_map(static fn($e) => $e->toArray(), $this->elements)];
        if ($this->blockId !== null)
            $d['block_id'] = $this->blockId;
        return $d;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
