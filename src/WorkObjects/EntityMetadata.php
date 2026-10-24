<?php

declare(strict_types=1);

namespace Thunder\Bolt\WorkObjects;

final readonly class EntityMetadata implements \JsonSerializable
{
    private function __construct(
        private array $entities,
    ) {}

    public static function make(Entity ...$entities): self
    {
        if ($entities === []) {
            throw new \InvalidArgumentException('Entity metadata needs at least one entity.');
        }
        return new self(array_values($entities));
    }

    public function toArray(): array
    {
        return ['entities' => array_map(static fn(Entity $entity): array => $entity->toArray(), $this->entities)];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
