<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

final readonly class Table implements Block
{
    /** @param list<TableRow> $rows @param list<TableColumn> $columns */
    private function __construct(
        private array $rows,
        private array $columns = [],
    ) {}

    public static function make(TableRow ...$rows): self
    {
        if ($rows === [] || count($rows) > 100) {
            throw new \InvalidArgumentException('A table needs between 1 and 100 rows.');
        }

        return new self(array_values($rows));
    }

    #[\NoDiscard]
    public function columns(TableColumn ...$columns): self
    {
        if (count($columns) > 20) {
            throw new \InvalidArgumentException('A table supports at most 20 column settings.');
        }

        return clone($this, ['columns' => $columns]);
    }

    public function toArray(): array
    {
        $data = [
            'type' => 'table',
            'rows' => array_map(static fn(TableRow $row): array => $row->toArray(), $this->rows),
        ];
        if ($this->columns !== []) {
            $data['column_settings'] = array_map(
                static fn(TableColumn $column): array => $column->toArray(),
                $this->columns,
            );
        }

        return $data;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
