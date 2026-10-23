<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

final readonly class IconButton implements Element
{
    /** @param list<string> $visibleToUserIds */
    private function __construct(
        private string $icon,
        private Text $text,
        private string $actionId,
        private ?string $accessibilityLabel = null,
        private ?string $buttonValue = null,
        private array $visibleToUserIds = [],
        private ?Confirm $confirmation = null,
    ) {}

    public static function make(string $icon, string|Text $text, string $actionId): self
    {
        if ($icon === '' || $actionId === '' || mb_strlen($actionId) > 255) {
            throw new \InvalidArgumentException('An icon button needs an icon and action ID.');
        }

        return new self($icon, self::plainText($text), $actionId);
    }

    #[\NoDiscard]
    public function accessibilityLabel(string $label): self
    {
        if ($label === '' || mb_strlen($label) > 75) {
            throw new \InvalidArgumentException('An accessibility label needs between 1 and 75 characters.');
        }

        return clone($this, ['accessibilityLabel' => $label]);
    }

    #[\NoDiscard]
    public function value(string $value): self
    {
        return clone($this, ['buttonValue' => $value]);
    }

    #[\NoDiscard]
    public function visibleTo(string ...$userIds): self
    {
        if (in_array('', $userIds, true)) {
            throw new \InvalidArgumentException('Visible user IDs cannot be empty.');
        }

        return clone($this, ['visibleToUserIds' => $userIds]);
    }

    #[\NoDiscard]
    public function confirm(Confirm $confirm): self
    {
        return clone($this, ['confirmation' => $confirm]);
    }

    public function toArray(): array
    {
        $data = [
            'type' => 'icon_button',
            'icon' => $this->icon,
            'text' => $this->text->toArray(),
            'action_id' => $this->actionId,
        ];
        if ($this->accessibilityLabel !== null) {
            $data['accessibility_label'] = $this->accessibilityLabel;
        }
        if ($this->buttonValue !== null) {
            $data['value'] = $this->buttonValue;
        }
        if ($this->visibleToUserIds !== []) {
            $data['visible_to_user_ids'] = $this->visibleToUserIds;
        }
        if ($this->confirmation !== null) {
            $data['confirm'] = $this->confirmation->toArray();
        }

        return $data;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private static function plainText(string|Text $text): Text
    {
        $text = is_string($text) ? Text::plain($text) : $text;
        if ($text->type !== 'plain_text') {
            throw new \InvalidArgumentException('An icon button needs plain text.');
        }

        return $text;
    }
}
