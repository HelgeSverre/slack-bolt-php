<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

final readonly class TableRow implements \JsonSerializable
{
    /** @param list<TableCell> $cells */
    private function __construct(
        private array $cells,
    ) {}

    public static function make(TableCell ...$cells): self
    {
        if ($cells === [] || count($cells) > 20) {
            throw new \InvalidArgumentException('A table row needs between 1 and 20 cells.');
        }

        return new self(array_values($cells));
    }

    /** @return list<array<array-key, mixed>> */
    public function toArray(): array
    {
        return array_map(static fn(TableCell $cell): array => $cell->toArray(), $this->cells);
    }

    /** @return list<array<array-key, mixed>> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
