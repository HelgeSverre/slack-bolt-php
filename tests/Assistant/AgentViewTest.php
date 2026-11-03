<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Assistant;

use PHPUnit\Framework\TestCase;
use Thunder\Bolt\Assistant;
use Thunder\Bolt\Assistant\AppContext;
use Thunder\Bolt\Assistant\ContextEntity;
use Thunder\Bolt\Assistant\Thread;
use Thunder\Bolt\Invocation\Delivery;
use Thunder\Bolt\Payloads\Events\AppContextChanged;
use Thunder\Bolt\Payloads\Events\AppHomeOpened;
use Thunder\Bolt\Payloads\Events\Message;
use Thunder\Bolt\Testing\BoltTest;

final class AgentViewTest extends TestCase
{
    public function test_modern_suggested_prompts_omit_the_legacy_thread_timestamp(): void
    {
        $test = BoltTest::make();
        $test->slack->respondsWith('assistant.threads.setSuggestedPrompts', ['ok' => true]);
        $test->app->assistant(Assistant::forAgentView()->userMessage(static function (Thread $thread): void {
            $thread->setSuggestedPrompts([new \Thunder\Bolt\Assistant\SuggestedPrompt('Explore', 'What can you do?')]);
        }));
        $test->event('message', [
            'channel_type' => 'im',
            'channel' => 'D1',
            'user' => 'U1',
            'ts' => '1.001',
        ])->assertApiCalled(
            'assistant.threads.setSuggestedPrompts',
            static fn(\Thunder\Bolt\Testing\SlackCall $call): bool => !isset($call->arguments['thread_ts']),
        );
    }

    public function test_constructor_injection_does_not_reuse_focus_from_an_earlier_delivery(): void
    {
        $test = BoltTest::make();
        $test->app->assistant(Assistant::forAgentView()->userMessage([FocusReader::class, 'message']));
        foreach (['C1', 'C2'] as $channel) {
            $test->event('message', [
                'channel_type' => 'im',
                'channel' => 'D1',
                'user' => 'U1',
                'ts' => '1.001',
                'app_context' => ['entities' => [['type' => ContextEntity::CHANNEL, 'value' => $channel]]],
            ])->assertMessageSent($channel);
        }
    }

    public function test_middleware_and_error_reporting_keep_the_modern_listener_and_context(): void
    {
        $test = BoltTest::make();
        $seen = null;
        $test
            ->app
            ->assistant(Assistant::forAgentView()->userMessage(static function (
                Delivery $delivery,
                \Closure $next,
            ): mixed {
                $delivery->context->set('local', 'present');
                return $next();
            }, static function (AppContext $context, \Thunder\Bolt\Context $local): never {
                self::assertSame([], $context->entities);
                self::assertSame('present', $local->require('local'));
                throw new \RuntimeException('agent fixture');
            }))
            ->onError(static function (\Thunder\Bolt\Errors\FailureContext $failure) use (
                &$seen,
            ): \Thunder\Bolt\Errors\ErrorHandling {
                $seen = $failure;
                return \Thunder\Bolt\Errors\ErrorHandling::Handled;
            });
        $test->event('message', [
            'channel_type' => 'im',
            'channel' => 'D1',
            'user' => 'U1',
            'ts' => '1.001',
        ])->assertNoApiCalls();
        self::assertSame('agent fixture', $seen->error->getMessage());
        self::assertSame(\Thunder\Bolt\Errors\FailureStage::Listener, $seen->stage);
        self::assertSame('Assistant configuration', $seen->delivery->listener->source);
    }

    public function test_root_and_reply_messages_receive_fresh_focus_without_legacy_metadata_or_network_reads(): void
    {
        $test = BoltTest::make();
        $seen = [];
        $test->app->assistant(Assistant::forAgentView()->userMessage(static function (
            Message $message,
            Thread $thread,
            AppContext $context,
        ) use (&$seen): void {
            $seen[] = [$thread->identity->threadTs(), $context->first()?->channelId()];
            self::assertSame($context, $thread->appContext());
            self::assertNull($thread->context());
            $thread->say('Received');
        }));
        $test
            ->event('message', [
                'channel_type' => 'im',
                'channel' => 'D1',
                'user' => 'U1',
                'ts' => '1.001',
                'text' => 'hello',
                'app_context' => ['entities' => [['type' => ContextEntity::CHANNEL, 'value' => 'C-focus']]],
            ])
            ->assertMessageSent('Received')
            ->assertApiCalled(
                'chat.postMessage',
                static fn(\Thunder\Bolt\Testing\SlackCall $call): bool => !isset($call->arguments['metadata']),
            );
        $test->event('message', [
            'channel_type' => 'im',
            'channel' => 'D1',
            'user' => 'U1',
            'ts' => '2.001',
            'thread_ts' => '1.001',
            'text' => 'next',
        ])->assertThreadReply('Received');
        self::assertSame([['1.001', 'C-focus'], ['1.001', null]], $seen);
    }

