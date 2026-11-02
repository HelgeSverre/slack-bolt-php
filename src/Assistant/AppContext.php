<?php

declare(strict_types=1);

namespace Thunder\Bolt\Assistant;

use Thunder\Bolt\Exceptions\InvalidPayload;

/** The user's current focus, ordered by relevance. This is a hint, not authorization. */
final readonly class AppContext
{
    /** @param list<ContextEntity> $entities */
    private function __construct(
        public array $entities,
        public array $raw,
    ) {}

    public static function fromArray(array $body): self
    {
        if ($body !== [] && array_is_list($body)) {
            throw new InvalidPayload('App context must be an object.');
        }
        $entities = $body['entities'] ?? [];
        if (!is_array($entities) || !array_is_list($entities)) {
            throw new InvalidPayload('App context entities must be a list.');
        }
        return new self(array_map(static function (mixed $entity): ContextEntity {
            if (!is_array($entity)) {
                throw new InvalidPayload('App context entities must be objects.');
            }
            return ContextEntity::fromArray($entity);
        }, $entities), $body);
    }

    public function first(?string $type = null): ?ContextEntity
    {
        foreach ($this->entities as $entity) {
            if ($type === null || $entity->type === $type) {
                return $entity;
            }
        }
        return null;
    }

    public function toArray(): array
    {
        return $this->raw;
    }
}
