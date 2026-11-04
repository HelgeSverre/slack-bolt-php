<?php

declare(strict_types=1);

namespace Thunder\Bolt\Testing;

/**
 * A complete, synthetic Slack interaction payload for handler tests.
 *
 * Factories make ordinary fixtures concise. `with()` is deliberately a raw
 * escape hatch: associative objects overlay recursively, while lists (even
 * empty lists) replace their seeded value so malformed deliveries remain easy
 * to test.
 */
final readonly class InteractionFixture
{
    /** @param array<array-key, mixed> $payload */
    private function __construct(
        private array $payload,
    ) {}

    public static function action(string $actionId, string $blockId = 'block'): self
    {
        return new self([
            'type' => 'block_actions',
            ...self::identity(),
            'channel' => ['id' => 'C_TEST'],
            'container' => [
                'type' => 'message',
                'channel_id' => 'C_TEST',
                'message_ts' => '1700000000.000001',
            ],
            'message' => [
                'type' => 'message',
                'channel' => 'C_TEST',
                'ts' => '1700000000.000001',
            ],
            'trigger_id' => 'TRIGGER_TEST',
            'response_url' => 'https://example.test/response',
            'actions' => [[
                'action_id' => $actionId,
                'block_id' => $blockId,
                'type' => 'button',
            ]],
        ]);
    }

    public static function shortcut(string $callbackId): self
    {
        return new self([
            'type' => 'shortcut',
            ...self::identity(),
            'callback_id' => $callbackId,
            'trigger_id' => 'TRIGGER_TEST',
        ]);
    }

    public static function options(string $actionId, string $value = '', string $blockId = 'block'): self
    {
        return new self([
            'type' => 'block_suggestion',
            ...self::identity(),
            'action_id' => $actionId,
            'block_id' => $blockId,
            'value' => $value,
        ]);
    }

    public static function viewSubmission(string $callbackId): self
    {
        return new self([
            'type' => 'view_submission',
            ...self::identity(),
            'trigger_id' => 'TRIGGER_TEST',
            'view' => [
                'id' => 'V_TEST',
                'type' => 'modal',
                'callback_id' => $callbackId,
                'state' => ['values' => []],
            ],
        ]);
    }

    public function fromUser(string $userId): self
    {
        return $this->with(['user' => ['id' => $userId]]);
    }

    public function forTeam(string $teamId): self
    {
        return $this->with(['team' => ['id' => $teamId]]);
    }

    public function inChannel(string $channelId, ?string $messageTs = null, ?string $threadTs = null): self
    {
        $payload = $this->type() === 'block_actions' ? self::without($this->payload, [
                'view',
                'channel',
                'container',
                'message',
                'thread_ts',
            ]) : $this->payload;
        $messageTs ??= '1700000000.000001';
        $message = [
            'channel' => $channelId,
            'ts' => $messageTs,
            ...($threadTs === null ? [] : ['thread_ts' => $threadTs]),
        ];

        $payload = self::overlay($payload, [
            'channel' => ['id' => $channelId],
            'container' => ['type' => 'message', 'channel_id' => $channelId, 'message_ts' => $messageTs],
            'message' => $message,
            ...($threadTs === null ? [] : ['thread_ts' => $threadTs]),
        ]);
        if ($this->type() === 'block_actions' && !array_key_exists('response_url', $payload)) {
            $payload['response_url'] = 'https://example.test/response';
        }

        return new self($payload);
    }

    public function view(string $viewId): self
    {
        return match ($this->type()) {
            'view_submission', 'view_closed' => $this->with(['view' => ['id' => $viewId]]),
            'block_actions' => $this->asViewAction($viewId),
            default => throw new \LogicException(
                'view() is available for view submissions and block actions. Use with() for a raw interaction shape.',
            ),
        };
    }

    /**
     * Sets one modal state cell. Scalar values become ordinary plain-text
     * input cells; pass an array for selects, files, or another Slack control.
     *
     * @param array<string, mixed>|string|null $value
     */
    public function state(string $blockId, string $actionId, array|string|null $value): self
    {
        $cell = is_array($value) ? $value : ['type' => 'plain_text_input', 'value' => $value];
        $values = $this->viewValues();
        $block = $values[$blockId] ?? [];
        $block = is_array($block) ? $block : [];
        $block[$actionId] = $cell;
        $values[$blockId] = $block;

        return $this->values($values);
    }

    /** @param array<array-key, mixed> $values */
    public function values(array $values): self
    {
        return $this->with(['view' => ['state' => ['values' => []]]])->with([
            'view' => ['state' => ['values' => $values]],
        ]);
    }

    public function value(string $value): self
    {
        if ($this->type() === 'block_suggestion') {
            return $this->with(['value' => $value]);
        }
        if ($this->type() !== 'block_actions') {
            throw new \LogicException(
                'value() is available for block actions and external-select options. Use with() for a raw interaction shape.',
            );
        }
        $payload = $this->payload;
        $actions = $payload['actions'] ?? null;
        if (!is_array($actions) || !is_array($actions[0] ?? null)) {
            throw new \LogicException(
                'A block action fixture needs an action before its value can change. Use with() for a raw interaction shape.',
            );
        }
        $action = $actions[0];
        $action['value'] = $value;
        $actions[0] = $action;
        $payload['actions'] = $actions;

        return new self($payload);
    }

    /**
     * Applies raw Slack fields without restricting unknown fields.
     *
     * @param array<array-key, mixed> $fields
     */
    public function with(array $fields): self
    {
        return new self(self::overlay($this->payload, $fields));
    }

    /** @return array<array-key, mixed> */
    public function toArray(): array
    {
        return $this->payload;
    }

    /** @return array<string, array{id: string}> */
    private static function identity(): array
    {
        return ['team' => ['id' => 'T_TEST'], 'user' => ['id' => 'U_TEST']];
    }

    private function type(): ?string
    {
        return is_string($this->payload['type'] ?? null) ? $this->payload['type'] : null;
    }

    /** @return array<array-key, mixed> */
    private function viewValues(): array
    {
        $view = $this->payload['view'] ?? null;
        $state = is_array($view) ? $view['state'] ?? null : null;
        $values = is_array($state) ? $state['values'] ?? null : null;

        return is_array($values) ? $values : [];
    }

    private function asViewAction(string $viewId): self
    {
        $payload = self::without($this->payload, [
            'channel',
            'container',
            'message',
            'thread_ts',
            'response_url',
            'view',
        ]);
        $payload['container'] = ['type' => 'view', 'view_id' => $viewId];
        $payload['view'] = ['id' => $viewId, 'type' => 'modal'];

        return new self($payload);
    }

    /** @param array<array-key, mixed> $payload @param list<string> $keys @return array<array-key, mixed> */
    private static function without(array $payload, array $keys): array
    {
        foreach ($keys as $key) {
            unset($payload[$key]);
        }

        return $payload;
    }

    /**
     * @param array<array-key, mixed> $base
     * @param array<array-key, mixed> $overrides
     * @return array<array-key, mixed>
     */
    private static function overlay(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            $existing = $base[$key] ?? null;
            $base[$key] = is_array($existing) && is_array($value) && !array_is_list($existing) && !array_is_list($value)
                ? self::overlay($existing, $value)
                : $value;
        }

        return $base;
    }
}
