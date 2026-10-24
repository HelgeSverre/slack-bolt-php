<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Assistant;

use PHPUnit\Framework\TestCase;
use Tempest\Container\GenericContainer;
use Thunder\Bolt\App;
use Thunder\Bolt\Assistant;
use Thunder\Bolt\Assistant\SuggestedPrompt;
use Thunder\Bolt\Assistant\Thread;
use Thunder\Bolt\Assistant\ThreadContext;
use Thunder\Bolt\Authorization\Authorization;
use Thunder\Bolt\Authorization\AuthorizationSource;
use Thunder\Bolt\BoltConfig;
use Thunder\Bolt\Conversation\Stores\MemoryConversationStore;
use Thunder\Bolt\Exceptions\HandlerFailures;
use Thunder\Bolt\Exceptions\InvalidPayload;
use Thunder\Bolt\Invocation\Delivery;
use Thunder\Bolt\Payloads\Events\AssistantThreadContextChanged;
use Thunder\Bolt\Payloads\Events\AssistantThreadStarted;
use Thunder\Bolt\Payloads\Events\Message;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;

final class RuntimeTest extends TestCase
{
    public function test_lifecycle_delivery_injects_a_rooted_thread_and_routes_helpers_through_the_selected_installation(): void
    {
        $http = new RecordingHttpClient();
        $seen = [];
        $app = $this
            ->app($http)
            ->authorizeUsing(static fn(AuthorizationSource $source): Authorization => new Authorization(
                botToken: 'install-' . $source->teamId,
                botUserId: 'BOT-' . $source->teamId,
                teamId: $source->teamId,
            ))
            ->assistant(
                Assistant::make()
                    ->threadStarted(static function (
                        AssistantThreadStarted $event,
                        Thread $thread,
                        Delivery $delivery,
                    ) use (&$seen): void {
                        $seen = [
                            $event->context->toArray(),
                            $thread->identity->channelId(),
                            $thread->identity->threadTs(),
                            $delivery->assistant === $thread,
                            $delivery->authorizationSource?->teamId,
                        ];
                        $thread->saveContext();
                        $thread->say('Started');
                        $thread->setStatus('Thinking', ['Reading context']);
                        $thread->setTitle('Support case');
                        $thread->setSuggestedPrompts([new SuggestedPrompt(
                            'Summarize',
                            'Summarize this thread.',
                        )], 'Try this');
                    })
                    ->userMessage(static fn() => null),
            );

        $app->dispatch($this->lifecycle('assistant_thread_started', team: 'T2', channel: 'D2', root: '100.001'));

        self::assertSame(
            [
                ['ticket' => 'CASE-42', 'channel_id' => 'C-focus', 'team_id' => 'T-focus'],
                'D2',
                '100.001',
                true,
                'T2',
            ],
            $seen,
        );
        self::assertSame(
            [
                'text' => 'Started',
                'metadata' => [
                    'event_type' => 'assistant_thread_context',
                    'event_payload' => ['ticket' => 'CASE-42', 'channel_id' => 'C-focus', 'team_id' => 'T-focus'],
                ],
                'channel' => 'D2',
                'thread_ts' => '100.001',
            ],
            $http->sentPayload(0),
        );
        self::assertSame('assistant.threads.setStatus', basename($http->requests[1]->getUri()->getPath()));
        self::assertSame(
            [
                'channel_id' => 'D2',
                'thread_ts' => '100.001',
                'status' => 'Thinking',
                'loading_messages' => ['Reading context'],
            ],
            $http->sentPayload(1),
        );
        self::assertSame('assistant.threads.setTitle', basename($http->requests[2]->getUri()->getPath()));
        self::assertSame(
            ['channel_id' => 'D2', 'thread_ts' => '100.001', 'title' => 'Support case'],
            $http->sentPayload(2),
        );
        self::assertSame('assistant.threads.setSuggestedPrompts', basename($http->requests[3]->getUri()->getPath()));
        self::assertSame(
            [
                'channel_id' => 'D2',
                'thread_ts' => '100.001',
                'prompts' => [['title' => 'Summarize', 'message' => 'Summarize this thread.']],
                'title' => 'Try this',
            ],
            $http->sentPayload(3),
        );
        self::assertSame(
            ['Bearer install-T2', 'Bearer install-T2', 'Bearer install-T2', 'Bearer install-T2'],
            array_map(static fn($request): string => $request->getHeaderLine('Authorization'), $http->requests),
        );
    }

