<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

/** @internal Common immutable formatting API for typed rich-text leaves. */
abstract readonly class RichTextLeaf implements RichTextElement
{
    abstract protected function currentStyle(): RichTextStyle;

    abstract protected function withStyle(RichTextStyle $style): static;

    #[\NoDiscard]
    final public function bold(): static
    {
        return $this->withStyle($this->currentStyle()->bold());
    }

    #[\NoDiscard]
    final public function code(): static
    {
        return $this->withStyle($this->currentStyle()->code());
    }

    #[\NoDiscard]
    final public function italic(): static
    {
        return $this->withStyle($this->currentStyle()->italic());
    }

    #[\NoDiscard]
    final public function strike(): static
    {
        return $this->withStyle($this->currentStyle()->strike());
    }

    #[\NoDiscard]
    final public function underline(): static
    {
        return $this->withStyle($this->currentStyle()->underline());
    }

    /** @param array<string, mixed> $data */
    final protected function addStyle(array &$data, RichTextStyle $style): void
    {
        if (($styleData = $style->toArray()) !== []) {
            $data['style'] = $styleData;
        }
    }

    /** @return array<string, mixed> */
    final public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
