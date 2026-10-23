<?php

declare(strict_types=1);

namespace Thunder\Bolt\Conversation\Stores;

use Closure;
use PDO;
use PDOException;
use Psr\Clock\ClockInterface;
use Thunder\Bolt\Conversation\ConversationKey;
use Thunder\Bolt\Conversation\ConversationRecord;
use Thunder\Bolt\Conversation\ConversationStore;
use Thunder\Bolt\Conversation\Exceptions\ConversationExpired;
use Thunder\Bolt\Conversation\Exceptions\ConversationNotFound;
use Thunder\Bolt\Conversation\Exceptions\ConversationStorageException;
use Thunder\Bolt\OAuth\SystemClock;

/** Durable SQLite implementation with a per-update write transaction. */
final class SqliteConversationStore implements ConversationStore
{
    /** @var array<string, true> */
    private array $updating = [];

    public function __construct(
        private readonly PDO $connection,
        private readonly ?ClockInterface $clock = null,
    ) {
        $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->connection->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS thunder_bolt_conversations (
                conversation_key TEXT PRIMARY KEY,
                value_json TEXT NOT NULL,
                expires_at_milliseconds INTEGER NULL
            )
            SQL);
    }

    public function get(ConversationKey $key): ConversationRecord
    {
        $this->assertNotUpdating($key);
        $record = $this->read($key);
        if ($record === null) {
            throw new ConversationNotFound('Conversation state was not found.');
        }
        if ($this->expired($record['expiresAtMilliseconds'])) {
            $this->removeExpired($key, $this->now());
            throw new ConversationExpired('Conversation state has expired.');
        }

        return new ConversationRecord(JsonValue::decode($record['value']), $record['expiresAtMilliseconds']);
    }

    public function set(ConversationKey $key, mixed $value, ?int $expiresAtMilliseconds = null): void
    {
        $this->assertNotUpdating($key);
        $this->write($key, JsonValue::encode($value), $expiresAtMilliseconds);
    }

    public function delete(ConversationKey $key): void
    {
        $this->assertNotUpdating($key);
        $this->remove($key);
    }

    public function update(
        ConversationKey $key,
        Closure $updater,
        ?int $expiresAtMilliseconds = null,
    ): ConversationRecord {
        $this->assertNotUpdating($key);
        if ($this->connection->inTransaction()) {
            throw new ConversationStorageException(
                'Conversation updates cannot run inside another database transaction.',
            );
        }

        $this->updating[$key->key()] = true;
        $transactionStarted = false;
        try {
            $this->connection->exec('BEGIN IMMEDIATE');
            $transactionStarted = true;

            $stored = $this->read($key);
            $current = null;
            if ($stored !== null && !$this->expired($stored['expiresAtMilliseconds'])) {
                $current = JsonValue::decode($stored['value']);
            } elseif ($stored !== null) {
                $this->remove($key);
            }

            $encoded = JsonValue::encode($updater($current));
            $this->write($key, $encoded, $expiresAtMilliseconds);
            $this->connection->exec('COMMIT');
            $transactionStarted = false;

            return new ConversationRecord(JsonValue::decode($encoded), $expiresAtMilliseconds);
        } catch (PDOException $exception) {
            throw new ConversationStorageException('Unable to update the conversation record.', previous: $exception);
        } finally {
            if ($transactionStarted) {
                try {
                    $this->connection->exec('ROLLBACK');
                } catch (PDOException) {
                    // The original storage failure gives the actionable error.
                }
            }
            unset($this->updating[$key->key()]);
        }
    }

    /** @return array{value:string, expiresAtMilliseconds:?int}|null */
    private function read(ConversationKey $key): ?array
    {
        try {
            $statement = $this->prepare(
                'SELECT value_json, expires_at_milliseconds FROM thunder_bolt_conversations WHERE conversation_key = :key',
            );
            $statement->execute(['key' => $key->key()]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $exception) {
            throw new ConversationStorageException('Unable to read the conversation record.', previous: $exception);
        }
        if ($row === false) {
            return null;
        }
        $value = $row['value_json'] ?? null;
        $expiresAt = $row['expires_at_milliseconds'] ?? null;
        if (!is_string($value) || !is_int($expiresAt) && !ctype_digit((string) $expiresAt) && $expiresAt !== null) {
            throw new ConversationStorageException('The conversation record has an invalid shape.');
        }

        return ['value' => $value, 'expiresAtMilliseconds' => $expiresAt === null ? null : (int) $expiresAt];
    }

    private function write(ConversationKey $key, string $value, ?int $expiresAtMilliseconds): void
    {
        try {
            $statement = $this->prepare(<<<'SQL'
                INSERT INTO thunder_bolt_conversations (conversation_key, value_json, expires_at_milliseconds)
                VALUES (:key, :value, :expires)
                ON CONFLICT(conversation_key) DO UPDATE SET
                    value_json = excluded.value_json,
                    expires_at_milliseconds = excluded.expires_at_milliseconds
                SQL);
            $statement->execute(['key' => $key->key(), 'value' => $value, 'expires' => $expiresAtMilliseconds]);
        } catch (PDOException $exception) {
            throw new ConversationStorageException('Unable to persist the conversation record.', previous: $exception);
        }
    }

    private function remove(ConversationKey $key): void
    {
        try {
            $statement = $this->prepare('DELETE FROM thunder_bolt_conversations WHERE conversation_key = :key');
            $statement->execute(['key' => $key->key()]);
        } catch (PDOException $exception) {
            throw new ConversationStorageException('Unable to delete the conversation record.', previous: $exception);
        }
    }

    /** Remove only a record that remains expired at the observed instant. */
    private function removeExpired(ConversationKey $key, int $now): void
    {
        try {
            $statement = $this->prepare(<<<'SQL'
                DELETE FROM thunder_bolt_conversations
                WHERE conversation_key = :key
                  AND expires_at_milliseconds IS NOT NULL
                  AND expires_at_milliseconds < CAST(:now AS INTEGER)
                SQL);
            $statement->execute(['key' => $key->key(), 'now' => $now]);
        } catch (PDOException $exception) {
            throw new ConversationStorageException(
                'Unable to clean up the expired conversation record.',
                previous: $exception,
            );
        }
    }

    private function prepare(string $sql): \PDOStatement
    {
        return (
            $this->connection->prepare($sql)
            ?: throw new ConversationStorageException('Unable to prepare a conversation statement.')
        );
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

    private function assertNotUpdating(ConversationKey $key): void
    {
        if (isset($this->updating[$key->key()])) {
            throw new \LogicException('Conversation update callbacks must not recursively access the same key.');
        }
    }
}
