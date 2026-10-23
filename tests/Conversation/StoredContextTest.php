<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Conversation;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Thunder\Bolt\Conversation\ConversationContext;
use Thunder\Bolt\Conversation\ConversationKey;
use Thunder\Bolt\Conversation\ConversationStore;
use Thunder\Bolt\Conversation\Exceptions\ConversationNotFound;
use Thunder\Bolt\Conversation\Exceptions\ConversationStorageException;
use Thunder\Bolt\Conversation\Stores\MemoryConversationStore;

final class StoredContextTest extends TestCase
{
    public function test_null_values_are_present_and_snapshots_change_only_after_explicit_writes(): void
    {
        $store = new MemoryConversationStore();
        $key = new ConversationKey('C1', 'T1');
        $context = new ConversationContext($store, $key);
        self::assertFalse($context->exists());
        $context->replace(null);
        self::assertTrue($context->exists());
        self::assertNull($context->value('fallback'));
        $other = new ConversationContext($store, $key);
        $other->replace(['step' => 'next']);
        self::assertNull($context->value());
        $context->update(static fn($current) => [...$current, 'count' => 1]);
        self::assertSame(['step' => 'next', 'count' => 1], $context->value());
        $context->delete();
        self::assertFalse($context->exists());
    }

    public function test_native_datetime_expiry_is_converted_to_absolute_milliseconds(): void
    {
        $store = $this->createMock(ConversationStore::class);
        $key = new ConversationKey('C1', 'T1');
        $store->method('get')->willThrowException(new ConversationNotFound('Missing'));
        $store->expects(self::once())->method('set')->with($key, ['step' => 1], 1_700_000_000_123);
        $context = new ConversationContext($store, $key);
        $context->replace(['step' => 1], new \DateTimeImmutable('2023-11-14T22:13:20.123Z'));
    }

    public function test_load_failure_logs_and_continues_but_write_failure_propagates(): void
    {
        $store = $this->createStub(ConversationStore::class);
        $store->method('get')->willThrowException(new ConversationStorageException('Backend unavailable'));
        $store->method('set')->willThrowException(new ConversationStorageException('Write unavailable'));
        $logger = new class extends AbstractLogger {
            /** @var list<array{mixed, string}> */
            public array $entries = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->entries[] = [$level, (string) $message];
            }
        };
        $context = new ConversationContext($store, new ConversationKey('C1', 'T1'), $logger);
        self::assertFalse($context->exists());
        self::assertSame('debug', $logger->entries[0][0]);
        $this->expectException(ConversationStorageException::class);
        $context->replace(['attempt' => 'persist']);
    }

    public function test_update_callbacks_cannot_recursively_write_the_same_facade(): void
    {
        $context = new ConversationContext(new MemoryConversationStore(), new ConversationKey('C1', 'T1'));
        try {
            $context->update(static fn() => $context->replace('nested'));
            self::fail('Recursive write must fail.');
        } catch (\LogicException) {
            self::assertFalse($context->exists());
        }
        $context->replace('still usable');
        self::assertSame('still usable', $context->value());
    }
}
