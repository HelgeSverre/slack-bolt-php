<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Assistant;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Thunder\Bolt\Assistant\AgentSessionStatus;
use Thunder\Bolt\Assistant\MessageStream;
use Thunder\Bolt\Assistant\Status;
use Thunder\Bolt\Assistant\ThreadIdentity;
use Thunder\Bolt\Conversation\ConversationKey;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;
use Thunder\Bolt\WebApi\SlackApiException;

final class MessageStreamTest extends TestCase
{
    public function test_stream_pins_identity_and_uses_one_start_append_and_stop(): void
    {
        $http = new RecordingHttpClient();
        $stream = $this->stream($http);
        self::assertNull($stream->timestamp());
        self::assertSame([], $http->requests);

        $stream
            ->append('The sun')
            ->chunks([['type' => 'markdown_text', 'text' => ' is big.']])
            ->stop();

        self::assertSame(
            [
                'channel' => 'D1',
                'thread_ts' => '100.001',
                'recipient_user_id' => 'U1',
                'recipient_team_id' => 'T1',
                'chunks' => [['type' => 'markdown_text', 'text' => 'The sun']],
            ],
            $http->sentPayload(0),
        );
        self::assertSame('chat.appendStream', basename($http->requests[1]->getUri()->getPath()));
        self::assertSame('chat.stopStream', basename($http->requests[2]->getUri()->getPath()));
        self::assertSame('1700000000.000002', $http->sentPayload(1)['ts']);
        self::assertSame($stream->timestamp(), $http->sentPayload(2)['ts']);
        self::assertSame('active', $http->sentPayload(2)['session_status']);
        $this->expectException(\LogicException::class);
        $stream->append('Too late');
    }

    public function test_failed_start_cannot_be_replayed_by_reusing_the_stream(): void
    {
        $http = new RecordingHttpClient([new Response(200, [], '{"ok":false,"error":"internal_error"}')]);
        $stream = $this->stream($http);
        try {
            $stream->append('One attempt');
            self::fail('Expected Slack failure.');
        } catch (SlackApiException $exception) {
            self::assertSame('internal_error', $exception->error);
        }
        try {
            $stream->append('Do not replay');
            self::fail('Expected a terminal stream.');
        } catch (\LogicException) {
            self::assertCount(1, $http->requests);
        }
    }

    public function test_stream_options_cannot_override_channel_or_credentials(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MessageStream(new Client(new RecordingHttpClient(), 'test'), $this->identity(), ['channel' => 'OTHER']);
    }

    public function test_finish_can_leave_a_session_waiting_for_input(): void
    {
        $http = new RecordingHttpClient();
        $this->stream($http)->append('Which workspace?')->finish(AgentSessionStatus::Suspended);

        self::assertSame('suspended', $http->sentPayload(1)['session_status']);
    }

    public function test_status_builder_keeps_options_immutable_and_bounds_loading_messages(): void
    {
        $base = Status::make('is thinking');
        $status = $base->loadingMessages('Reading the question')->icon(':hourglass:')->username('Thunder');
        self::assertSame(['status' => 'is thinking'], $base->toArray());
        self::assertSame(
            [
                'status' => 'is thinking',
                'loading_messages' => ['Reading the question'],
                'icon_emoji' => ':hourglass:',
                'username' => 'Thunder',
            ],
            $status->toArray(),
        );
        $this->expectException(\InvalidArgumentException::class);
        (void) $base->loadingMessages(...array_fill(0, 11, 'Too many'));
    }

    private function identity(): ThreadIdentity
    {
        return new ThreadIdentity(new ConversationKey('D1', teamId: 'T1', threadTs: '100.001'), 'U1');
    }

    private function stream(RecordingHttpClient $http): MessageStream
    {
        return new MessageStream(new Client($http, 'test'), $this->identity());
    }
}
