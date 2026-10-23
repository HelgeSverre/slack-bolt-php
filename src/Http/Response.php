<?php

declare(strict_types=1);

namespace Thunder\Bolt\Http;

final readonly class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public int $status,
        public string $body = '',
        public array $headers = ['Content-Type' => 'text/plain; charset=utf-8'],
    ) {}

    /** @param array<string, mixed> $body */
    public static function json(array $body, int $status = 200): self
    {
        return new self($status, json_encode((object) $body, JSON_THROW_ON_ERROR), [
            'Content-Type' => 'application/json; charset=utf-8',
        ]);
    }
}
