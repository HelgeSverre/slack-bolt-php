<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

use Thunder\Bolt\BlockKit\Internal\BlockRules;

final readonly class ChartSeries implements \JsonSerializable
{
    /** @param list<ChartPoint> $points */
    private function __construct(
        public string $name,
        public array $points,
    ) {}

    public static function make(string $name, ChartPoint ...$points): self
    {
        BlockRules::text($name, 20, 'Chart series name');
        if ($points === [] || count($points) > 20) {
            throw new \InvalidArgumentException('A chart series needs between 1 and 20 points.');
        }
        $labels = array_map(static fn(ChartPoint $point): string => $point->label, $points);
        if (count(array_unique($labels)) !== count($labels)) {
            throw new \InvalidArgumentException('Chart series point labels must be unique.');
        }
        return new self($name, array_values($points));
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'data' => array_map(static fn(ChartPoint $point): array => $point->toArray(), $this->points),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
