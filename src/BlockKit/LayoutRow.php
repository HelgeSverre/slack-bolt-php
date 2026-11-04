<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

final readonly class LayoutRow implements \JsonSerializable
{
    /** @param list<LayoutItem> $items */
    private function __construct(
        private array $items,
    ) {}

    public static function make(LayoutItem ...$items): self
    {
        if ($items === [] || count($items) > 3) {
            throw new \InvalidArgumentException('A layout row needs between 1 and 3 items.');
        }
        $large = 0;
        $chartWeight = null;
        $totalWeight = 0;
        foreach ($items as $item) {
            $data = $item->toArray();
            $block = $data['block'] ?? null;
            $type = is_array($block) ? $block['type'] ?? null : null;
            $weight = match ($data['width'] ?? 'standard') {
                'narrow' => 1,
                'wide' => 3,
                default => 2,
            };
            $totalWeight += $weight;
            if (in_array($type, ['carousel', 'data_table', 'data_visualization'], true)) {
                $large++;
            }
            if ($type === 'data_visualization') {
                $chartWeight = $weight;
            }
        }
        if ($large > 1 || $chartWeight !== null && ($chartWeight * 2) <= $totalWeight) {
            throw new \InvalidArgumentException(
                'A layout row accepts one large block; a chart needs more than half the row width.',
            );
        }
        return new self(array_values($items));
    }

    public function toArray(): array
    {
        return ['items' => array_map(static fn(LayoutItem $item): array => $item->toArray(), $this->items)];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
