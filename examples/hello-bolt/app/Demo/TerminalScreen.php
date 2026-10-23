<?php

declare(strict_types=1);

namespace App\Demo;

final class TerminalScreen
{
    public function __construct(
        public readonly DemoJob $job,
        public readonly string $message,
        public float $updated = 0,
    ) {}
}
