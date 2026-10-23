<?php

declare(strict_types=1);

namespace App\Demo\Terminal;

final readonly class TerminalSnapshot
{
    public function __construct(
        public string $sessionId,
        public string $output,
        public string $status,
        public bool $changed = true,
    ) {}
}
