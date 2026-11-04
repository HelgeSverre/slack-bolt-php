<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Http;

use PHPUnit\Framework\TestCase;
use Tempest\Container\GenericContainer;
use Thunder\Bolt\App;
use Thunder\Bolt\BoltConfig;
use Thunder\Bolt\Errors\FailureContext;
use Thunder\Bolt\Errors\FailureStage;
use Thunder\Bolt\Http\Receiver;
use Thunder\Bolt\Http\Response;
use Thunder\Bolt\Responses\OptionsResponse;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;

final class ReceiverFailurePolicyTest extends TestCase
{
    public function test_receiver_policy_can_choose_an_error_response_with_delivery_identity(): void
    {
        $config = new BoltConfig(token: 'fixture', signingSecret: 'fixture-secret');
        $app = new App(new GenericContainer(), new Client(new RecordingHttpClient(), 'fixture'), $config);
        $app->event('message', static function (): never {
            throw new \RuntimeException('private exception');
        });
        $seen = null;
        $receiver = new Receiver($app, $config, errorHandler: static function (FailureContext $failure) use (
            &$seen,
        ): Response {
            $seen = $failure;
            return new Response(503, 'Please retry.');
        });
        $response = $this->post($receiver);
        self::assertSame(503, $response->status);
        self::assertSame('Please retry.', $response->body);
        self::assertSame(FailureStage::Receiver, $seen->stage);
        self::assertSame('T1', $seen->summary()['team_id']);
        self::assertSame('Ev1', $seen->summary()['event_id']);
        self::assertSame('http', $seen->summary()['transport']);
        self::assertStringNotContainsString('private exception', json_encode($seen, JSON_THROW_ON_ERROR));
    }

    public function test_receiver_policy_cannot_acknowledge_failed_durable_intake(): void
    {
        $config = new BoltConfig(token: 'fixture', signingSecret: 'fixture-secret');
        $app = new App(new GenericContainer(), new Client(new RecordingHttpClient(), 'fixture'), $config);
        $app->event('message', static function (): never {
            throw new \Thunder\Bolt\Jobs\IntakeFailed('private failure');
        });
        $receiver = new Receiver($app, $config, errorHandler: static fn() => new Response(200));
        self::assertSame(500, $this->post($receiver)->status);
    }

    public function test_invalid_acknowledgement_json_returns_a_controlled_server_error(): void
    {
        $config = new BoltConfig(token: 'fixture', signingSecret: 'fixture-secret');
        $app = new App(new GenericContainer(), new Client(new RecordingHttpClient(), 'fixture'), $config);
        $app->options('assignee', static fn(): OptionsResponse => self::invalidOptionsResponse());

        $response = $this->postOptions(new Receiver($app, $config));

        self::assertSame(500, $response->status);
        self::assertSame('Slack delivery could not be processed.', $response->body);
    }

    public function test_receiver_policy_handles_invalid_acknowledgement_json(): void
    {
        $config = new BoltConfig(token: 'fixture', signingSecret: 'fixture-secret');
        $app = new App(new GenericContainer(), new Client(new RecordingHttpClient(), 'fixture'), $config);
        $app->options('assignee', static fn(): OptionsResponse => self::invalidOptionsResponse());
        $seen = null;
        $receiver = new Receiver($app, $config, errorHandler: static function (FailureContext $failure) use (
            &$seen,
        ): Response {
            $seen = $failure;

            return new Response(503, 'Please retry.');
        });

        $response = $this->postOptions($receiver);

        self::assertSame(503, $response->status);
        self::assertSame('Please retry.', $response->body);
        self::assertSame(FailureStage::Receiver, $seen->stage);
        self::assertInstanceOf(\JsonException::class, $seen->error);
    }

    private function post(Receiver $receiver): Response
    {
        $body = json_encode([
            'type' => 'event_callback',
            'team_id' => 'T1',
            'event_id' => 'Ev1',
            'event' => ['type' => 'message', 'user' => 'U1', 'channel' => 'C1', 'text' => 'hi', 'ts' => '1.000001'],
        ], JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        return $receiver->handle($body, [
            'X-Slack-Request-Timestamp' => $timestamp,
            'X-Slack-Signature' => 'v0=' . hash_hmac('sha256', "v0:{$timestamp}:{$body}", 'fixture-secret'),
        ]);
    }

    private function postOptions(Receiver $receiver): Response
    {
        $body = http_build_query(['payload' => json_encode([
            'type' => 'block_suggestion',
            'action_id' => 'assignee',
            'block_id' => 'assignment',
            'value' => '',
        ], JSON_THROW_ON_ERROR)]);
        $timestamp = (string) time();

        return $receiver->handle(
            $body,
            [
                'X-Slack-Request-Timestamp' => $timestamp,
                'X-Slack-Signature' => 'v0=' . hash_hmac('sha256', "v0:{$timestamp}:{$body}", 'fixture-secret'),
            ],
            'application/x-www-form-urlencoded',
        );
    }

    private static function invalidOptionsResponse(): OptionsResponse
    {
        return OptionsResponse::fromArray([
            'options' => [[
                'text' => ['type' => 'plain_text', 'text' => "\xB1"],
                'value' => 'U1',
            ]],
        ]);
    }
}
