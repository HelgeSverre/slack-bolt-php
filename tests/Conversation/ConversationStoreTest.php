<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Conversation;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Thunder\Bolt\Conversation\ConversationKey;
use Thunder\Bolt\Conversation\ConversationStore;
use Thunder\Bolt\Conversation\Exceptions\ConversationExpired;
use Thunder\Bolt\Conversation\Exceptions\ConversationNotFound;
use Thunder\Bolt\Conversation\Exceptions\ConversationStorageException;
use Thunder\Bolt\Conversation\Stores\FileConversationStore;
use Thunder\Bolt\Conversation\Stores\JsonValue;
use Thunder\Bolt\Conversation\Stores\MemoryConversationStore;
use Thunder\Bolt\Conversation\Stores\SqliteConversationStore;

final class ConversationStoreTest extends TestCase
{
    public function test_key_is_stable_scoped_and_preserves_slack_timestamp_strings(): void
    {
        $conversation = new ConversationKey('C1', 'T1', 'E1', true);
        $thread = $conversation->thread('1700000000.000001');

        self::assertSame($conversation->key(), new ConversationKey('C1', 'T1', 'E1', true)->key());
        self::assertNotSame($conversation->key(), $thread->key());
        self::assertSame($conversation->key(), new ConversationKey('C1', 'T2', 'E1', true)->key());
        self::assertNotSame($conversation->key(), new ConversationKey('C1', 'T2', 'E2', true)->key());
        self::assertSame(
            new ConversationKey('C1', 'T1')->key(),
            new ConversationKey('C1', 'T1', 'E-incidental')->key(),
        );
        self::assertSame('1700000000.000001', $thread->threadTs);
    }

