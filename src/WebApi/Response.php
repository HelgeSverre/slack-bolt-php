<?php

declare(strict_types=1);

namespace Thunder\Bolt\WebApi;

readonly class Response
{
    public function __construct(
        public array $data,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }
}
