<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\WebApi;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Thunder\Bolt\Generated\WebApi\Models\Requests\ChatPostMessageRequest;
use Thunder\Bolt\Generated\WebApi\Models\Responses\ChatPostMessageResponse;
use Thunder\Bolt\Messages\Message;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;
use Thunder\Bolt\WebApi\RateLimited;
use Thunder\Bolt\WebApi\Requests\ChatPostMessage;
use Thunder\Bolt\WebApi\SlackApiException;

final class ClientTest extends TestCase
{
    public function test_generated_resources_preserve_nested_paths_and_leaf_method_names(): void
    {
        $http = new RecordingHttpClient();
        $client = new Client($http, 'test-bot-token');

        $client->admin()->conversations()->archive(['channel_id' => 'C123']);
        $client->conversations()->list(['limit' => 10]);

        self::assertSame('https://slack.com/api/admin.conversations.archive', (string) $http->requests[0]->getUri());
        self::assertSame(['channel_id' => 'C123'], $http->sentPayload());
        self::assertSame('https://slack.com/api/conversations.list', (string) $http->requests[1]->getUri());
        self::assertSame(['limit' => 10], $http->sentPayload(1));
    }

    public function test_typed_request_sends_bearer_header_and_exact_json(): void
    {
        $http = new RecordingHttpClient();
        $client = new Client($http, 'test-bot-token');
        $response = $client->call('chat.postMessage', new ChatPostMessage('C123', Message::text('Hello')));
        self::assertSame('https://slack.com/api/chat.postMessage', (string) $http->requests[0]->getUri());
        self::assertSame('Bearer test-bot-token', $http->requests[0]->getHeaderLine('Authorization'));
        self::assertSame(['channel' => 'C123', 'text' => 'Hello'], $http->sentPayload());
        self::assertTrue($response->get('ok'));
    }

    public function test_generated_models_preserve_known_and_unknown_wire_fields(): void
    {
        $request = new ChatPostMessageRequest('C123', text: 'Hello', extra: ['future_option' => true]);
        self::assertSame(['future_option' => true, 'channel' => 'C123', 'text' => 'Hello'], $request->toArray());

        $http = new RecordingHttpClient([
            new Response(
                200,
                [],
                '{"ok":true,"channel":"C123","message":{"type":"message","text":"Hello"},"future_field":{"enabled":true}}',
            ),
        ]);
        $result = new Client($http, 'test-bot-token')
            ->chat()
            ->postMessage($request);

        self::assertInstanceOf(ChatPostMessageResponse::class, $result);
        self::assertSame('C123', $result->channel);
        self::assertSame('Hello', $result->message?->text);
        self::assertSame(['enabled' => true], $result->get('future_field'));
    }

    public function test_token_overrides_do_not_leak_into_payload_or_other_calls(): void
    {
        $http = new RecordingHttpClient();
        $client = new Client($http, 'original-token');
        $client->call('auth.test', ['token' => 'override-token']);
        $client->withToken('scoped-token')->call('auth.test');
        $client->call('auth.test');
        self::assertSame('Bearer override-token', $http->requests[0]->getHeaderLine('Authorization'));
        self::assertSame('Bearer scoped-token', $http->requests[1]->getHeaderLine('Authorization'));
        self::assertSame('Bearer original-token', $http->requests[2]->getHeaderLine('Authorization'));
        self::assertSame('{}', (string) $http->requests[0]->getBody());
    }

    public function test_oauth_uses_form_encoding(): void
    {
        $http = new RecordingHttpClient();
        new Client($http, '')->call('oauth.v2.access', [
            'client_id' => '123',
            'client_secret' => 'a+b&c',
            'code' => 'abc',
        ]);
        self::assertSame('application/x-www-form-urlencoded', $http->requests[0]->getHeaderLine('Content-Type'));
        self::assertSame('client_id=123&client_secret=a%2Bb%26c&code=abc', (string) $http->requests[0]->getBody());
        self::assertSame('', $http->requests[0]->getHeaderLine('Authorization'));
    }

