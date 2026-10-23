<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

final readonly class Input implements Block
{
    private function __construct(
        private Text $label,
        private Element $element,
        private ?string $blockId = null,
        private ?Text $hint = null,
        private bool $optional = false,
        private bool $dispatchAction = false,
    ) {}

    public static function make(string|Text $label, Element $element): self
    {
        $label = self::plain($label, 'input label');
        if (mb_strlen($label->value) > 2000) {
            throw new \InvalidArgumentException('An input label must not exceed 2000 characters.');
        }

        return new self($label, $element);
    }

    #[\NoDiscard]
    public function id(string $id): self
    {
        return clone($this, ['blockId' => $id]);
    }

    #[\NoDiscard]
    public function hint(string|Text $hint): self
    {
        $hint = self::plain($hint, 'input hint');
        if (mb_strlen($hint->value) > 2000) {
            throw new \InvalidArgumentException('An input hint must not exceed 2000 characters.');
        }

        return clone($this, ['hint' => $hint]);
    }

    #[\NoDiscard]
    public function optional(bool $optional = true): self
    {
        return clone($this, ['optional' => $optional]);
    }

    #[\NoDiscard]
    public function dispatchAction(bool $enabled = true): self
    {
        return clone($this, ['dispatchAction' => $enabled]);
    }

    public function toArray(): array
    {
        return array_filter(
            [
                'type' => 'input',
                'block_id' => $this->blockId,
                'label' => $this->label->toArray(),
                'element' => $this->element->toArray(),
                'hint' => $this->hint?->toArray(),
                'optional' => $this->optional ?: null,
                'dispatch_action' => $this->dispatchAction ?: null,
            ],
            static fn(mixed $value): bool => $value !== null,
        );
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private static function plain(string|Text $text, string $field): Text
    {
        $text = is_string($text) ? Text::plain($text) : $text;
        if ($text->type !== 'plain_text') {
            throw new \InvalidArgumentException("An {$field} requires plain text.");
        }

        return $text;
    }
}
