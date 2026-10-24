<?php

declare(strict_types=1);

namespace App\Demo\Ai;

final readonly class AgentEvent
{
    private function __construct(
        public string $type,
        public string $text = '',
        public string $id = '',
        public string $title = '',
        public string $status = '',
    ) {}

    public static function text(string $delta): self
    {
        return new self('text', text: $delta);
    }

    public static function task(string $id, string $title, string $status): self
    {
        return new self('task', id: $id, title: $title, status: $status);
    }
}
