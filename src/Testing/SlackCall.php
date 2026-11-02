<?php

declare(strict_types=1);

namespace Thunder\Bolt\Testing;

final readonly class SlackCall
{
    public function __construct(
        public string $method,
        public array $arguments,
    ) {}

    public function __debugInfo(): array
    {
        return ['method' => $this->method];
    }
}
