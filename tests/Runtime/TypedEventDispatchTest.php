<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Runtime;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tempest\Container\GenericContainer;
use Thunder\Bolt\App;
use Thunder\Bolt\BoltConfig;
use Thunder\Bolt\Exceptions\InvalidListener;
use Thunder\Bolt\Payloads\Event;
use Thunder\Bolt\Payloads\Events\AgentSessionStopped;
use Thunder\Bolt\Payloads\Events\AgentSessionTitleChanged;
use Thunder\Bolt\Payloads\Events\AppUninstalled;
use Thunder\Bolt\Payloads\Events\FileShared;
use Thunder\Bolt\Payloads\Events\LinkShared;
use Thunder\Bolt\Payloads\Events\MemberJoinedChannel;
use Thunder\Bolt\Payloads\Events\MemberLeftChannel;
use Thunder\Bolt\Payloads\Events\ReactionAdded;
use Thunder\Bolt\Payloads\Events\ReactionRemoved;
use Thunder\Bolt\Payloads\Events\TokensRevoked;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;

final class TypedEventDispatchTest extends TestCase
{
    #[DataProvider('typedEvents')]
    public function test_trusted_dispatch_routes_each_supported_event_to_its_typed_payload(
        string $selector,
        string $payloadClass,
        array $event,
    ): void {
        $app = $this->app();
        $received = null;
        $app->event($selector, static function (Event $payload) use (&$received): void {
            $received = $payload;
        });

        $app->dispatch(['type' => 'event_callback', 'event' => $event]);

        self::assertInstanceOf($payloadClass, $received);
        self::assertSame($event, $received->raw);
    }

    public function test_a_specific_listener_and_a_generic_event_listener_both_receive_a_reaction_payload(): void
    {
        $app = $this->app();
        $received = [];
        $app->event('reaction_added', static function (ReactionAdded $event) use (&$received): void {
            $received[] = $event->reaction;
        });
        $app->event('reaction_added', static function (Event $event) use (&$received): void {
            $received[] = $event::class;
        });

        $app->dispatch(['type' => 'event_callback', 'event' => self::reactionAdded()]);

        self::assertSame(['wave', ReactionAdded::class], $received);
    }

    public function test_payload_compatibility_rejects_an_incompatible_typed_listener(): void
    {
        $app = $this->app();

        $this->expectException(InvalidListener::class);
        $app->event('reaction_added', static fn(ReactionRemoved $event): null => null);
    }

    public function test_membership_events_from_the_bot_are_not_suppressed_by_ignore_self(): void
    {
        $app = $this->app(new BoltConfig(token: 'token', signingSecret: 'secret', botUserId: 'U-bot'));
        $called = false;
        $app->event('member_joined_channel', static function (MemberJoinedChannel $event) use (&$called): void {
            $called = $event->userId === 'U-bot';
        });

        $app->dispatch([
            'type' => 'event_callback',
            'event' => [
                'type' => 'member_joined_channel',
                'user' => 'U-bot',
                'channel' => 'C1',
                'channel_type' => 'C',
                'team' => 'T1',
            ],
        ]);

        self::assertTrue($called);
    }

    public function test_a_file_shared_by_the_bot_is_suppressed_by_ignore_self(): void
    {
        $app = $this->app(new BoltConfig(token: 'token', signingSecret: 'secret', botUserId: 'U1'));
        $called = false;
        $app->event('file_shared', static function (FileShared $event) use (&$called): void {
            $called = $event->fileId === 'F1';
        });

        $app->dispatch([
            'type' => 'event_callback',
            'event' => [
                'type' => 'file_shared',
                'channel_id' => 'C1',
                'file_id' => 'F1',
                'user_id' => 'U1',
                'file' => ['id' => 'F1'],
                'event_ts' => '1.000001',
            ],
        ]);

        self::assertFalse($called);
    }

    public static function typedEvents(): iterable
    {
        yield 'reaction added' => ['reaction_added', ReactionAdded::class, self::reactionAdded()];
        yield 'reaction removed' => [
            'reaction_removed',
            ReactionRemoved::class,
            [...self::reactionAdded(), 'type' => 'reaction_removed'],
        ];
        yield 'member joined' => [
            'member_joined_channel',
            MemberJoinedChannel::class,
            self::membership('member_joined_channel'),
        ];
        yield 'member left' => [
            'member_left_channel',
            MemberLeftChannel::class,
            self::membership('member_left_channel'),
        ];
        yield 'link shared' => [
            'link_shared',
            LinkShared::class,
            [
                'type' => 'link_shared',
                'channel' => 'C1',
                'user' => 'U1',
                'message_ts' => '1.000001',
                'links' => [['domain' => 'example.test', 'url' => 'https://example.test/1']],
            ],
        ];
        yield 'file shared' => [
            'file_shared',
            FileShared::class,
            [
                'type' => 'file_shared',
                'channel_id' => 'C1',
                'file_id' => 'F1',
                'user_id' => 'U1',
                'file' => ['id' => 'F1'],
                'event_ts' => '1.000001',
            ],
        ];
        yield 'app uninstalled' => ['app_uninstalled', AppUninstalled::class, ['type' => 'app_uninstalled']];
        yield 'tokens revoked' => [
            'tokens_revoked',
            TokensRevoked::class,
            ['type' => 'tokens_revoked', 'tokens' => ['bot' => ['U1']]],
        ];
        yield 'agent session stopped' => [
            'agent_session_stopped',
            AgentSessionStopped::class,
            [
                'type' => 'agent_session_stopped',
                'channel' => 'C1',
                'user' => 'U1',
                'event_ts' => '1.000001',
                'streaming_message_ts' => ['1.000000'],
            ],
        ];
        yield 'agent session title changed' => [
            'agent_session_title_changed',
            AgentSessionTitleChanged::class,
            [
                'type' => 'agent_session_title_changed',
                'channel' => 'C1',
                'user' => 'U1',
                'event_ts' => '1.000001',
                'title' => 'Investigate incident',
            ],
        ];
    }

    private function app(?BoltConfig $config = null): App
    {
        $config ??= new BoltConfig(token: 'token', signingSecret: 'secret');

        return new App(new GenericContainer(), new Client(new RecordingHttpClient(), $config->token), $config);
    }

    private static function reactionAdded(): array
    {
        return [
            'type' => 'reaction_added',
            'user' => 'U1',
            'reaction' => 'wave',
            'item' => ['type' => 'message', 'channel' => 'C1', 'ts' => '1.000000'],
            'event_ts' => '1.000001',
        ];
    }

    private static function membership(string $type): array
    {
        return ['type' => $type, 'user' => 'U1', 'channel' => 'C1', 'channel_type' => 'C', 'team' => 'T1'];
    }
}