    public function test_focus_and_open_events_have_no_invented_thread_and_unrelated_home_events_fall_through(): void
    {
        $test = BoltTest::make();
        $seen = [];
        $test->app->assistant(
            Assistant::forAgentView()
                ->opened(static function (AppHomeOpened $event, AppContext $context, Delivery $delivery) use (
                    &$seen,
                ): void {
                    self::assertNull($delivery->assistant);
                    $seen[] = $event->tab . ':' . count($context->entities);
                })
                ->appContextChanged(static function (
                    AppContextChanged $event,
                    AppContext $context,
                    Delivery $delivery,
                ) use (&$seen): void {
                    self::assertNull($delivery->assistant);
                    self::assertNull($event->userId);
                    $seen[] = 'focus:' . count($context->entities);
                })
                ->userMessage(static fn() => null),
        );
        $test->app->event('app_home_opened', static function () use (&$seen): void {
            $seen[] = 'ordinary';
        });
        $test->event('app_home_opened', ['user' => 'U1', 'tab' => 'messages'])->assertNoApiCalls();
        $test->event('app_context_changed', ['context' => []])->assertNoApiCalls();
        $test->event('app_home_opened', ['user' => 'U1', 'tab' => 'home'])->assertNoApiCalls();
        self::assertSame(['messages:0', 'focus:0', 'ordinary'], $seen);
        self::assertSame('app_home_opened (messages)', $test->app->listeners()[1]['selector']);
    }

    public function test_nonhuman_messages_and_channel_messages_do_not_enter_agent_runtime(): void
    {
        $test = BoltTest::make();
        $test->app->assistant(Assistant::forAgentView()->userMessage(static function (): never {
            self::fail('Unexpected agent message');
        }));
        foreach ([['channel_type' => 'channel'], ['bot_id' => 'B1'], ['subtype' => 'message_changed']] as $extra) {
            $test->event('message', [
                ...['channel_type' => 'im', 'channel' => 'D1', 'user' => 'U1', 'ts' => '1.001'],
                ...$extra,
            ])->assertNoApiCalls();
        }
    }

    public function test_context_preserves_order_unknown_entities_and_extension_fields(): void
    {
        $raw = [
            'entities' => [
                [
                    'type' => ContextEntity::MESSAGE,
                    'value' => ['channel_id' => 'C1', 'message_ts' => '2.001'],
                    'team_id' => 'T-other',
                ],
                ['type' => 'future#/object', 'value' => ['extra' => null]],
                ['type' => ContextEntity::CANVAS, 'value' => 'F1'],
            ],
            'future' => true,
        ];
        $context = AppContext::fromArray($raw);
        self::assertSame($raw, $context->toArray());
        self::assertSame('C1', $context->first()?->channelId());
        self::assertSame('2.001', $context->first()?->messageTs());
        self::assertSame('F1', $context->first(ContextEntity::CANVAS)?->value);
        self::assertNull($context->first(ContextEntity::LIST));
    }

    public function test_malformed_known_entity_fails_before_handlers(): void
    {
        $this->expectException(\Thunder\Bolt\Exceptions\InvalidPayload::class);
        AppContext::fromArray(['entities' => [['type' => ContextEntity::MESSAGE, 'value' => ['channel_id' => 'C1']]]]);
    }

    public function test_legacy_and_agent_configuration_cannot_be_mixed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Assistant::forAgentView()
            ->threadStarted(static fn() => null)
            ->userMessage(static fn() => null)
            ->validate();
    }
}

final readonly class FocusReader
{
    public function __construct(
        private AppContext $context,
    ) {}

    public function message(Thread $thread): void
    {
        $thread->say($this->context->first()?->channelId() ?? 'empty');
    }
}
