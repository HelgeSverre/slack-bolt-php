<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

final readonly class Chart implements \JsonSerializable
{
    /** @param array<string, mixed> $data */
    private function __construct(
        private array $data,
    ) {}

    public static function pie(ChartPoint ...$segments): self
    {
        if (
            $segments === []
            || count($segments) > 12
            || array_any($segments, static fn(ChartPoint $point): bool => $point->value <= 0)
        ) {
            throw new \InvalidArgumentException('A pie chart needs between 1 and 12 positive segments.');
        }
        return new self([
            'type' => 'pie',
            'segments' => array_map(static fn(ChartPoint $point): array => $point->toArray(), array_values($segments)),
        ]);
    }

    public static function bar(ChartAxis $axis, ChartSeries ...$series): self
    {
        return self::cartesian('bar', $axis, array_values($series));
    }

    public static function area(ChartAxis $axis, ChartSeries ...$series): self
    {
        return self::cartesian('area', $axis, array_values($series));
    }

    public static function line(ChartAxis $axis, ChartSeries ...$series): self
    {
        return self::cartesian('line', $axis, array_values($series));
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** @param list<ChartSeries> $series */
    private static function cartesian(string $type, ChartAxis $axis, array $series): self
    {
        if ($series === [] || count($series) > 12) {
            throw new \InvalidArgumentException('A chart needs between 1 and 12 series.');
        }
        $names = [];
        foreach ($series as $item) {
            if (in_array($item->name, $names, true)) {
                throw new \InvalidArgumentException('Chart series names must be unique.');
            }
            $names[] = $item->name;
            $labels = array_map(static fn(ChartPoint $point): string => $point->label, $item->points);
            if (count($labels) !== count($axis->labels) || array_diff($axis->labels, $labels) !== []) {
                throw new \InvalidArgumentException(
                    'Every series must contain exactly one point for each axis category.',
                );
            }
        }
        return new self([
            'type' => $type,
            'series' => array_map(static fn(ChartSeries $item): array => $item->toArray(), $series),
            'axis_config' => $axis->toArray(),
        ]);
    }
}
