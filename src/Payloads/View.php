<?php

declare(strict_types=1);

namespace Thunder\Bolt\Payloads;

use Thunder\Bolt\Payloads\Values\FormState;

final readonly class View
{
    public function __construct(
        public string $type,
        public string $callbackId,
        public string $viewId,
        public ?string $userId,
        public array $values,
        public array $view,
        public array $raw,
    ) {}

    public static function fromArray(array $body): self
    {
        $view = is_array($body['view'] ?? null) ? $body['view'] : [];
        $state = is_array($view['state'] ?? null) ? $view['state'] : [];
        $values = is_array($state['values'] ?? null) ? $state['values'] : [];

        return new self(
            type: self::string($body['type'] ?? null) ?? '',
            callbackId: self::string($view['callback_id'] ?? null) ?? '',
            viewId: self::string($view['id'] ?? null) ?? '',
            userId: self::nestedString($body, 'user', 'id'),
            values: $values,
            view: $view,
            raw: $body,
        );
    }

    /**
     * Returns a text-input value when the state cell exposes `value`; otherwise
     * returns the raw state cell for non-text controls. Null means no such cell.
     */
    public function value(string $blockId, string $actionId): mixed
    {
        $cell = $this->state($blockId, $actionId);
        if (!is_array($cell)) {
            return null;
        }

        if (array_key_exists('value', $cell) && (is_string($cell['value']) || $cell['value'] === null)) {
            return $cell['value'];
        }

        return $cell;
    }

    public function state(string $blockId, string $actionId): ?array
    {
        $block = $this->values[$blockId] ?? null;
        if (!is_array($block)) {
            return null;
        }

        $cell = $block[$actionId] ?? null;

        return is_array($cell) ? $cell : null;
    }

    public function form(): FormState
    {
        return FormState::fromArray($this->values);
    }

    private static function nestedString(array $body, string $container, string $key): ?string
    {
        return is_array($body[$container] ?? null) ? self::string($body[$container][$key] ?? null) : null;
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