    public function test_key_rejects_missing_conversation_or_enterprise_identity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ConversationKey('C1', isEnterpriseInstall: true);
    }

    public function test_adapters_round_trip_portable_json_and_isolate_scopes(): void
    {
        $this->forEachStore(function (ConversationStore $store): void {
            $key = new ConversationKey('C1', 'T1');
            $value = ['message' => 'hello', 'number' => 1.0, 'items' => [true, null, ['nested' => 'value']]];
            $store->set($key, $value);

            self::assertSame($value, $store->get($key)->value);
            try {
                $store->get(new ConversationKey('C1', 'T2'));
                self::fail('Tenant-scoped keys must not leak state.');
            } catch (ConversationNotFound) {
                // Expected: the same Slack conversation ID belongs to another tenant.
            }
        });
    }

    public function test_adapters_expire_strictly_after_the_boundary_and_update_from_null(): void
    {
        $clock = new FrozenClock(1_000);
        $this->forEachStore(
            function (ConversationStore $store) use ($clock): void {
                $clock->setMilliseconds(1_000);
                $key = new ConversationKey('C1', 'T1');
                $store->set($key, 'old', 1_000);
                self::assertSame('old', $store->get($key)->value);

                $clock->setMilliseconds(1_001);
                try {
                    $store->get($key);
                    self::fail('An expired conversation should not be returned.');
                } catch (ConversationExpired) {
                    // The first read reports expiry and cleans the record.
                }
                $seen = 'not called';
                $updated = $store->update($key, static function (mixed $current) use (&$seen): array {
                    $seen = $current;

                    return ['fresh' => true];
                });

                self::assertNull($seen);
                self::assertSame(['fresh' => true], $updated->value);
                self::assertNull($updated->expiresAtMilliseconds);
            },
            $clock,
        );
    }

    public function test_adapters_replace_values_during_atomic_update_and_reject_recursive_access(): void
    {
        $this->forEachStore(function (ConversationStore $store): void {
            $key = new ConversationKey('C1', 'T1');
            $store->set($key, ['count' => 1]);
            $updated = $store->update($key, static fn(mixed $current): array => ['count' => $current['count'] + 1]);

            self::assertSame(['count' => 2], $updated->value);
            self::assertSame(['count' => 2], $store->get($key)->value);

            try {
                $store->update($key, static function () use ($store, $key): string {
                    $store->set($key, 'nested');

                    return 'unreachable';
                });
                self::fail('Recursive writes must be rejected before they can deadlock.');
            } catch (\LogicException) {
                // Update callbacks are intentionally pure replacement functions.
            }
        });
    }

    public function test_json_boundary_rejects_nonportable_values_and_detaches_snapshots(): void
    {
        $source = ['nested' => ['value' => 1.0]];
        $copy = JsonValue::copy($source);
        $copy['nested']['value'] = 'changed';
        self::assertSame(['nested' => ['value' => 1.0]], $source);

        foreach ([new \stdClass(), NAN] as $invalid) {
            try {
                JsonValue::copy($invalid);
                self::fail('Only portable JSON values may be copied.');
            } catch (\InvalidArgumentException) {
                // Expected.
            }
        }
    }

    public function test_adapters_reject_recursive_values_without_replacing_existing_state(): void
    {
        $recursive = [];
        $recursive['self'] = &$recursive;

        $this->forEachStore(function (ConversationStore $store) use ($recursive): void {
            $key = new ConversationKey('C1', 'T1');
            $store->set($key, 'kept');
            try {
                $store->set($key, $recursive);
                self::fail('Recursive PHP arrays are not portable JSON.');
            } catch (\InvalidArgumentException) {
                // Validation must finish before the adapter replaces existing state.
            }
            self::assertSame('kept', $store->get($key)->value);
        });
    }

    public function test_file_store_survives_restart_and_reports_corrupt_records(): void
    {
        $directory = $this->temporaryDirectory();
        try {
            $key = new ConversationKey('C1', 'T1');
            new FileConversationStore($directory)->set($key, ['float' => 1.0]);
            self::assertSame(['float' => 1.0], new FileConversationStore($directory)->get($key)->value);

            file_put_contents($directory . DIRECTORY_SEPARATOR . $key->key() . '.json', '{"value":"\\\"saved\\\""}');
            $this->expectException(ConversationStorageException::class);
            new FileConversationStore($directory)->get($key);
        } finally {
            $this->deleteDirectory($directory);
        }
    }

    public function test_sqlite_store_survives_new_adapter_instance(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('The PDO SQLite driver is unavailable.');
        }
        $connection = new PDO('sqlite::memory:');
        $key = new ConversationKey('C1', 'T1');
        new SqliteConversationStore($connection)->set($key, 'saved');

        self::assertSame('saved', new SqliteConversationStore($connection)->get($key)->value);
    }

    /** @param \Closure(ConversationStore): void $assertions */
    private function forEachStore(\Closure $assertions, ?FrozenClock $clock = null): void
    {
        $directory = $this->temporaryDirectory();
        try {
            $stores = [
                new MemoryConversationStore($clock),
                new FileConversationStore($directory, $clock),
            ];
            if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
                $stores[] = new SqliteConversationStore(new PDO('sqlite::memory:'), $clock);
            }
            foreach ($stores as $store) {
                $assertions($store);
            }
        } finally {
            $this->deleteDirectory($directory);
        }
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'thunder-bolt-conversation-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);

        return $directory;
    }

    private function deleteDirectory(string $directory): void
    {
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
}

final class FrozenClock implements ClockInterface
{
    public function __construct(
        private int $milliseconds,
    ) {}

    public function setMilliseconds(int $milliseconds): void
    {
        $this->milliseconds = $milliseconds;
    }

    public function now(): DateTimeImmutable
    {
        $seconds = intdiv($this->milliseconds, 1_000);
        $microseconds = ($this->milliseconds % 1_000) * 1_000;

        return (
            DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%06d', $seconds, $microseconds))
            ?: throw new \LogicException('Unable to construct the test clock time.')
        );
    }
}
