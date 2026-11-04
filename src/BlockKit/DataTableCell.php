<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

use Thunder\Bolt\BlockKit\Internal\BlockRules;

final readonly class DataTableCell implements \JsonSerializable
{
    /** @param array<string, mixed> $data */
    private function __construct(
        private array $data,
    ) {}

    public static function text(string $text): self
    {
        BlockRules::text($text, 20000, 'Data table cell text');
        return new self(['type' => 'raw_text', 'text' => $text]);
    }

    public static function number(int|float $value, ?string $text = null): self
    {
        if (!is_finite((float) $value)) {
            throw new \InvalidArgumentException('A data table number must be finite.');
        }
        $text ??= (string) $value;
        BlockRules::text($text, 20000, 'Data table number text');
        return new self(['type' => 'raw_number', 'value' => $value, 'text' => $text]);
    }

    public static function richText(RichText $text): self
    {
        return new self($text->toArray());
    }

    public static function action(Button $button, ?self $fallback = null): self
    {
        if ($fallback !== null && !in_array($fallback->data['type'], ['raw_text', 'raw_number'], true)) {
            throw new \InvalidArgumentException('An action cell fallback must be a text or number cell.');
        }
        return new self(array_filter(
            [
                'type' => 'action_cell',
                'element' => $button->toArray(),
                'fallback' => $fallback?->toArray(),
            ],
            static fn(mixed $value): bool => $value !== null,
        ));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
