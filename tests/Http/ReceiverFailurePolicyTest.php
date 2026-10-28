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
}
