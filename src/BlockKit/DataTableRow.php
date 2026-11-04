<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

final readonly class DataTableRow implements \JsonSerializable
{
    /** @param list<DataTableCell> $cells */
    private function __construct(
        private array $cells,
    ) {}

    public static function make(DataTableCell ...$cells): self
    {
        if ($cells === [] || count($cells) > 20) {
            throw new \InvalidArgumentException('A data table row needs between 1 and 20 cells.');
        }
        return new self(array_values($cells));
    }

    /** @return list<array<string, mixed>> */
    public function toArray(): array
    {
        return array_map(static fn(DataTableCell $cell): array => $cell->toArray(), $this->cells);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
