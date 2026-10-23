<?php

declare(strict_types=1);

namespace Thunder\Bolt\WebApi;

/** A typed nested Web API value that retains its complete decoded Slack shape. */
readonly class ResponseObject
{
    public function __construct(
        public array $data,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }
}
