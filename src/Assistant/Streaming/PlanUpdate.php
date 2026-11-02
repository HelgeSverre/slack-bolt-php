<?php

declare(strict_types=1);

namespace Thunder\Bolt\Assistant\Streaming;

final readonly class PlanUpdate implements Chunk
{
    private function __construct(
        public string $title,
    ) {}

    public static function make(string $title): self
    {
        if (trim($title) === '') {
            throw new \InvalidArgumentException('A plan update needs a title.');
        }
        return new self($title);
    }

    public function toArray(): array
    {
        return ['type' => 'plan_update', 'title' => $this->title];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
