<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

/** Validated extension point for Slack element types not yet modelled as a dedicated builder. */
final readonly class RawElement implements Element
{
    /** @param array<string,mixed> $data */ private function __construct(
        private array $data,
    ) {}

    /** @param array<string,mixed> $data */ public static function fromArray(array $data): self
    {
        if (!is_string($data['type'] ?? null) || $data['type'] === '')
            throw new \InvalidArgumentException('An element needs a non-empty type.');
        return new self($data);
    }

    /** @return array<string,mixed> */ public function toArray(): array
    {
        return $this->data;
    }

    /** @return array<string,mixed> */ public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