    public function test_context_changed_uses_the_default_persistence_and_a_new_app_reads_it_only_for_the_same_tenant_and_thread(): void
    {
        $store = new MemoryConversationStore();
        $this
            ->app(store: $store)
            ->assistant($this->assistant(static fn() => null))
            ->dispatch($this->lifecycle(
                'assistant_thread_context_changed',
                team: 'T1',
                channel: 'D1',
                root: '100.001',
            ));

        $received = [];
        $this
            ->app(store: $store)
            ->assistant($this->assistant(static function (Message $message, Thread $thread) use (&$received): void {
                $received[$thread->identity->key->teamId
                    . ':'
                    . $message->channelId
                    . ':'
                    . $message->threadTs] = $thread->context()?->toArray();
            }))
            ->dispatch($this->userMessage(team: 'T1', channel: 'D1', root: '100.001'));
        $this
            ->app(store: $store)
            ->assistant($this->assistant(static function (Message $message, Thread $thread) use (&$received): void {
                $received[$thread->identity->key->teamId
                    . ':'
                    . $message->channelId
                    . ':'
                    . $message->threadTs] = $thread->context()?->toArray();
            }))
            ->dispatch($this->userMessage(team: 'T1', channel: 'D1', root: '200.001'));
        $this
            ->app(store: $store)
            ->assistant($this->assistant(static function (Message $message, Thread $thread) use (&$received): void {
                $received[$thread->identity->key->teamId
                    . ':'
                    . $message->channelId
                    . ':'
                    . $message->threadTs] = $thread->context()?->toArray();
            }))
            ->dispatch($this->userMessage(team: 'T2', channel: 'D1', root: '100.001'));

        self::assertSame(
            ['ticket' => 'CASE-42', 'channel_id' => 'C-focus', 'team_id' => 'T-focus'],
            $received['T1:D1:100.001'],
        );
        self::assertNull($received['T1:D1:200.001']);
        self::assertNull($received['T2:D1:100.001']);
    }

    public function test_assistant_messages_are_consumed_after_global_middleware_but_unrelated_messages_still_reach_regular_listeners(): void
    {
        $calls = [];
        $app = $this
            ->app()
            ->use(static function (Delivery $delivery, \Closure $next) use (&$calls): mixed {
                $calls[] = 'global:' . $delivery->payload::class;
                return $next();
            })
            ->assistant(Assistant::make()
                ->threadStarted(static fn() => null)
                ->userMessage(static function (Thread $thread) use (&$calls): void {
                    $calls[] = 'assistant:' . $thread->identity->threadTs();
                }))
            ->message('hello', static function () use (&$calls): void {
                $calls[] = 'ordinary';
            });

        $app->dispatch($this->userMessage());
        $app->dispatch([
            'type' => 'event_callback',
            'team_id' => 'T1',
            'event' => ['type' => 'message', 'channel' => 'C1', 'user' => 'U1', 'text' => 'hello', 'ts' => '3.001'],
        ]);

        self::assertSame(
            [
                'global:' . Message::class,
                'assistant:100.001',
                'global:' . Message::class,
                'ordinary',
            ],
            $calls,
        );
    }

