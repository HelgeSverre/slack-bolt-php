<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Payloads;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thunder\Bolt\Exceptions\InvalidListener;
use Thunder\Bolt\Payloads\Event;
use Thunder\Bolt\Payloads\EventPayloads;
use Thunder\Bolt\Payloads\Events\AppHomeOpened;
use Thunder\Bolt\Payloads\Events\ChannelCreated;
use Thunder\Bolt\Payloads\Events\MessageMetadataEvent;
use Thunder\Bolt\Testing\BoltTest;

final class EverydayEventsTest extends TestCase
{
    #[DataProvider('events')]
    public function test_signed_http_dispatch_retains_raw_fields_and_supplies_specific_and_generic_handlers(
        string $type,
        array $data,
    ): void {
        $bolt = BoltTest::make();
        $received = [];
        $bolt->app->event($type, static function (Event $event) use (&$received): void {
            $received[] = $event;
        });
        $raw = [...$data, 'future_field' => ['nested' => true], 'type' => $type];
        $bolt->event($type, $raw)->assertNoApiCalls();
        self::assertInstanceOf(EventPayloads::classFor($type), $received[0]);
        self::assertSame($raw, $received[0]->raw);
        if ($received[0] instanceof MessageMetadataEvent) {
            self::assertSame('1700000000.000001', $received[0]->messageTs);
            self::assertSame('C123', $received[0]->channelId);
            self::assertNull($received[0]->threadTs);
            $metadata = $received[0]->metadata ?? $received[0]->previousMetadata;
            self::assertSame('domain:future-event', $metadata->eventType);
            self::assertSame(['id' => 42, 'nested' => ['anything' => null]], $metadata->eventPayload);
        }
    }

    public static function events(): iterable
    {
        yield [
            'app_home_opened',
            [
                'user' => 'U123',
                'channel' => 'D123',
                'tab' => 'home',
                'context' => ['entities' => []],
                'view' => ['type' => 'home'],
            ],
        ];
        yield [
            'channel_created',
            ['channel' => ['id' => 'C123', 'name' => 'new', 'creator' => 'U123', 'created' => 1700000000]],
        ];
        yield ['channel_rename', ['channel' => ['id' => 'C123', 'name' => 'renamed']]];
        foreach (['channel_archive', 'channel_unarchive', 'channel_deleted'] as $type) {
            yield [$type, ['channel' => 'C123', 'event_ts' => '1700000000.000002']];
        }
        $metadata = [
            'event_type' => 'domain:future-event',
            'event_payload' => ['id' => 42, 'nested' => ['anything' => null]],
        ];
        foreach (['message_metadata_posted', 'message_metadata_updated', 'message_metadata_deleted'] as $type) {
            yield [
                $type,
                [
                    'channel_id' => 'C123',
                    'message_ts' => '1700000000.000001',
                    'event_ts' => '1700000000.000002',
                    ...(
                        $type === 'message_metadata_deleted'
                            ? ['previous_metadata' => $metadata, 'deleted_ts' => '1700000000.000002']
                            : [
                                'metadata' => $metadata,
                                ...($type === 'message_metadata_updated' ? ['previous_metadata' => $metadata] : []),
                            ]
                    ),
                ],
            ];
        }
    }

    public function test_app_home_without_previously_published_view_or_optional_fields_is_accepted(): void
    {
        $event = AppHomeOpened::fromArray(['type' => 'app_home_opened', 'user' => 'U123']);
        self::assertNull($event->view);
        self::assertNull($event->context);
        self::assertNull($event->channelId);
        self::assertNull($event->tab);
    }

    public function test_native_listener_registration_rejects_the_wrong_event_type(): void
    {
        $this->expectException(InvalidListener::class);
        BoltTest::make()->app->event('app_home_opened', static fn(ChannelCreated $event): null => null);
    }

    #[DataProvider('malformed')]
    public function test_invalid_known_payloads_are_rejected_before_handlers(string $type, array $body): void
    {
        BoltTest::make()
            ->send(['type' => 'event_callback', 'event' => ['type' => $type, ...$body]])
            ->assertStatus(400);
        $this->addToAssertionCount(1);
    }

    public static function malformed(): iterable
    {
        yield ['app_home_opened', ['user' => ['id' => 'U1']]];
        yield ['app_home_opened', ['user' => 'U1', 'view' => 'invalid']];
        yield ['channel_created', ['channel' => 'C123']];
        yield ['channel_rename', ['channel' => ['name' => 'missing-id']]];
        yield ['channel_deleted', ['channel' => ['C123']]];
        yield [
            'message_metadata_posted',
            ['channel_id' => 'C123', 'message_ts' => '1.0', 'event_ts' => '2.0', 'metadata' => []],
        ];
    }

    public function test_unknown_event_payloads_remain_generic(): void
    {
        self::assertSame(Event::class, EventPayloads::fromArray(['type' => 'future_event'])::class);
    }

    public function test_metadata_written_by_the_bot_is_still_delivered_to_observers(): void
    {
        $bolt = BoltTest::make();
        $observed = false;
        $bolt->app->event('message_metadata_posted', static function (MessageMetadataEvent $event) use (
            &$observed,
        ): void {
            $observed = $event->userId === 'U_BOLT_TEST';
        });
        $bolt->event('message_metadata_posted', [
            'user_id' => 'U_BOLT_TEST',
            'channel_id' => 'C1',
            'message_ts' => '1.0',
            'event_ts' => '2.0',
            'metadata' => ['event_type' => 'task', 'event_payload' => []],
        ]);
        self::assertTrue($observed);
    }
}
