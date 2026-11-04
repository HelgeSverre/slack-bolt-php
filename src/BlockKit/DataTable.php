<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

use Thunder\Bolt\BlockKit\Internal\BlockRules;

final readonly class DataTable implements Block
{
    /** @param list<DataTableRow> $rows */
    private function __construct(
        private string $caption,
        private array $rows,
        private ?int $perPage = null,
        private ?int $rowHeaderIndex = null,
        private ?string $blockId = null,
    ) {}

    public static function make(string $caption, DataTableRow $header, DataTableRow ...$rows): self
    {
        if ($caption === '' || $rows === [] || count($rows) > 200) {
            throw new \InvalidArgumentException(
                'A data table needs a caption, a header and between 1 and 200 data rows.',
            );
        }
        $columns = count($header->toArray());
        foreach ($header->toArray() as $cell) {
            if (($cell['type'] ?? null) === 'rich_text') {
                throw new \InvalidArgumentException('Data table headers cannot contain rich text cells.');
            }
        }
        $characters = 0;
        foreach ([$header, ...$rows] as $row) {
            if (count($row->toArray()) !== $columns) {
                throw new \InvalidArgumentException('All data table rows must have the same number of cells.');
            }
            foreach ($row->toArray() as $cell) {
                $characters += BlockRules::cellCharacters($cell);
            }
        }
        if ($characters > 20000) {
            throw new \InvalidArgumentException('A data table accepts at most 20000 cell characters.');
        }
        return new self($caption, [$header, ...array_values($rows)]);
    }

    #[\NoDiscard]
    public function pageSize(int $size): self
    {
        if ($size < 1 || $size > 100) {
            throw new \InvalidArgumentException('A data table page size must be between 1 and 100.');
        }
        return clone($this, ['perPage' => $size]);
    }

    #[\NoDiscard]
    public function rowHeaderColumn(int $index): self
    {
        if ($index < 0 || $index >= count($this->rows[0]->toArray())) {
            throw new \InvalidArgumentException('The row-header column index must identify a table column.');
        }
        return clone($this, ['rowHeaderIndex' => $index]);
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
                'type' => 'data_table',
                'caption' => $this->caption,
                'rows' => array_map(static fn(DataTableRow $row): array => $row->toArray(), $this->rows),
                'page_size' => $this->perPage,
                'row_header_column_index' => $this->rowHeaderIndex,
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
