<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

final readonly class Button implements Element
{
    private function __construct(
        private string $label,
        private string $actionId,
        private ?string $buttonValue = null,
        private ?string $buttonStyle = null,
        private ?string $buttonUrl = null,
        private ?Confirm $confirmation = null,
        private ?string $accessibleLabel = null,
    ) {}

    public static function make(string $label, string $actionId): self
    {
        self::length($label, 'button label', 75);
        self::length($actionId, 'button action ID', 255);

        return new self($label, $actionId);
    }

    #[\NoDiscard]
    public function value(string $value): self
    {
        self::length($value, 'button value', 2000);

        return clone($this, ['buttonValue' => $value]);
    }

    #[\NoDiscard]
    public function url(string $url): self
    {
        self::length($url, 'button URL', 3000);
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException('A button URL must be an absolute URL.');
        }

        return clone($this, ['buttonUrl' => $url]);
    }

    #[\NoDiscard]
    public function confirm(Confirm $confirmation): self
    {
        return clone($this, ['confirmation' => $confirmation]);
    }

    #[\NoDiscard]
    public function accessibilityLabel(string $label): self
    {
        self::length($label, 'button accessibility label', 75);

        return clone($this, ['accessibleLabel' => $label]);
    }

    #[\NoDiscard]
    public function primary(): self
    {
        return clone($this, ['buttonStyle' => 'primary']);
    }

    #[\NoDiscard]
    public function danger(): self
    {
        return clone($this, ['buttonStyle' => 'danger']);
    }

    public function toArray(): array
    {
        return array_filter(
            [
                'type' => 'button',
                'text' => ['type' => 'plain_text', 'text' => $this->label],
                'action_id' => $this->actionId,
                'value' => $this->buttonValue,
                'style' => $this->buttonStyle,
                'url' => $this->buttonUrl,
                'confirm' => $this->confirmation?->toArray(),
                'accessibility_label' => $this->accessibleLabel,
            ],
            static fn(mixed $value): bool => $value !== null,
        );
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private static function length(string $value, string $field, int $maximum): void
    {
        if ($value === '' || mb_strlen($value) > $maximum) {
            throw new \InvalidArgumentException("A {$field} needs between 1 and {$maximum} characters.");
        }
    }
}
