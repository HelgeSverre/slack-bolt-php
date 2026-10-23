<?php

declare(strict_types=1);

namespace Thunder\Bolt\Responses;

use Thunder\Bolt\BlockKit\Option;
use Thunder\Bolt\BlockKit\OptionGroup;

final readonly class OptionsResponse implements Acknowledgement
{
    /** @param array<string, mixed> $body */
    private function __construct(
        private array $body,
    ) {}

    public static function empty(): self
    {
        return new self(['options' => []]);
    }

    public static function options(Option ...$options): self
    {
        self::validateCount($options, 'options');

        return new self(['options' => array_map(static fn(Option $option): array => $option->toArray(), $options)]);
    }

    public static function groups(OptionGroup ...$groups): self
    {
        self::validateCount($groups, 'option groups');

        return new self(['option_groups' => array_map(
            static fn(OptionGroup $group): array => $group->toArray(),
            $groups,
        )]);
    }

    /**
     * Escape hatch for legacy dialog and interactive-message option objects.
     * The body must contain exactly one of `options` or `option_groups`.
     *
     * @param  array<string, mixed>  $body
     */
    public static function fromArray(array $body): self
    {
        if (array_diff(array_keys($body), ['options', 'option_groups']) !== []) {
            throw new \InvalidArgumentException(
                'An options acknowledgement may only contain options or option_groups.',
            );
        }

        $hasOptions = array_key_exists('options', $body);
        $hasGroups = array_key_exists('option_groups', $body);
        if ($hasOptions === $hasGroups) {
            throw new \InvalidArgumentException(
                'An options acknowledgement needs exactly one of options or option_groups.',
            );
        }

        if ($hasOptions) {
            if (!is_array($body['options']) || !array_is_list($body['options'])) {
                throw new \InvalidArgumentException('Options must be a list.');
            }
            self::validateCount($body['options'], 'options');
            foreach ($body['options'] as $option) {
                self::validateOption($option);
            }
        }

        if ($hasGroups) {
            if (!is_array($body['option_groups']) || !array_is_list($body['option_groups'])) {
                throw new \InvalidArgumentException('Option groups must be a list.');
            }
            self::validateCount($body['option_groups'], 'option groups');
            foreach ($body['option_groups'] as $group) {
                if (
                    !is_array($group)
                    || !self::isLabel($group['label'] ?? null)
                    || !is_array($group['options'] ?? null)
                    || !array_is_list($group['options'])
                ) {
                    throw new \InvalidArgumentException('Each option group needs a label and an options list.');
                }
                self::validateCount($group['options'], 'options in an option group');
                foreach ($group['options'] as $option) {
                    self::validateOption($option);
                }
            }
        }

        return new self($body);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->body;
    }

    /** @param array<mixed> $items */
    private static function validateCount(array $items, string $kind): void
    {
        if (count($items) > 100) {
            throw new \InvalidArgumentException("Slack accepts at most 100 {$kind} in one acknowledgement.");
        }
    }

    private static function validateOption(mixed $option): void
    {
        if (
            !is_array($option)
            || !is_string($option['value'] ?? null)
            || !self::isLabel($option['label'] ?? null) && !self::isLabel($option['text'] ?? null)
        ) {
            throw new \InvalidArgumentException('Each option needs a string value and a label or text object.');
        }
    }

    private static function isLabel(mixed $value): bool
    {
        return (
            is_string($value)
            || is_array($value)
            && ($value['type'] ?? null) === 'plain_text'
            && is_string($value['text'] ?? null)
        );
    }
}
