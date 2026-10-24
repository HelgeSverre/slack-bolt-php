<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Tempest\Container\GenericContainer;
use Thunder\Bolt\App;
use Thunder\Bolt\Authorization\Authorization;
use Thunder\Bolt\Authorization\AuthorizationSource;
use Thunder\Bolt\BoltConfig;
use Thunder\Bolt\Conversation\ConversationContext;
use Thunder\Bolt\Conversation\ConversationStore;
use Thunder\Bolt\Conversation\Exceptions\MissingConversationContext;
use Thunder\Bolt\Conversation\Stores\MemoryConversationStore;
use Thunder\Bolt\Conversation\ThreadContext;
use Thunder\Bolt\Invocation\Delivery;
use Thunder\Bolt\Responses\ShortcutResponse;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;

final class ConversationDispatchTest extends TestCase
{
    public function test_context_loads_before_global_middleware_and_is_shared_by_listeners_but_scoped_to_deliveries(): void
    {
        $app = $this->app(new MemoryConversationStore());
        $app->authorizeUsing(static fn(AuthorizationSource $source): Authorization => new Authorization(
            botToken: 'token',
            teamId: $source->teamId,
        ));
        $contexts = [];
        $observed = [];
        $app->use(static function (Delivery $delivery, \Closure $next) use (&$contexts): mixed {
            $contexts[] = $delivery->conversation;

            return $next();
        });
        $app->event('app_mention', static function (ConversationContext $conversation, ThreadContext $thread): void {
            $conversation->update(static fn($value) => ($value ?? 0) + 1);
            $thread->update(static fn($value) => ($value ?? 0) + 1);
        });
        $app->event('app_mention', static function (
            ConversationContext $conversation,
            ThreadContext $thread,
            Delivery $delivery,
        ) use (&$observed, &$contexts): void {
            self::assertSame(end($contexts), $conversation);
            self::assertSame($delivery->thread, $thread);
            $observed[] = [$conversation->value(), $thread->value()];
        });

        $app->dispatch($this->mention('T1', '100.001', '100.001'));
        $app->dispatch($this->mention('T1', '100.002', '100.001'));
        $app->dispatch($this->mention('T1', '200.001', '200.001'));
        $app->dispatch($this->mention('T2', '100.001', '100.001'));

        self::assertSame([[1, 1], [2, 2], [3, 1], [1, 1]], $observed);
        self::assertCount(4, array_unique(array_map(spl_object_id(...), $contexts)));
        self::assertNotSame($contexts[0], $contexts[1]);
    }

    public function test_state_survives_a_new_app_when_an_adapter_is_shared(): void
    {
        $store = new MemoryConversationStore();
        $this
            ->app($store)
            ->event('app_mention', static fn(ConversationContext $context) => $context->replace([
                'step' => 'awaiting_email',
            ]))
            ->dispatch($this->mention());
        $state = null;
        $this
            ->app($store)
            ->event('app_mention', static function (ConversationContext $context) use (&$state): void {
                $state = $context->value();
            })
            ->dispatch($this->mention());
        self::assertSame(['step' => 'awaiting_email'], $state);
    }

    public function test_global_shortcuts_have_no_fabricated_context_and_never_write_storage(): void
    {
        $app = $this->app(new MemoryConversationStore());
        $app->shortcut('launch', static function (
            ConversationContext $conversation,
            ThreadContext $thread,
        ): ShortcutResponse {
            self::assertFalse($conversation->isAvailable());
            self::assertFalse($thread->isAvailable());
            self::assertFalse($conversation->exists());
            self::assertSame('default', $conversation->value('default'));
            try {
                $conversation->replace(['cannot' => 'write']);
                self::fail('Expected missing context.');
            } catch (MissingConversationContext) {
                return ShortcutResponse::empty();
            }
        });
        self::assertInstanceOf(ShortcutResponse::class, $app->dispatch([
            'type' => 'shortcut',
            'callback_id' => 'launch',
        ]));
    }

    public function test_false_disables_both_contexts_without_inventing_state(): void
    {
        $app = $this->app(false);
        $app->event('app_mention', static function (ConversationContext $conversation, ThreadContext $thread): void {
            self::assertFalse($conversation->isAvailable());
            self::assertFalse($thread->isAvailable());
        });
        $app->dispatch($this->mention());
    }

    public function test_ignored_self_events_do_not_load_conversation_storage(): void
    {
        $store = $this->createMock(ConversationStore::class);
        $store->expects(self::never())->method('get');
        $config = new BoltConfig('token', botUserId: 'U1');
        $app = new App(
            new GenericContainer(),
            new Client(new RecordingHttpClient(), 'token'),
            $config,
            conversationStore: $store,
        );
        $app->event('app_mention', static fn() => self::fail('Self event must be ignored'));
        self::assertNull($app->dispatch($this->mention()));
    }

    public function test_own_bot_messages_are_ignored_before_storage_even_without_a_bot_user_id(): void
    {
        $store = $this->createMock(ConversationStore::class);
        $store->expects(self::never())->method('get');
        $app = $this->app($store)->authorizeUsing(static fn() => new Authorization(botToken: 'token', botId: 'B1'));
        $app->event('message', static fn() => self::fail('Own bot message must be ignored'));
        self::assertNull($app->dispatch([
            'type' => 'event_callback',
            'team_id' => 'T1',
            'event' => [
                'type' => 'message',
                'subtype' => 'bot_message',
                'bot_id' => 'B1',
                'channel' => 'C1',
                'ts' => '100.001',
            ],
        ]));
    }

    public function test_own_membership_events_keep_their_conversation_context(): void
    {
        $calls = [];
        $app = $this->app(new MemoryConversationStore())->authorizeUsing(static fn() => new Authorization(
            botToken: 'token',
            botUserId: 'U1',
        ));
        foreach (['member_joined_channel', 'member_left_channel'] as $type) {
            $app->event($type, static function (ConversationContext $conversation) use (&$calls, $type): void {
                self::assertTrue($conversation->isAvailable());
                $calls[] = $type;
            });
            $app->dispatch([
                'type' => 'event_callback',
                'team_id' => 'T1',
                'event' => [
                    'type' => $type,
                    'channel' => 'C1',
                    'channel_type' => 'C',
                    'team' => 'T1',
                    'user' => 'U1',
                ],
            ]);
        }
        self::assertSame(['member_joined_channel', 'member_left_channel'], $calls);
    }

    private function app(ConversationStore|false $store): App
    {
        return new App(
            new GenericContainer(),
            new Client(new RecordingHttpClient(), 'token'),
            new BoltConfig('token'),
            conversationStore: $store,
        );
    }

    private function mention(string $team = 'T1', string $timestamp = '100.001', ?string $root = null): array
    {
        return [
            'type' => 'event_callback',
            'team_id' => $team,
            'event' => [
                'type' => 'app_mention',
                'channel' => 'C1',
                'user' => 'U1',
                'ts' => $timestamp,
                'thread_ts' => $root,
            ],
        ];
    }
}
