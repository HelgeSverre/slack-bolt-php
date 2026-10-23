<?php

declare(strict_types=1);

namespace Thunder\Bolt\Conversation\Stores;

use Closure;
use Psr\Clock\ClockInterface;
use Thunder\Bolt\Conversation\ConversationKey;
use Thunder\Bolt\Conversation\ConversationRecord;
use Thunder\Bolt\Conversation\ConversationStore;
use Thunder\Bolt\Conversation\Exceptions\ConversationExpired;
use Thunder\Bolt\Conversation\Exceptions\ConversationNotFound;
use Thunder\Bolt\OAuth\SystemClock;

final class MemoryConversationStore implements ConversationStore
{
    /** @var array<string, array{value:string, expiresAtMilliseconds:?int}> */
    private array $records = [];

    /** @var array<string, true> */
    private array $updating = [];

    public function __construct(
        private ?ClockInterface $clock = null,
    ) {}

    public function get(ConversationKey $key): ConversationRecord
    {
        $this->assertNotUpdating($key);
        $record = $this->records[$key->key()] ?? null;
        if ($record === null) {
            throw new ConversationNotFound('Conversation state was not found.');
        }
        if ($this->expired($record['expiresAtMilliseconds'])) {
            unset($this->records[$key->key()]);
            throw new ConversationExpired('Conversation state has expired.');
        }

        return new ConversationRecord(JsonValue::decode($record['value']), $record['expiresAtMilliseconds']);
    }

    public function set(ConversationKey $key, mixed $value, ?int $expiresAtMilliseconds = null): void
    {
        $this->assertNotUpdating($key);
        $this->records[$key->key()] = [
            'value' => JsonValue::encode($value),
            'expiresAtMilliseconds' => $expiresAtMilliseconds,
        ];
    }

    public function delete(ConversationKey $key): void
    {
        $this->assertNotUpdating($key);
        unset($this->records[$key->key()]);
    }

    public function update(
        ConversationKey $key,
        Closure $updater,
        ?int $expiresAtMilliseconds = null,
    ): ConversationRecord {
        $this->assertNotUpdating($key);
        $this->updating[$key->key()] = true;
        try {
            try {
                $current = $this->record($key)->value;
            } catch (ConversationNotFound) {
                $current = null;
            }
            $value = $updater($current);
            $encoded = JsonValue::encode($value);
            $this->records[$key->key()] = ['value' => $encoded, 'expiresAtMilliseconds' => $expiresAtMilliseconds];

            return new ConversationRecord(JsonValue::decode($encoded), $expiresAtMilliseconds);
        } finally {
            unset($this->updating[$key->key()]);
        }
    }

    private function record(ConversationKey $key): ConversationRecord
    {
        $record = $this->records[$key->key()] ?? null;
        if ($record === null) {
            throw new ConversationNotFound('Conversation state was not found.');
        }
        if ($this->expired($record['expiresAtMilliseconds'])) {
            unset($this->records[$key->key()]);
            throw new ConversationExpired('Conversation state has expired.');
        }

        return new ConversationRecord(JsonValue::decode($record['value']), $record['expiresAtMilliseconds']);
    }

    private function assertNotUpdating(ConversationKey $key): void
    {
        if (isset($this->updating[$key->key()])) {
            throw new \LogicException('Conversation update callbacks must not recursively access the same key.');
        }
    }

    private function expired(?int $expiresAtMilliseconds): bool
    {
        return $expiresAtMilliseconds !== null && $this->now() > $expiresAtMilliseconds;
    }

    private function now(): int
    {
        $now = ($this->clock ?? new SystemClock())->now();

        return ((int) $now->format('U') * 1_000) + intdiv((int) $now->format('u'), 1_000);
    }
}
