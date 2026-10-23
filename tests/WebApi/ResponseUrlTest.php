<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\WebApi;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tempest\Container\GenericContainer;
use Thunder\Bolt\App;
use Thunder\Bolt\BoltConfig;
use Thunder\Bolt\Exceptions\MissingReplyContext;
use Thunder\Bolt\Payloads\Shortcut;
use Thunder\Bolt\Reply;
use Thunder\Bolt\Responses\ActionResponse;
use Thunder\Bolt\Responses\ShortcutResponse;
use Thunder\Bolt\Responses\ViewResponse;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;
use Thunder\Bolt\WebApi\RateLimited;
use Thunder\Bolt\WebApi\SlackApiException;

final class ResponseUrlTest extends TestCase
{
    public function test_response_url_uses_opaque_url_without_bot_authorization_and_accepts_plain_ok(): void
    {
        $http = new RecordingHttpClient([new Response(200, [], 'ok')]);
        $url = 'https://hooks.slack.com/actions/T1/opaque%2Ftoken?key=a%2Bb';
        $reply = new Reply(new Client($http, 'must-never-be-forwarded'), 'C1', '1.2', 'U1', $url);

        self::assertTrue($reply->respond('Hello')->get('ok'));
        self::assertSame($url, (string) $http->requests[0]->getUri());
        self::assertSame('POST', $http->requests[0]->getMethod());
        self::assertSame('', $http->requests[0]->getHeaderLine('Authorization'));
        self::assertSame(['text' => 'Hello'], $http->sentPayload());
    }

    public function test_action_reply_helpers_preserve_response_url_replace_and_delete_flags(): void
    {
        $http = new RecordingHttpClient();
        $app = $this->app($http);
        $app->action('approve_order', static function (Reply $reply): ActionResponse {
            $reply->replaceOriginal('Approved');
            $reply->deleteOriginal();

            return ActionResponse::empty();
        });
        $app->dispatch($this->fixture('block-actions-first-action'));
        self::assertSame(['text' => 'Approved', 'replace_original' => true], $http->sentPayload());
        self::assertSame(['delete_original' => true], $http->sentPayload(1));
        self::assertSame('', $http->requests[0]->getHeaderLine('Authorization'));
    }

    public function test_view_respond_uses_first_response_url_and_preserves_all_entries_in_raw_payload(): void
    {
        $http = new RecordingHttpClient();
        $app = $this->app($http);
        $app->view('ticket_form', static function (Reply $reply): ViewResponse {
            $reply->respond(['text' => 'Saved', 'response_type' => 'ephemeral']);

            return ViewResponse::empty();
        });
        $body = $this->fixture('view-submission-response-urls');
        $app->dispatch($body);
        self::assertSame($body['response_urls'][0]['response_url'], (string) $http->requests[0]->getUri());
        self::assertSame(['text' => 'Saved', 'response_type' => 'ephemeral'], $http->sentPayload());
    }

    public function test_message_shortcut_replies_use_the_original_thread_root(): void
    {
        $http = new RecordingHttpClient();
        $app = $this->app($http);
        $app->shortcut('inspect', static function (Shortcut $shortcut, Reply $reply): ShortcutResponse {
            self::assertSame('U1', $shortcut->userId);
            $reply->inThread('Found it');

            return ShortcutResponse::empty();
        });
        $app->dispatch([
            'type' => 'message_action',
            'callback_id' => 'inspect',
            'channel' => ['id' => 'C1'],
            'user' => ['id' => 'U1'],
            'message' => ['ts' => '1.3', 'thread_ts' => '1.2'],
        ]);
        self::assertSame(['channel' => 'C1', 'text' => 'Found it', 'thread_ts' => '1.2'], $http->sentPayload());
    }

    public function test_global_shortcut_does_not_invent_a_response_url_or_channel(): void
    {
        $http = new RecordingHttpClient();
        $app = $this->app($http);
        $missingCapabilities = 0;
        $app->shortcut('open', static function (Reply $reply) use (&$missingCapabilities): ShortcutResponse {
            foreach (['respond', 'inChannel'] as $method) {
                try {
                    $reply->{$method}('Must not send');
                    self::fail('Expected a missing delivery capability.');
                } catch (MissingReplyContext) {
                    $missingCapabilities++;
                }
            }

            return ShortcutResponse::empty();
        });
        $app->dispatch(['type' => 'shortcut', 'callback_id' => 'open']);
        self::assertSame(2, $missingCapabilities);
        self::assertSame([], $http->requests);
    }

    #[DataProvider('invalidUrls')]
    public function test_invalid_response_url_is_rejected_before_transport(string $url): void
    {
        $http = new RecordingHttpClient();
        try {
            new Client($http, 'test')->respond($url, ['text' => 'Hi']);
            self::fail('Expected an invalid response URL.');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $http->requests);
        }
    }

    public static function invalidUrls(): iterable
    {
        yield ['http://hooks.slack.com/secret'];
        yield ['https://user:password@hooks.slack.com/secret'];
        yield ['https://hooks.slack.com/secret#fragment'];
    }

    public function test_response_url_rate_limits_are_exposed_without_sleep_or_retry(): void
    {
        $http = new RecordingHttpClient([new Response(429, ['Retry-After' => '9'], 'rate_limited')]);
        try {
            new Client($http, 'test')->respond('https://hooks.slack.com/secret', []);
            self::fail('Expected rate limiting.');
        } catch (RateLimited $exception) {
            self::assertSame(9, $exception->retryAfter);
            self::assertCount(1, $http->requests);
        }
    }

    public function test_response_url_platform_failure_does_not_become_success(): void
    {
        $this->expectException(SlackApiException::class);
        $this->expectExceptionMessage('expired_url');
        new Client(new RecordingHttpClient([new Response(
            200,
            [],
            '{"ok":false,"error":"expired_url"}',
        )]), 'test')->respond('https://hooks.slack.com/secret', []);
    }

    private function app(RecordingHttpClient $http): App
    {
        return new App(
            new GenericContainer(),
            new Client($http, 'local-token'),
            new BoltConfig('local-token', 'secret'),
        );
    }

    private function fixture(string $name): array
    {
        return json_decode(
            file_get_contents(dirname(__DIR__) . '/Fixtures/interactions/' . $name . '.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }
}
