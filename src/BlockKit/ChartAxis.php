<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

use Thunder\Bolt\BlockKit\Internal\BlockRules;

final readonly class ChartAxis implements \JsonSerializable
{
    /** @param list<string> $labels */
    private function __construct(
        public array $labels,
        private ?string $xTitle = null,
        private ?string $yTitle = null,
    ) {}

    public static function categories(string ...$labels): self
    {
        if ($labels === [] || count($labels) > 20 || count(array_unique($labels)) !== count($labels)) {
            throw new \InvalidArgumentException('Chart categories need between 1 and 20 unique labels.');
        }
        foreach ($labels as $label) {
            BlockRules::text($label, 20, 'Chart category');
        }
        return new self(array_values($labels));
    }

    #[\NoDiscard]
    public function xLabel(string $label): self
    {
        BlockRules::text($label, 50, 'Chart x-axis title');
        return clone($this, ['xTitle' => $label]);
    }

    #[\NoDiscard]
    public function yLabel(string $label): self
    {
        BlockRules::text($label, 50, 'Chart y-axis title');
        return clone($this, ['yTitle' => $label]);
    }

    public function toArray(): array
    {
        return array_filter(
            ['categories' => $this->labels, 'x_label' => $this->xTitle, 'y_label' => $this->yTitle],
            static fn(mixed $value): bool => $value !== null,
        );
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
