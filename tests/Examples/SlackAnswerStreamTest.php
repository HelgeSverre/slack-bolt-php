<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Examples;

use App\Demo\Ai\AgentEvent;
use App\Demo\Ai\AgentResult;
use App\Demo\Ai\SlackAnswerStream;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Thunder\Bolt\Jobs\UncertainOutcome;
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

    public function test_internal_append_error_is_uncertain_and_never_posts_a_fallback(): void
    {
        $http = new RecordingHttpClient([
            new Response(200, [], '{"ok":true,"ts":"2.002"}'),
            new Response(200, [], '{"ok":false,"error":"internal_error"}'),
        ]);
        $stream = $this->stream($http);
        $stream->accept(AgentEvent::text('Hello'));

        $this->expectException(UncertainOutcome::class);
        try {
            $stream->accept(AgentEvent::task('tool', 'Check pet status', 'in_progress'));
        } finally {
            self::assertSame(['chat.startStream', 'chat.appendStream'], $this->methods($http));
        }
    }

    public function test_lost_start_stream_response_is_uncertain_and_never_posts_a_fallback(): void
    {
        $http = new AmbiguousStreamHttpClient(
            1,
            new ConnectException(
                'Response lost.',
                new \GuzzleHttp\Psr7\Request('POST', 'https://slack.com/api/chat.startStream'),
            ),
        );
        $stream = new SlackAnswerStream(new Client($http, 'test'), 'C1', '1.001', 'U1', 'T1');

        $this->expectException(UncertainOutcome::class);
        try {
            $stream->accept(AgentEvent::text('Hello'));
        } finally {
            self::assertSame(['chat.startStream'], $this->methods($http));
        }
    }

    public function test_lost_append_stream_response_is_uncertain_and_never_posts_a_fallback(): void
    {
        $http = new AmbiguousStreamHttpClient(
            2,
            new ConnectException(
                'Response lost.',
                new \GuzzleHttp\Psr7\Request('POST', 'https://slack.com/api/chat.appendStream'),
            ),
        );
        $stream = new SlackAnswerStream(new Client($http, 'test'), 'C1', '1.001', 'U1', 'T1');
        $stream->accept(AgentEvent::text('Hello'));

        $this->expectException(UncertainOutcome::class);
        try {
            $stream->accept(AgentEvent::task('search', 'Search the web', 'in_progress'));
        } finally {
            self::assertSame(['chat.startStream', 'chat.appendStream'], $this->methods($http));
        }
    }

    public function test_lost_stop_stream_response_is_uncertain_and_never_posts_a_fallback(): void
    {
        $http = new AmbiguousStreamHttpClient(
            2,
            new ConnectException(
                'Response lost.',
                new \GuzzleHttp\Psr7\Request('POST', 'https://slack.com/api/chat.stopStream'),
            ),
        );
        $stream = new SlackAnswerStream(new Client($http, 'test'), 'C1', '1.001', 'U1', 'T1');
        $stream->accept(AgentEvent::text('Hello'));

        $this->expectException(UncertainOutcome::class);
        try {
            $stream->finish(new AgentResult('Hello.', []));
        } finally {
            self::assertSame(['chat.startStream', 'chat.stopStream'], $this->methods($http));
        }
    }

    public function test_internal_stream_error_is_uncertain_and_never_posts_a_fallback(): void
    {
        $http = new AmbiguousStreamHttpClient(1, new Response(200, [], '{"ok":false,"error":"internal_error"}'));
        $stream = new SlackAnswerStream(new Client($http, 'test'), 'C1', '1.001', 'U1', 'T1');

        $this->expectException(UncertainOutcome::class);
        try {
            $stream->accept(AgentEvent::text('Hello'));
        } finally {
            self::assertSame(['chat.startStream'], $this->methods($http));
        }
    }

    public function test_lost_fallback_response_is_uncertain_after_a_known_stream_rejection(): void
    {
        $http = new FallbackFailureHttpClient();
        $stream = new SlackAnswerStream(new Client($http, 'test'), 'C1', '1.001', 'U1', 'T1');
        $stream->accept(AgentEvent::text('Hello'));

        $this->expectException(UncertainOutcome::class);
        try {
            $stream->finish(new AgentResult('Hello.', []));
        } finally {
            self::assertSame(['chat.startStream', 'chat.postMessage'], $this->methods($http));
        }
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

    private function methods(RecordingHttpClient|AmbiguousStreamHttpClient|FallbackFailureHttpClient $http): array
    {
        return array_map(static fn($request): string => basename($request->getUri()->getPath()), $http->requests);
    }
}

final class AmbiguousStreamHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    public function __construct(
        private readonly int $failureRequest,
        private readonly ResponseInterface|\Throwable $failure,
    ) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        if (count($this->requests) === $this->failureRequest) {
            if ($this->failure instanceof \Throwable) {
                throw $this->failure;
            }

            return $this->failure;
        }

        return new Response(200, [], '{"ok":true,"ts":"2.002"}');
    }
}

final class FallbackFailureHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        if (count($this->requests) === 1) {
            return new Response(200, [], '{"ok":false,"error":"not_allowed_token_type"}');
        }

        throw new ConnectException('Response lost.', $request);
    }
}