    public function test_lifecycle_and_handler_middleware_preserve_the_typed_event_and_assistant_thread(): void
    {
        $calls = [];
        $app = $this->app()->assistant(
            Assistant::make()
                ->threadStarted(static function (Delivery $delivery, \Closure $next) use (&$calls): mixed {
                    $calls[] = $delivery->assistant instanceof Thread ? 'middleware' : 'missing';
                    return $next();
                }, static function (AssistantThreadStarted $event, Thread $thread) use (&$calls): void {
                    $calls[] = $event->context->teamId . ':' . $thread->identity->threadTs();
                })
                ->threadContextChanged(static function (AssistantThreadContextChanged $event) use (&$calls): void {
                    $calls[] = 'changed:' . $event->context->teamId;
                })
                ->userMessage(static fn() => null),
        );

        $app->dispatch($this->lifecycle('assistant_thread_started'));
        $app->dispatch($this->lifecycle('assistant_thread_context_changed'));

        self::assertSame(['middleware', 'T-focus:100.001', 'changed:T-focus'], $calls);
    }

    public function test_assistant_failures_use_the_application_error_handler_and_bot_messages_never_enter_the_assistant(): void
    {
        $failure = new \RuntimeException('Assistant failed');
        $reported = [];
        $handled = false;
        $app = $this
            ->app()
            ->error(static function (HandlerFailures $failures, AssistantThreadStarted $event, array $metadata) use (
                &$reported,
            ): void {
                $reported[] = [$failures->failures, $event->userId, $metadata];
            })
            ->assistant(Assistant::make()
                ->threadStarted(static fn() => throw $failure)
                ->userMessage(static function () use (&$handled): void {
                    $handled = true;
                }));

        self::assertNull($app->dispatch($this->lifecycle('assistant_thread_started'), ['delivery' => 'D1']));
        $botMessage = $this->userMessage();
        $botMessage['event']['bot_id'] = 'B1';
        $app->dispatch($botMessage);

        self::assertSame([[[$failure], 'U1', ['delivery' => 'D1']]], $reported);
        self::assertFalse($handled);
    }

    public function test_builder_and_lifecycle_payload_reject_missing_handlers_and_malformed_thread_data(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->app()->assistant(Assistant::make()->threadStarted(static fn() => null));
    }

    public function test_malformed_lifecycle_event_fails_before_any_assistant_handler_runs(): void
    {
        $called = false;
        $app = $this->app()->assistant($this->assistant(static function () use (&$called): void {
            $called = true;
        }));
        $payload = $this->lifecycle('assistant_thread_started');
        unset($payload['event']['assistant_thread']['thread_ts']);

        try {
            $app->dispatch($payload);
            self::fail('Expected malformed assistant lifecycle payload to fail.');
        } catch (InvalidPayload) {
            self::assertFalse($called);
        }
    }

    private function assistant(\Closure $message): Assistant
    {
        return Assistant::make()
            ->threadStarted(static fn() => null)
            ->userMessage($message);
    }

    private function app(?RecordingHttpClient $http = null, MemoryConversationStore|false|null $store = null): App
    {
        $http ??= new RecordingHttpClient();
        return new App(
            new GenericContainer(),
            new Client($http, 'root-token'),
            new BoltConfig('root-token'),
            conversationStore: $store,
        );
    }

    /** @return array<string, mixed> */
    private function lifecycle(
        string $type,
        string $team = 'T1',
        string $channel = 'D1',
        string $root = '100.001',
    ): array {
        return [
            'type' => 'event_callback',
            'team_id' => 'WRONG-TEAM',
            'authorizations' => [[
                'team_id' => $team,
                'is_enterprise_install' => false,
            ]],
            'event' => [
                'type' => $type,
                'event_ts' => '1.001',
                'assistant_thread' => [
                    'channel_id' => $channel,
                    'thread_ts' => $root,
                    'user_id' => 'U1',
                    'context' => ['channel_id' => 'C-focus', 'team_id' => 'T-focus', 'ticket' => 'CASE-42'],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function userMessage(string $team = 'T1', string $channel = 'D1', string $root = '100.001'): array
    {
        return [
            'type' => 'event_callback',
            'team_id' => $team,
            'event' => [
                'type' => 'message',
                'channel_type' => 'im',
                'channel' => $channel,
                'user' => 'U1',
                'text' => 'hello',
                'ts' => '101.001',
                'thread_ts' => $root,
            ],
        ];
    }
}
