<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Http;

use PHPUnit\Framework\TestCase;
use Tempest\Container\GenericContainer;
use Thunder\Bolt\App;
use Thunder\Bolt\BoltConfig;
use Thunder\Bolt\Http\Receiver;
use Thunder\Bolt\Invocation\Delivery;
use Thunder\Bolt\Payloads\Events\AppMention;
use Thunder\Bolt\Payloads\SlashCommand;
use Thunder\Bolt\Reply;
use Thunder\Bolt\Responses\CommandResponse;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;

final class ReceiverTest extends TestCase
{
    private const string SECRET = 'test-signing-secret';

    public function test_signed_challenge_is_verified_and_returned_without_a_bot_token(): void
    {
        $config = new BoltConfig('', self::SECRET);
        $receiver = new Receiver(
            new App(new GenericContainer(), new Client(new RecordingHttpClient(), ''), $config),
            $config,
        );
        $raw = '{"type":"url_verification","challenge":"challenge-value"}';
        $response = $receiver->handle($raw, $this->headers($raw));
        self::assertSame(200, $response->status);
        self::assertSame('challenge-value', $response->body);
    }

    public function test_signed_mention_reaches_typed_handler_and_replies_to_existing_thread(): void
    {
        [$app, $receiver, $http] = $this->application();
        $app->event('app_mention', static function (AppMention $event, Reply $reply): void {
            self::assertSame('U123', $event->userId);
            $reply->inThread('Hello');
        });
        $raw = json_encode([
            'type' => 'event_callback',
            'event_id' => 'Ev1',
            'team_id' => 'T1',
            'event' => [
                'type' => 'app_mention',
                'user' => 'U123',
                'channel' => 'C123',
                'text' => '<@BOT> hi',
                'ts' => '1700000000.000002',
                'thread_ts' => '1700000000.000001',
            ],
        ], JSON_THROW_ON_ERROR);
        $response = $receiver->handle($raw, $this->headers($raw));
        self::assertSame(200, $response->status);
        self::assertSame('', $response->body);
        self::assertSame(
            ['channel' => 'C123', 'text' => 'Hello', 'thread_ts' => '1700000000.000001'],
            $http->sentPayload(),
        );
    }

    public function test_form_command_returns_its_initial_ephemeral_response(): void
    {
        [$app, $receiver] = $this->application();
        $app->command('/ping', static fn(SlashCommand $command): CommandResponse => CommandResponse::ephemeral('Pong: '
        . $command->text));
        $raw = http_build_query([
            'command' => '/ping',
            'text' => 'hello world',
            'channel_id' => 'C123',
            'user_id' => 'U123',
        ]);
        $response = $receiver->handle($raw, $this->headers($raw), 'application/x-www-form-urlencoded; charset=utf-8');
        self::assertSame(200, $response->status);
        self::assertSame(
            ['response_type' => 'ephemeral', 'text' => 'Pong: hello world'],
            json_decode($response->body, true),
        );
    }

    public function test_bad_signature_is_rejected_before_json_parsing_and_dispatch(): void
    {
        [$app, $receiver, $http] = $this->application();
        $app->event('app_mention', static fn(Reply $reply) => $reply->inChannel('Must not send'));
        self::assertSame(401, $receiver->handle('not-json', [])->status);
        self::assertSame([], $http->requests);
    }

    public function test_signed_malformed_body_is_bad_request(): void
    {
        [, $receiver] = $this->application();
        self::assertSame(400, $receiver->handle('not-json', $this->headers('not-json'))->status);
    }

    public function test_oversized_body_is_rejected(): void
    {
        $config = new BoltConfig('test', self::SECRET, maxBodyBytes: 10);
        $receiver = new Receiver(
            new App(new GenericContainer(), new Client(new RecordingHttpClient(), 'test'), $config),
            $config,
        );
        self::assertSame(413, $receiver->handle(str_repeat('x', 11))->status);
    }

    public function test_unimplemented_interactivity_is_not_silently_acknowledged(): void
    {
        [, $receiver] = $this->application();
        $raw = json_encode(['type' => 'dialog_cancellation', 'callback_id' => 'legacy-dialog'], JSON_THROW_ON_ERROR);
        self::assertSame(501, $receiver->handle($raw, $this->headers($raw))->status);
    }

    public function test_retry_headers_reach_the_delivery_without_affecting_matching(): void
    {
        [$app, $receiver] = $this->application();
        $metadata = null;
        $app->event('app_mention', static function (Delivery $delivery) use (&$metadata): void {
            $metadata = $delivery->metadata;
        });
        $raw = json_encode([
            'type' => 'event_callback',
            'event' => [
                'type' => 'app_mention',
                'user' => 'U123',
                'channel' => 'C123',
                'text' => 'hello',
                'ts' => '1700000000.000001',
            ],
        ], JSON_THROW_ON_ERROR);
        $response = $receiver->handle($raw, [
            ...$this->headers($raw),
            'X-Slack-Retry-Num' => '2',
            'X-Slack-Retry-Reason' => 'http_timeout',
        ]);
        self::assertSame(200, $response->status);
        self::assertSame(['transport' => 'http', 'retryNum' => 2, 'retryReason' => 'http_timeout'], $metadata);
    }

    /** @return array{App, Receiver, RecordingHttpClient} */
    private function application(): array
    {
        $config = new BoltConfig('test-bot-token', self::SECRET);
        $http = new RecordingHttpClient();
        $app = new App(new GenericContainer(), new Client($http, $config->token), $config);

        return [$app, new Receiver($app, $config), $http];
    }

    /** @return array<string, string> */
    private function headers(string $raw): array
    {
        $timestamp = (string) time();

        return [
            'X-Slack-Signature' => 'v0=' . hash_hmac('sha256', "v0:{$timestamp}:{$raw}", self::SECRET),
            'X-Slack-Request-Timestamp' => $timestamp,
        ];
    }
}
