<?php

declare(strict_types=1);

namespace Thunder\Bolt\Routing;

use Thunder\Bolt\Exceptions\InvalidListener;

final readonly class InteractionConstraints
{
    public function __construct(
        public string|Regex|null $actionId = null,
        public string|Regex|null $blockId = null,
        public string|Regex|null $callbackId = null,
        public ?string $type = null,
    ) {}

    public static function action(
        string|Regex|null $actionId = null,
        string|Regex|null $blockId = null,
        string|Regex|null $callbackId = null,
        ?string $type = null,
    ): self {
        return new self($actionId, $blockId, $callbackId, $type);
    }

    public static function shortcut(string|Regex $callbackId, ?string $type = null): self
    {
        return new self(callbackId: $callbackId, type: $type);
    }

    public static function options(
        string|Regex|null $actionId = null,
        string|Regex|null $blockId = null,
        string|Regex|null $callbackId = null,
        ?string $type = null,
    ): self {
        return new self($actionId, $blockId, $callbackId, $type);
    }

    public static function view(string|Regex $callbackId, ?string $type = 'view_submission'): self
    {
        return new self(callbackId: $callbackId, type: $type);
    }

    public static function normalize(ListenerKind $kind, string|Regex|self $selector): self
    {
        $constraints = $selector instanceof self
            ? $selector
            : match ($kind) {
                ListenerKind::Action, ListenerKind::Options => new self(actionId: $selector),
                ListenerKind::Shortcut => new self(callbackId: $selector),
                ListenerKind::View => self::view($selector),
                default => throw new InvalidListener(
                    'Interaction constraints belong to action, shortcut, options, or view listeners.',
                ),
            };
        $constraints->validate($kind);

        return $constraints;
    }

    public function validate(ListenerKind $kind): void
    {
        if (
            in_array($kind, [ListenerKind::Shortcut, ListenerKind::View], true)
            && ($this->actionId !== null || $this->blockId !== null)
        ) {
            throw new InvalidListener("{$kind->value} listeners accept callbackId and type constraints only.");
        }
        $types = match ($kind) {
            ListenerKind::Action => ['block_actions', 'interactive_message', 'dialog_submission'],
            ListenerKind::Shortcut => ['shortcut', 'message_action'],
            ListenerKind::Options => ['block_suggestion', 'interactive_message', 'dialog_suggestion'],
            ListenerKind::View => ['view_submission', 'view_closed'],
            default => [],
        };
        if ($this->type !== null && !in_array($this->type, $types, true)) {
            throw new InvalidListener("Unsupported {$kind->value} payload type: {$this->type}.");
        }
    }

    public function key(): string
    {
        $data = [];
        foreach (['actionId', 'blockId', 'callbackId', 'type'] as $field) {
            $value = $this->{$field};
            $data[$field] = $value instanceof Regex ? ['regex' => $value->pattern] : $value;
        }

        return json_encode($data, JSON_THROW_ON_ERROR);
    }

    public function describe(): string
    {
        $data = [];
        foreach ([
            'actionId' => 'action_id',
            'blockId' => 'block_id',
            'callbackId' => 'callback_id',
            'type' => 'type',
        ] as $field => $name) {
            $value = $this->{$field};
            if ($value !== null) {
                $data[$name] = $value instanceof Regex ? ['regex' => $value->pattern] : $value;
            }
        }

        return json_encode((object) $data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
