<?php

declare(strict_types=1);

namespace Thunder\Bolt\Responses;

final readonly class CommandResponse implements Acknowledgement
{
    /** @param array<string, mixed> $body */
    private function __construct(
        private array $body,
    ) {}

    public static function empty(): self
    {
        return new self([]);
    }

    public static function text(string $text): self
    {
        return new self(['text' => $text]);
    }

    public static function ephemeral(string $text): self
    {
        return new self(['response_type' => 'ephemeral', 'text' => $text]);
    }

    /** @param array<string, mixed> $body */
    public static function fromArray(array $body): self
    {
        return new self($body);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->body;
    }
}
