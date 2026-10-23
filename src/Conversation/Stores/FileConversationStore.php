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
use Thunder\Bolt\Conversation\Exceptions\ConversationStorageException;
use Thunder\Bolt\OAuth\SystemClock;

final class FileConversationStore implements ConversationStore
{
    /** @var array<string, true> */
    private array $updating = [];

    public function __construct(
        private readonly string $directory,
        private readonly ?ClockInterface $clock = null,
    ) {
        if ($directory === '') {
            throw new \InvalidArgumentException('A conversation store needs a directory.');
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new ConversationStorageException('Unable to create the conversation store directory.');
        }
        if (!is_readable($directory) || !is_writable($directory)) {
            throw new ConversationStorageException('The conversation store directory must be readable and writable.');
        }
    }

    public function get(ConversationKey $key): ConversationRecord
    {
        $this->assertNotUpdating($key);

        return $this->locked($key, function () use ($key): ConversationRecord {
            $record = $this->read($key);
            if ($record === null) {
                throw new ConversationNotFound('Conversation state was not found.');
            }
            if ($this->expired($record['expiresAtMilliseconds'])) {
                $this->remove($key);
                throw new ConversationExpired('Conversation state has expired.');
            }

            return new ConversationRecord(JsonValue::decode($record['value']), $record['expiresAtMilliseconds']);
        });
    }

    public function set(ConversationKey $key, mixed $value, ?int $expiresAtMilliseconds = null): void
    {
        $this->assertNotUpdating($key);
        $encoded = JsonValue::encode($value);
        $this->locked($key, fn(): null => $this->write($key, $encoded, $expiresAtMilliseconds));
    }

    public function delete(ConversationKey $key): void
    {
        $this->assertNotUpdating($key);
        $this->locked($key, fn(): null => $this->remove($key));
    }

    public function update(
        ConversationKey $key,
        Closure $updater,
        ?int $expiresAtMilliseconds = null,
    ): ConversationRecord {
        $this->assertNotUpdating($key);
        $this->updating[$key->key()] = true;
        try {
            return $this->locked($key, function () use ($key, $updater, $expiresAtMilliseconds): ConversationRecord {
                $record = $this->read($key);
                $current = null;
                if ($record !== null && !$this->expired($record['expiresAtMilliseconds'])) {
                    $current = JsonValue::decode($record['value']);
                } elseif ($record !== null) {
                    $this->remove($key);
                }
                $value = $updater($current);
                $encoded = JsonValue::encode($value);
                $this->write($key, $encoded, $expiresAtMilliseconds);

                return new ConversationRecord(JsonValue::decode($encoded), $expiresAtMilliseconds);
            });
        } finally {
            unset($this->updating[$key->key()]);
        }
    }

    /** @return array{value:string, expiresAtMilliseconds:?int}|null */
    private function read(ConversationKey $key): ?array
    {
        $path = $this->recordPath($key);
        if (!is_file($path)) {
            return null;
        }
        $json = file_get_contents($path);
        if ($json === false) {
            throw new ConversationStorageException('Unable to read the conversation record.');
        }
        try {
            $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ConversationStorageException('The conversation record is invalid JSON.', previous: $exception);
        }
        if (
            !is_array($data)
            || !array_key_exists('value', $data)
            || !array_key_exists('expiresAtMilliseconds', $data)
            || !is_string($data['value'])
            || !is_int($data['expiresAtMilliseconds']) && $data['expiresAtMilliseconds'] !== null
        ) {
            throw new ConversationStorageException('The conversation record has an invalid shape.');
        }

        return ['value' => $data['value'], 'expiresAtMilliseconds' => $data['expiresAtMilliseconds']];
    }

    private function write(ConversationKey $key, string $value, ?int $expiresAtMilliseconds): void
    {
        $temporary = tempnam($this->directory, '.conversation-');
        if ($temporary === false) {
            throw new ConversationStorageException('Unable to create a temporary conversation record.');
        }
        try {
            $json = json_encode([
                'value' => $value,
                'expiresAtMilliseconds' => $expiresAtMilliseconds,
            ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
            if (
                !chmod($temporary, 0600)
                || file_put_contents($temporary, $json, LOCK_EX) === false
                || !rename($temporary, $this->recordPath($key))
            ) {
                throw new ConversationStorageException('Unable to persist the conversation record.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function remove(ConversationKey $key): void
    {
        $path = $this->recordPath($key);
        if (is_file($path) && !unlink($path)) {
            throw new ConversationStorageException('Unable to delete the conversation record.');
        }
    }

    private function recordPath(ConversationKey $key): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $key->key() . '.json';
    }

    private function lockPath(ConversationKey $key): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $key->key() . '.lock';
    }

    /**
     * @template T
     * @param Closure(): T $operation
     * @return T
     */
    private function locked(ConversationKey $key, Closure $operation): mixed
    {
        $handle = fopen($this->lockPath($key), 'c');
        if ($handle === false) {
            throw new ConversationStorageException('Unable to open the conversation lock.');
        }
        try {
            if (!chmod($this->lockPath($key), 0600) || !flock($handle, LOCK_EX)) {
                throw new ConversationStorageException('Unable to lock the conversation record.');
            }

            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
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

    private function assertNotUpdating(ConversationKey $key): void
    {
        if (isset($this->updating[$key->key()])) {
            throw new \LogicException('Conversation update callbacks must not recursively access the same key.');
        }
    }
}
