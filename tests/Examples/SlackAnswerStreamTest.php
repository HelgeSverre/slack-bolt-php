<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Examples;

use App\Demo\Ai\AgentEvent;
use App\Demo\Ai\AgentResult;
use App\Demo\Ai\SlackAnswerStream;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;

final class SlackAnswerStreamTest extends TestCase
{
    public function test_it_batches_text_and_keeps_task_ids_and_sources_on_one_stream(): void
    {
        $http = new RecordingHttpClient();
        $stream = $this->stream($http);
        $stream->accept(AgentEvent::task('search-1', 'Search the web', 'in_progress'));
        $stream->accept(AgentEvent::task('search-1', 'Search the web', 'complete'));
        foreach (str_split('Diameter ≈ 1.4 million km.', 3) as $fragment) {
            $stream->accept(AgentEvent::text($fragment));
        }
        self::assertCount(2, $http->requests, 'Small text fragments should remain batched.');
        $stream->finish(
            new AgentResult(
                'Diameter ≈ 1.4 million km.',
                [],
                citations: [['url' => 'https://example.com/sun', 'title' => 'Sun']],
            ),
        );
        self::assertSame(['chat.startStream', 'chat.appendStream', 'chat.stopStream'], $this->methods($http));
        self::assertSame('1.001', $http->sentPayload(0)['thread_ts']);
        self::assertSame('T1', $http->sentPayload(0)['recipient_team_id']);
        self::assertSame('U1', $http->sentPayload(0)['recipient_user_id']);
        self::assertSame('complete', $http->sentPayload(1)['chunks'][0]['status']);
        self::assertSame('search-1', $http->sentPayload(1)['chunks'][0]['id']);
        self::assertSame('Diameter ≈ 1.4 million km.', $http->sentPayload(2)['chunks'][0]['text']);
        self::assertStringContainsString(
            '<https://example.com/sun|Sun>',
            $http->sentPayload(2)['blocks'][0]['elements'][0]['text'],
        );
    }

    public function test_split_notification_markup_is_escaped_before_any_fragment_is_sent(): void
    {
        $http = new RecordingHttpClient();
        $stream = $this->stream($http);
        $stream->accept(AgentEvent::text('<!'));
        $stream->accept(AgentEvent::text('channel> <@U1>'));
        $stream->finish(new AgentResult('@channel @U1', []));
        $wire = implode('', array_map(static fn($request): string => (string) $request->getBody(), $http->requests));
        self::assertStringNotContainsString('<!', $wire);
        self::assertStringNotContainsString('<@', $wire);
        self::assertStringContainsString('&lt;', $wire);
    }

    public function test_stream_start_failure_posts_the_final_answer_in_the_same_thread(): void
    {
        $http = new RecordingHttpClient([new Response(200, [], '{"ok":false,"error":"not_allowed_token_type"}')]);
        $stream = $this->stream($http);
        $stream->accept(AgentEvent::text('Hello'));
        $stream->finish(new AgentResult('Hello world.', []));
        self::assertSame(['chat.startStream', 'chat.postMessage'], $this->methods($http));
        self::assertSame('Hello world.', $http->sentPayload(1)['text']);
        self::assertSame('1.001', $http->sentPayload(1)['thread_ts']);
    }

    public function test_append_failure_closes_the_partial_stream_then_posts_the_complete_answer(): void
    {
        $http = new RecordingHttpClient([
            new Response(200, [], '{"ok":true,"ts":"2.002"}'),
            new Response(200, [], '{"ok":false,"error":"internal_error"}'),
        ]);
        $stream = $this->stream($http);
        $stream->accept(AgentEvent::text('Hello'));
        $stream->accept(AgentEvent::task('tool', 'Check pet status', 'in_progress'));
        $stream->finish(new AgentResult('Hello world.', []));
        self::assertSame(
            ['chat.startStream', 'chat.appendStream', 'chat.stopStream', 'chat.postMessage'],
            $this->methods($http),
        );
        self::assertSame('2.002', $http->sentPayload(2)['ts']);
        self::assertSame('Hello world.', $http->sentPayload(3)['text']);
    }

    public function test_open_tasks_are_marked_failed_when_the_model_stream_is_interrupted(): void
    {
        $http = new RecordingHttpClient();
        $stream = $this->stream($http);
        $stream->accept(AgentEvent::task('search', 'Search the web', 'in_progress'));
        $stream->finish(new AgentResult('I could not finish that request.', [], 'stream_failed'));
        self::assertSame('chat.stopStream', $this->methods($http)[1]);
        self::assertSame('error', $http->sentPayload(1)['chunks'][1]['status']);
        self::assertSame('I could not finish that request.', $http->sentPayload(1)['chunks'][0]['text']);
    }

    private function stream(RecordingHttpClient $http): SlackAnswerStream
    {
        return new SlackAnswerStream(new Client($http, 'test'), 'C1', '1.001', 'U1', 'T1');
    }

    private function methods(RecordingHttpClient $http): array
    {
        return array_map(static fn($request): string => basename($request->getUri()->getPath()), $http->requests);
    }
}
