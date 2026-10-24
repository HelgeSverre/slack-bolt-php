<?php

declare(strict_types=1);

namespace App\Demo\Ai;

final readonly class AgentResult
{
    /** @param list<array> $history */
    public function __construct(
        public string $text,
        public array $history,
        public ?string $error = null,
        public array $citations = [],
    ) {}

    public function succeeded(): bool
    {
        return $this->error === null;
    }
}
