<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Assistant;

use PHPUnit\Framework\TestCase;
use Thunder\Bolt\Assistant\AgentSessionStatus;
use Thunder\Bolt\Assistant\Session;
use Thunder\Bolt\Assistant\ThreadIdentity;
use Thunder\Bolt\Conversation\ConversationKey;
use Thunder\Bolt\Payloads\Events\AgentSessionStopped;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;

final class SessionTest extends TestCase
{
    public function test_it_controls_the_modern_session_lifecycle_and_title(): void
    {
        $http = new RecordingHttpClient();
        $session = $this->session($http);

        $session->processing('Investigating');
        $session->active();
        $session->suspended();
        $session->closed();
        $session->rename('Case review');

        self::assertSame(
            [
                'agents.sessions.setStatus',
                'agents.sessions.setStatus',
                'agents.sessions.setStatus',
                'agents.sessions.setStatus',
                'agents.sessions.rename',
            ],
            array_map(static fn($request): string => basename($request->getUri()->getPath()), $http->requests),
        );
        self::assertSame(
            [
                'channel_id' => 'C1',
                'thread_ts' => '100.001',
                'status' => 'processing',
                'title' => 'Investigating',
            ],
            $http->sentPayload(0),
        );
        self::assertSame('active', $http->sentPayload(1)['status']);
        self::assertSame('suspended', $http->sentPayload(2)['status']);
        self::assertSame('closed', $http->sentPayload(3)['status']);
        self::assertSame('Case review', $http->sentPayload(4)['title']);
    }

    public function test_stopped_marks_only_its_own_session_active(): void
    {
        $http = new RecordingHttpClient();
        $session = $this->session($http);
        $event = AgentSessionStopped::fromArray([
            'type' => 'agent_session_stopped',
            'channel' => 'C1',
            'thread_ts' => '100.001',
            'user' => 'U1',
            'event_ts' => '101.001',
            'streaming_message_ts' => [],
        ]);

        $session->stopped($event);

        self::assertSame('active', $http->sentPayload()['status']);

        $otherThread = AgentSessionStopped::fromArray([
            'type' => 'agent_session_stopped',
            'channel' => 'C1',
            'thread_ts' => 'different',
            'user' => 'U1',
            'event_ts' => '101.001',
            'streaming_message_ts' => [],
        ]);
        $this->expectException(\InvalidArgumentException::class);
        $session->stopped($otherThread);
    }

    private function session(RecordingHttpClient $http): Session
    {
        return new Session(
            new Client($http, 'xoxb-test'),
            new ThreadIdentity(new ConversationKey('C1', teamId: 'T1', threadTs: '100.001'), 'U1'),
        );
    }
}
