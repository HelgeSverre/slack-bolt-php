<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

final readonly class PlainTextInput implements Element
{
    private function __construct(
        private string $actionId,
        private ?Text $placeholderText = null,
        private ?string $initialValue = null,
        private ?bool $isMultiline = null,
        private ?int $minimumLength = null,
        private ?int $maximumLength = null,
        private ?bool $focus = null,
        private ?array $dispatch = null,
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
            throw new \InvalidArgumentException('An input placeholder requires plain text of at most 150 characters.');
        }
        return clone($this, ['placeholderText' => $text]);
    }

    #[\NoDiscard]
    public function initial(string $value): self
    {
        return clone($this, ['initialValue' => $value]);
    }

    #[\NoDiscard]
    public function multiline(bool $enabled = true): self
    {
        return clone($this, ['isMultiline' => $enabled]);
    }

    #[\NoDiscard]
    public function length(int $min = 0, ?int $max = null): self
    {
        if ($min < 0 || $min > 3000 || $max !== null && ($max < $min || $max > 3000)) {
            throw new \InvalidArgumentException('Input lengths must be ordered and between zero and 3000.');
        }
        return clone($this, ['minimumLength' => $min, 'maximumLength' => $max]);
    }

    #[\NoDiscard]
    public function focusOnLoad(bool $enabled = true): self
    {
        return clone($this, ['focus' => $enabled]);
    }

    #[\NoDiscard]
    public function dispatchOn(string ...$triggers): self
    {
        foreach ($triggers as $trigger) {
            if (!in_array($trigger, ['on_enter_pressed', 'on_character_entered'], true)) {
                throw new \InvalidArgumentException('Unknown input dispatch trigger.');
            }
        }
        if ($triggers === []) {
            throw new \InvalidArgumentException('At least one input dispatch trigger is required.');
        }
        return clone($this, ['dispatch' => ['trigger_actions_on' => array_values(array_unique($triggers))]]);
    }

    public function toArray(): array
    {
        return array_filter(
            [
                'type' => 'plain_text_input',
                'action_id' => $this->actionId,
                'placeholder' => $this->placeholderText?->toArray(),
                'initial_value' => $this->initialValue,
                'multiline' => $this->isMultiline,
                'min_length' => $this->minimumLength,
                'max_length' => $this->maximumLength,
                'focus_on_load' => $this->focus,
                'dispatch_action_config' => $this->dispatch,
            ],
            static fn(mixed $value): bool => $value !== null,
        );
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
