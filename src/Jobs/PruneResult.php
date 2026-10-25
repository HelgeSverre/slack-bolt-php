<?php

declare(strict_types=1);

namespace Thunder\Bolt\Jobs;

final readonly class PruneResult implements \JsonSerializable
{
    public function __construct(
        public int $before,
        public bool $dryRun,
        public int $eligible,
        public int $historyEntries,
        public int $limit,
        public bool $moreAvailable,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'before' => gmdate(DATE_ATOM, $this->before),
            'dry_run' => $this->dryRun,
            'eligible' => $this->eligible,
            'pruned' => $this->dryRun ? 0 : $this->eligible,
            'history_entries' => $this->historyEntries,
            'limit' => $this->limit,
            'more_available' => $this->moreAvailable,
            'deduplication' => 'retained',
        ];
    }
}