    public function test_oauth_exchange_preserves_the_old_token_in_the_form_without_a_bearer_header(): void
    {
        $http = new RecordingHttpClient();
        new Client($http, 'unrelated-default-token')->call('oauth.v2.exchange', [
            'client_id' => '123',
            'client_secret' => 'secret',
            'token' => 'old+token',
        ]);
        parse_str((string) $http->requests[0]->getBody(), $form);
        self::assertSame('old+token', $form['token']);
        self::assertSame('', $http->requests[0]->getHeaderLine('Authorization'));
    }

    public function test_rate_limit_is_exposed_without_sleeping_inside_an_inbound_request(): void
    {
        $http = new RecordingHttpClient([new Response(
            429,
            ['Retry-After' => '37'],
            '{"ok":false,"error":"ratelimited"}',
        )]);
        try {
            new Client($http, 'test')->call('chat.postMessage');
            self::fail('Expected a rate limit exception.');
        } catch (RateLimited $exception) {
            self::assertSame(37, $exception->retryAfter);
            self::assertCount(1, $http->requests);
        }
    }

    public function test_platform_error_keeps_the_slack_error_code(): void
    {
        $http = new RecordingHttpClient([new Response(200, [], '{"ok":false,"error":"channel_not_found"}')]);
        $this->expectException(SlackApiException::class);
        $this->expectExceptionMessage('channel_not_found');
        new Client($http, 'test')->call('chat.postMessage');
    }

    public function test_rejects_a_url_as_method_without_sending_a_request(): void
    {
        $http = new RecordingHttpClient();
        try {
            new Client($http, 'test')->call('https://example.com');
            self::fail('Expected an invalid method.');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $http->requests);
        }
    }

    public function test_custom_api_origin_must_use_https(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Client(new RecordingHttpClient(), 'test-token', 'http://example.test/api/');
    }

    public function test_typed_message_rejects_invalid_thread_timestamp_before_sending(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ChatPostMessage('C123', 'Hello', 'not-a-timestamp');
    }

    public function test_pagination_is_lazy_and_stops_at_an_empty_cursor(): void
    {
        $http = new RecordingHttpClient([
            new Response(200, [], '{"ok":true,"channels":[{"id":"C1"}],"response_metadata":{"next_cursor":"next"}}'),
            new Response(200, [], '{"ok":true,"channels":[{"id":"C2"}],"response_metadata":{"next_cursor":""}}'),
        ]);
        $pages = new Client($http, 'test')->paginate('conversations.list', ['limit' => 1]);
        self::assertCount(0, $http->requests);
        $results = iterator_to_array($pages);
        self::assertCount(2, $results);
        self::assertSame(['limit' => 1, 'cursor' => 'next'], $http->sentPayload(1));
    }

    public function test_rejects_invalid_json_without_returning_an_untyped_success(): void
    {
        $this->expectException(SlackApiException::class);
        $this->expectExceptionMessage('invalid_json');
        new Client(new RecordingHttpClient([new Response(200, [], '<html>bad gateway</html>')]), 'test')->call(
            'auth.test',
        );
    }

    public function test_recursive_profile_models_and_array_intersections_keep_their_wire_shapes(): void
    {
        $http = new RecordingHttpClient([
            new Response(200, [], '{"ok":true,"user":{"id":"U1","profile":{"display_name":"Ada","future":"kept"}}}'),
            new Response(200, [], '{"ok":true,"response_metadata":{"warnings":["notice"]}}'),
        ]);
        $client = new Client($http, 'token');
        $user = $client->users()->info(['user' => 'U1']);
        self::assertSame('Ada', $user->user?->profile?->displayName);
        self::assertSame('kept', $user->user?->profile?->data['future']);
        $view = $client->views()->update(['view_id' => 'V1']);
        self::assertSame(['notice'], $view->responseMetadata?->warnings);
    }
}
