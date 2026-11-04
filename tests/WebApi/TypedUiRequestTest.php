<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\WebApi;

use PHPUnit\Framework\TestCase;
use Thunder\Bolt\BlockKit\Section;
use Thunder\Bolt\Messages\Message;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\Views\Home;
use Thunder\Bolt\Views\Modal;
use Thunder\Bolt\WebApi\Client;
use Thunder\Bolt\WebApi\Requests\ChatPostEphemeral;
use Thunder\Bolt\WebApi\Requests\ChatUpdate;
use Thunder\Bolt\WebApi\Requests\ViewRequest;

final class TypedUiRequestTest extends TestCase
{
    public function test_native_view_requests_serialize_only_their_valid_operation_fields(): void
    {
        $modal = Modal::make('Details');
        $home = Home::make(Section::text('Ready'));

        self::assertSame(
            ['trigger_id' => 'trigger', 'view' => $modal->toArray()],
            ViewRequest::open('trigger', $modal)->toArray(),
        );
        self::assertSame(
            ['trigger_id' => 'trigger', 'view' => $modal->toArray()],
            ViewRequest::push('trigger', $modal)->toArray(),
        );
        self::assertSame(
            ['user_id' => 'U123', 'view' => $home->toArray(), 'hash' => 'home-hash'],
            ViewRequest::publish('U123', $home)->withHash('home-hash')->toArray(),
        );
        self::assertSame(
            ['view' => $modal->toArray(), 'view_id' => 'V123', 'hash' => 'view-hash'],
            ViewRequest::update('V123', $modal)->withHash('view-hash')->toArray(),
        );
        self::assertSame(
            ['view' => $modal->toArray(), 'external_id' => 'billing-modal'],
            ViewRequest::updateByExternalId('billing-modal', $modal)->toArray(),
        );
    }

    public function test_native_view_requests_use_existing_generated_resources(): void
    {
        $http = new RecordingHttpClient();
        $client = new Client($http, 'token');
        $modal = Modal::make('Details');

        $client->views()->open(ViewRequest::open('open-trigger', $modal));
        $client->views()->push(ViewRequest::push('push-trigger', $modal));
        $client->views()->publish(ViewRequest::publish('U123', Home::make(Section::text('Ready'))));
        $client->views()->update(ViewRequest::update('V123', $modal));

        self::assertSame('views.open', basename($http->requests[0]->getUri()->getPath()));
        self::assertSame(['trigger_id' => 'open-trigger', 'view' => $modal->toArray()], $http->sentPayload());
        self::assertSame('views.push', basename($http->requests[1]->getUri()->getPath()));
        self::assertSame('views.publish', basename($http->requests[2]->getUri()->getPath()));
        self::assertSame('views.update', basename($http->requests[3]->getUri()->getPath()));
    }

    public function test_view_hash_is_immutable_and_only_available_to_publish_or_update(): void
    {
        $home = Home::make();
        $published = ViewRequest::publish('U123', $home);

        self::assertArrayNotHasKey('hash', $published->toArray());
        self::assertSame('v1', $published->withHash('v1')->toArray()['hash']);

        $this->expectException(\LogicException::class);
        (void) ViewRequest::open('trigger', Modal::make('Details'))->withHash('not-valid-here');
    }

    public function test_view_requests_reject_empty_identifiers_and_overlong_external_targets(): void
    {
        $modal = Modal::make('Details');

        foreach ([
            static fn(): ViewRequest => ViewRequest::open('', $modal),
            static fn(): ViewRequest => ViewRequest::publish('', Home::make()),
            static fn(): ViewRequest => ViewRequest::update('', $modal),
            static fn(): ViewRequest => ViewRequest::updateByExternalId(str_repeat('x', 256), $modal),
        ] as $invalid) {
            try {
                $invalid();
                self::fail('Expected an invalid view request identifier.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_chat_update_uses_explicit_destination_and_drops_post_only_fields(): void
    {
        $message = Message::text('Updated')
            ->inThread('1700000000.000001')
            ->unfurlLinks(false)
            ->mrkdwn(false)
            ->iconEmoji(':bolt:')
            ->replyBroadcast()
            ->metadata('release.updated', ['id' => '42']);

        self::assertSame(
            [
                'channel' => 'C123',
                'ts' => '1700000000.000002',
                'text' => 'Updated',
                'reply_broadcast' => true,
                'metadata' => ['event_type' => 'release.updated', 'event_payload' => ['id' => '42']],
                'as_user' => false,
            ],
            new ChatUpdate('C123', '1700000000.000002', $message)->toArray(),
        );
    }

    public function test_ephemeral_request_uses_an_explicit_thread_and_drops_incompatible_message_fields(): void
    {
        $message = Message::text('Private')
            ->inThread('1700000000.000001')
            ->replyBroadcast()
            ->unfurlLinks(false)
            ->mrkdwn(false)
            ->metadata('private.note', ['id' => '42']);

        self::assertSame(
            [
                'channel' => 'C123',
                'user' => 'U123',
                'text' => 'Private',
                'metadata' => ['event_type' => 'private.note', 'event_payload' => ['id' => '42']],
                'thread_ts' => '1700000000.000002',
            ],
            new ChatPostEphemeral('C123', 'U123', $message, '1700000000.000002')->toArray(),
        );
    }

    public function test_chat_request_values_are_sent_through_generated_resources(): void
    {
        $http = new RecordingHttpClient();
        $client = new Client($http, 'token');

        $client->chat()->update(new ChatUpdate('C123', '1700000000.000001', 'Updated'));
        $client->chat()->postEphemeral(new ChatPostEphemeral('C123', 'U123', 'Private'));

        self::assertSame('chat.update', basename($http->requests[0]->getUri()->getPath()));
        self::assertSame(['channel' => 'C123', 'ts' => '1700000000.000001', 'text' => 'Updated'], $http->sentPayload());
        self::assertSame('chat.postEphemeral', basename($http->requests[1]->getUri()->getPath()));
        self::assertSame(['channel' => 'C123', 'user' => 'U123', 'text' => 'Private'], $http->sentPayload(1));
    }

    public function test_chat_request_values_validate_required_destinations_and_timestamps(): void
    {
        foreach ([
            static fn(): ChatUpdate => new ChatUpdate('', '1700000000.000001', 'Update'),
            static fn(): ChatUpdate => new ChatUpdate('C123', 'invalid', 'Update'),
            static fn(): ChatPostEphemeral => new ChatPostEphemeral('', 'U123', 'Private'),
            static fn(): ChatPostEphemeral => new ChatPostEphemeral('C123', '', 'Private'),
            static fn(): ChatPostEphemeral => new ChatPostEphemeral('C123', 'U123', 'Private', 'invalid'),
        ] as $invalid) {
            try {
                $invalid();
                self::fail('Expected an invalid chat request destination.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
