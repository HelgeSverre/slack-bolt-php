<?php

declare(strict_types=1);

namespace Thunder\Bolt\WorkObjects;

final readonly class ExternalReference implements \JsonSerializable
{
    public function __construct(
        public string $id,
        public ?string $type = null,
    ) {
        Guard::text($id, 255);
        if ($type !== null) {
            Guard::text($type, 255);
        }
    }

    public static function fromArray(array $data): self
    {
        if (!is_string($data['id'] ?? null) || isset($data['type']) && !is_string($data['type'])) {
            throw new \InvalidArgumentException('A Work Object reference needs a string ID and optional type.');
        }
        return new self($data['id'], $data['type'] ?? null);
    }

    public function key(): string
    {
        return hash('sha256', json_encode([$this->type, $this->id], JSON_THROW_ON_ERROR));
    }

    public function toArray(): array
    {
        return array_filter(['id' => $this->id, 'type' => $this->type], static fn($value): bool => $value !== null);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
