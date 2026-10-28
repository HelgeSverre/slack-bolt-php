<?php

declare(strict_types=1);

namespace Thunder\Bolt\Payloads\Values;

final readonly class FormState
{
    public function __construct(
        public array $raw,
    ) {}

    public static function fromArray(array $values): self
    {
        return new self($values);
    }

    public function get(string $blockId, string $actionId): ?InputValue
    {
        $block = $this->raw[$blockId] ?? null;
        if (!is_array($block) || !is_array($block[$actionId] ?? null)) {
            return null;
        }
        return InputValue::fromArray($block[$actionId]);
    }

    public function require(string $blockId, string $actionId): InputValue
    {
        return (
            $this->get($blockId, $actionId) ?? throw new \OutOfBoundsException(
                "Missing form state: {$blockId}.{$actionId}.",
            )
        );
    }
}
