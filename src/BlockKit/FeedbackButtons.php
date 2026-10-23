<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

final readonly class FeedbackButtons implements Element
{
    private function __construct(
        private Text $positiveText,
        private string $positiveValue,
        private Text $negativeText,
        private string $negativeValue,
        private string $actionId,
        private ?string $positiveAccessibilityLabel = null,
        private ?string $negativeAccessibilityLabel = null,
    ) {}

    public static function make(
        string|Text $positiveText,
        string $positiveValue,
        string|Text $negativeText,
        string $negativeValue,
        string $actionId,
    ): self {
        if ($positiveValue === '' || $negativeValue === '' || $actionId === '' || mb_strlen($actionId) > 255) {
            throw new \InvalidArgumentException('Feedback buttons need values and an action ID.');
        }

        return new self(
            self::plainText($positiveText),
            $positiveValue,
            self::plainText($negativeText),
            $negativeValue,
            $actionId,
        );
    }

    #[\NoDiscard]
    public function positiveAccessibilityLabel(string $label): self
    {
        self::accessibilityLabel($label);

        return clone($this, ['positiveAccessibilityLabel' => $label]);
    }

    #[\NoDiscard]
    public function negativeAccessibilityLabel(string $label): self
    {
        self::accessibilityLabel($label);

        return clone($this, ['negativeAccessibilityLabel' => $label]);
    }

    public function toArray(): array
    {
        $positive = ['text' => $this->positiveText->toArray(), 'value' => $this->positiveValue];
        $negative = ['text' => $this->negativeText->toArray(), 'value' => $this->negativeValue];
        if ($this->positiveAccessibilityLabel !== null) {
            $positive['accessibility_label'] = $this->positiveAccessibilityLabel;
        }
        if ($this->negativeAccessibilityLabel !== null) {
            $negative['accessibility_label'] = $this->negativeAccessibilityLabel;
        }

        return [
            'type' => 'feedback_buttons',
            'action_id' => $this->actionId,
            'positive_button' => $positive,
            'negative_button' => $negative,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private static function accessibilityLabel(string $label): void
    {
        if ($label === '' || mb_strlen($label) > 75) {
            throw new \InvalidArgumentException('An accessibility label needs between 1 and 75 characters.');
        }
    }

    private static function plainText(string|Text $text): Text
    {
        $text = is_string($text) ? Text::plain($text) : $text;
        if ($text->type !== 'plain_text' || mb_strlen($text->value) > 75) {
            throw new \InvalidArgumentException('Feedback button labels need plain text up to 75 characters.');
        }

        return $text;
    }
}
