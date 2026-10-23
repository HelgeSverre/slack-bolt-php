<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

final readonly class StaticSelect implements Element
{ /** @param list<Option> $options */
    private function __construct(
        private string $actionId,
        private array $options,
        private array $groups = [],
        private ?Text $placeholder = null,
        private ?Option $initial = null,
        private ?Confirm $confirmation = null,
        private ?bool $focus = null,
    ) {}

    public static function make(string $actionId, Option ...$options): self
    {
        if ($actionId === '' || mb_strlen($actionId) > 255 || $options === [] || count($options) > 100) {
            throw new \InvalidArgumentException('A static select needs an action ID and 1 to 100 options.');
        }

        foreach ($options as $option)
            $option->requirePlainText();
        return new self($actionId, array_values($options));
    }

    public static function groups(string $actionId, OptionGroup ...$groups): self
    {
        if ($actionId === '' || mb_strlen($actionId) > 255 || $groups === [] || count($groups) > 100) {
            throw new \InvalidArgumentException('A static select needs an action ID and 1 to 100 option groups.');
        }

        return new self($actionId, [], array_values($groups));
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
                'type' => 'static_select',
                'action_id' => $this->actionId,
                'options' => $this->options === []
                    ? null
                    : array_map(static fn(Option $option): array => $option->toArray(), $this->options),
                'option_groups' => $this->groups === []
                    ? null
                    : array_map(static fn(OptionGroup $group): array => $group->toArray(), $this->groups),
                'placeholder' => $this->placeholder?->toArray(),
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
