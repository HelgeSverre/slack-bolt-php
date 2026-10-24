<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Assistant;

use PDO;
use PHPUnit\Framework\TestCase;
use Thunder\Bolt\Assistant\Stores\StoredThreadContextStore;
use Thunder\Bolt\Assistant\ThreadContext;
use Thunder\Bolt\Assistant\ThreadIdentity;
use Thunder\Bolt\Conversation\ConversationKey;
use Thunder\Bolt\Conversation\ConversationStore;
use Thunder\Bolt\Conversation\Exceptions\ConversationNotFound;
use Thunder\Bolt\Conversation\Stores\FileConversationStore;
use Thunder\Bolt\Conversation\Stores\MemoryConversationStore;
use Thunder\Bolt\Conversation\Stores\SqliteConversationStore;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;

final class ContextStoresTest extends TestCase
{
    public function test_empty_focus_context_can_be_saved_and_loaded(): void
    {
        foreach ($this->stores() as $store) {
            $client = new Client(new RecordingHttpClient(), 'test');
            $adapter = new StoredThreadContextStore($store);
            $identity = $this->identity('100.001');
            $adapter->save($identity, new ThreadContext(), $client);
            self::assertSame([], $adapter->get($identity, $client)?->toArray());
        }
    }

    public function test_context_keeps_unknown_focus_fields_and_partitions_by_thread(): void
    {
        $context = ThreadContext::fromArray([
            'channel_id' => 'C1',
            'team_id' => 'T1',
            'enterprise_id' => null,
            'focus' => ['document' => 'D1'],
        ]);
        self::assertSame('D1', $context->toArray()['focus']['document']);

        foreach ($this->stores() as $store) {
            $adapter = new StoredThreadContextStore($store);
            $client = new Client(new RecordingHttpClient(), 'xoxb-test');
            $first = $this->identity('100.001');
            $other = $this->identity('200.002');
            $adapter->save($first, $context, $client);

            self::assertSame('C1', $adapter->get($first, $client)?->channelId);
            self::assertNull($adapter->get($other, $client));
            try {
                $store->get($first->key);
                self::fail('Assistant state must not share the ordinary conversation key.');
            } catch (ConversationNotFound) {
                // Expected: assistant state has a dedicated namespace.
            }
        }
    }

    public function test_file_and_sqlite_contexts_survive_a_fresh_adapter(): void
    {
        $directory = sys_get_temp_dir() . '/thunder-bolt-assistant-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        try {
            $stores = [
                fn(): ConversationStore => new FileConversationStore($directory . '/records'),
                fn(): ConversationStore => new SqliteConversationStore(
                    new PDO('sqlite:' . $directory . '/assistant.sqlite'),
                ),
            ];
            foreach ($stores as $makeStore) {
                $client = new Client(new RecordingHttpClient(), 'xoxb-test');
                $identity = $this->identity('300.003');
                new StoredThreadContextStore($makeStore())->save($identity, new ThreadContext('C1', 'T1'), $client);
                self::assertSame('T1', new StoredThreadContextStore($makeStore())->get($identity, $client)?->teamId);
            }
        } finally {
            $this->removeDirectory($directory);
        }
    }

    /** @return list<ConversationStore> */
    private function stores(): array
    {
        return [
            new MemoryConversationStore(),
            new SqliteConversationStore(new PDO('sqlite::memory:')),
        ];
    }

    private function identity(string $root): ThreadIdentity
    {
        return new ThreadIdentity(new ConversationKey('C1', 'T1', threadTs: $root), 'U1', 'U-bot');
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($directory);
    }
}
