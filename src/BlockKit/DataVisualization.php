<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

use Thunder\Bolt\BlockKit\Internal\BlockRules;

final readonly class DataVisualization implements Block
{
    private function __construct(
        private string $title,
        private Chart $chart,
        private ?string $blockId = null,
    ) {}

    public static function make(string $title, Chart $chart): self
    {
        BlockRules::text($title, 50, 'Chart title');
        return new self($title, $chart);
    }

    #[\NoDiscard]
    public function id(string $id): self
    {
        BlockRules::text($id, 255, 'Block ID');
        return clone($this, ['blockId' => $id]);
    }

    public function toArray(): array
    {
        return array_filter(
            [
                'type' => 'data_visualization',
                'title' => $this->title,
                'chart' => $this->chart->toArray(),
                'block_id' => $this->blockId,
            ],
            static fn(mixed $value): bool => $value !== null,
        );
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
