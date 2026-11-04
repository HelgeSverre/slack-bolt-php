<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

use Thunder\Bolt\BlockKit\Internal\BlockRules;

final readonly class ChartPoint implements \JsonSerializable
{
    private function __construct(
        public string $label,
        public int|float $value,
    ) {}

    public static function make(string $label, int|float $value): self
    {
        BlockRules::text($label, 20, 'Chart point label');
        if (!is_finite((float) $value)) {
            throw new \InvalidArgumentException('A chart value must be finite.');
        }
        return new self($label, $value);
    }

    public function toArray(): array
    {
        return ['label' => $this->label, 'value' => $this->value];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
