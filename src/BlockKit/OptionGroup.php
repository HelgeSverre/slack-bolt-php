<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

final readonly class OptionGroup implements \JsonSerializable
{
    /** @param list<Option> $options */
    private function __construct(
        private string $label,
        private array $options,
    ) {}

    public static function make(string $label, Option ...$options): self
    {
        if ($label === '' || mb_strlen($label) > 75) {
            throw new \InvalidArgumentException('An option group label needs between 1 and 75 characters.');
        }
        if ($options === [] || count($options) > 100) {
            throw new \InvalidArgumentException('An option group needs between 1 and 100 options.');
        }

        foreach ($options as $option)
            $option->requirePlainText();
        return new self($label, array_values($options));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'label' => ['type' => 'plain_text', 'text' => $this->label],
            'options' => array_map(static fn(Option $option): array => $option->toArray(), $this->options),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
