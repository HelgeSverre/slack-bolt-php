<?php

declare(strict_types=1);

namespace Thunder\Bolt\Conversation;

use Closure;
use DateTimeInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Thunder\Bolt\Conversation\Exceptions\ConversationNotFound;
use Thunder\Bolt\Conversation\Exceptions\MissingConversationContext;
use Thunder\Bolt\Conversation\Stores\JsonValue;

/** @internal Shared implementation for the two delivery-scoped context capabilities. */
abstract class StoredContext
{
    private ?ConversationRecord $snapshot = null;

    private bool $updating = false;

    public function __construct(
        private readonly ?ConversationStore $store,
        public readonly ?ConversationKey $key,
        ?LoggerInterface $logger = null,
    ) {
        if ($store === null || $key === null) {
            return;
        }
        try {
            $this->snapshot = $store->get($key);
        } catch (ConversationNotFound) {
            // Missing and expired state are ordinary first-delivery outcomes.
        } catch (\Throwable $exception) {
            ($logger ?? new NullLogger())->debug('Conversation context could not be loaded.', [
                'exception' => $exception,
            ]);
        }
    }

    public function isAvailable(): bool
    {
        return $this->store !== null && $this->key !== null;
    }

    public function exists(): bool
    {
        return $this->snapshot !== null;
    }

    public function value(mixed $default = null): mixed
    {
        return $this->snapshot === null ? $default : $this->snapshot->value;
    }

    public function replace(mixed $value, ?DateTimeInterface $expiresAt = null): static
    {
        [$store, $key] = $this->writable();
        $milliseconds = self::milliseconds($expiresAt);
        $store->set($key, $value, $milliseconds);
        $this->snapshot = new ConversationRecord(JsonValue::copy($value), $milliseconds);

        return $this;
    }

    /** @param Closure(mixed): mixed $updater */
    public function update(Closure $updater, ?DateTimeInterface $expiresAt = null): static
    {
        [$store, $key] = $this->writable();
        $this->updating = true;
        try {
            $this->snapshot = $store->update($key, $updater, self::milliseconds($expiresAt));
        } finally {
            $this->updating = false;
        }

        return $this;
    }

    public function delete(): void
    {
        [$store, $key] = $this->writable();
        $store->delete($key);
        $this->snapshot = null;
    }

    /** @return array{ConversationStore,ConversationKey} */
    private function writable(): array
    {
        if ($this->store === null || $this->key === null) {
            throw new MissingConversationContext(
                'This delivery has no available '
                . ($this instanceof ThreadContext ? 'thread' : 'conversation')
                . ' context.',
            );
        }
        if ($this->updating) {
            throw new \LogicException(
                'Context update callbacks must return the new value without recursively writing context.',
            );
        }

        return [$this->store, $this->key];
    }

    private static function milliseconds(?DateTimeInterface $date): ?int
    {
        return $date === null ? null : ($date->getTimestamp() * 1_000) + (int) $date->format('v');
    }
}
