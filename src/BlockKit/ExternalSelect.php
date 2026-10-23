<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

final readonly class ExternalSelect implements Element
{
    private function __construct(
        private string $actionId,
        private ?Text $placeholder = null,
        private ?int $minQueryLength = null,
        private ?Option $initial = null,
        private ?Confirm $confirmation = null,
        private ?bool $focus = null,
    ) {}

    public static function make(string $actionId): self
    {
        if ($actionId === '' || mb_strlen($actionId) > 255) {
            throw new \InvalidArgumentException('An action ID needs between 1 and 255 characters.');
        }

        return new self($actionId);
    }

    #[\NoDiscard]
    public function placeholder(string|Text $text): self
    {
        $text = is_string($text) ? Text::plain($text) : $text;
        if ($text->type !== 'plain_text' || mb_strlen($text->value) > 150) {
            throw new \InvalidArgumentException('A select placeholder requires plain text of at most 150 characters.');
        }

        return clone($this, ['placeholder' => $text]);
    }

    #[\NoDiscard]
    public function minQueryLength(int $length): self
    {
        if ($length < 0 || $length > 100) {
            throw new \InvalidArgumentException('The minimum query length must be between 0 and 100.');
        }

        return clone($this, ['minQueryLength' => $length]);
    }

    #[\NoDiscard]
    public function initial(Option $option): self
    {
        $option->requirePlainText();
        return clone($this, ['initial' => $option]);
    }

    #[\NoDiscard]
    public function confirm(Confirm $confirmation): self
    {
        return clone($this, ['confirmation' => $confirmation]);
    }

    #[\NoDiscard]
    public function focusOnLoad(bool $enabled = true): self
    {
        return clone($this, ['focus' => $enabled]);
    }

    public function toArray(): array
    {
        return array_filter(
            [
                'type' => 'external_select',
                'action_id' => $this->actionId,
                'placeholder' => $this->placeholder?->toArray(),
                'min_query_length' => $this->minQueryLength,
                'initial_option' => $this->initial?->toArray(),
                'confirm' => $this->confirmation?->toArray(),
                'focus_on_load' => $this->focus,
            ],
            static fn(mixed $value): bool => $value !== null,
        );
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
