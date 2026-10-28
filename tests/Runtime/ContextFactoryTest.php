<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Runtime;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Tempest\Container\GenericContainer;
use Thunder\Bolt\App;
use Thunder\Bolt\Authorization\Authorization;
use Thunder\Bolt\Authorization\AuthorizationException;
use Thunder\Bolt\BoltConfig;
use Thunder\Bolt\Context;
use Thunder\Bolt\Errors\ErrorHandling;
use Thunder\Bolt\Errors\FailureContext;
use Thunder\Bolt\Errors\FailureStage;
use Thunder\Bolt\Http\ContextInput as HttpInput;
use Thunder\Bolt\Http\Receiver;
use Thunder\Bolt\Invocation\Delivery;
use Thunder\Bolt\SocketMode\Config;
use Thunder\Bolt\SocketMode\ContextInput as SocketInput;
use Thunder\Bolt\SocketMode\Runner;
use Thunder\Bolt\SocketMode\Transport\Connection;
use Thunder\Bolt\SocketMode\Transport\Connector;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;

final class ContextFactoryTest extends TestCase
{
    private const string SECRET = 'test-signing-secret';

    public function test_http_enrichment_runs_after_authorization_before_middleware_and_is_fresh_per_delivery(): void
    {
        $order = [];
        $inputs = [];
        $contexts = [];
        $config = new BoltConfig(
            token: 'fixture',
            signingSecret: self::SECRET,
            httpContextFactory: function (HttpInput $input) use (&$order, &$inputs): void {
                $order[] = 'factory';
                self::assertSame('T1', $input->delivery->authorization?->teamId);
                self::assertFalse($input->delivery->context->has('account'));
                $input->delivery->context->set('account', new \stdClass())->set('trace', $input->headers['x-trace']);
                $input->delivery->context->set('authorization', 'application-owned value');
                $inputs[] = $input;
            },
        );
        $app = $this->app($config)->authorizeUsing(function () use (&$order): Authorization {
            $order[] = 'authorize';
            return new Authorization(botToken: 'authorized', teamId: 'T1');
        });
        $app->use(static function (Delivery $delivery, \Closure $next) use (&$order): mixed {
            $order[] = 'middleware';
            self::assertSame('trace-1', $delivery->context->require('trace'));
            return $next();
        });
        $app->event('message', static function (Context $context, Authorization $authorization) use (
            &$order,
            &$contexts,
        ): void {
            $order[] = 'handler';
            self::assertSame('T1', $authorization->teamId);
            self::assertSame('authorized', $authorization->token());
            $contexts[] = $context;
        });
        $receiver = new Receiver($app, $config);
        $raw = $this->raw();
        foreach ([1, 2] as $_) {
            self::assertSame(200, $receiver->handle($raw, $this->headers($raw) + ['X-Trace' => 'trace-1'])->status);
        }
        self::assertSame(
            ['authorize', 'factory', 'middleware', 'handler', 'authorize', 'factory', 'middleware', 'handler'],
            $order,
        );
        self::assertNotSame($contexts[0], $contexts[1]);
        self::assertNotSame($contexts[0]->require('account'), $contexts[1]->require('account'));
        self::assertSame($contexts[0], $inputs[0]->delivery->context);
        self::assertSame($raw, $inputs[0]->rawBody);
        self::assertSame('application/json', $inputs[0]->contentType);
        self::assertStringNotContainsString('trace-1', print_r($inputs[0], true));
        $this->expectException(\LogicException::class);
        serialize($inputs[0]);
    }

    public function test_http_factory_is_not_called_for_unverified_denied_probe_challenge_or_ignored_deliveries(): void
    {
        $called = 0;
        $config = new BoltConfig(token: 'fixture', signingSecret: self::SECRET, httpContextFactory: function () use (
            &$called,
        ): void {
            $called++;
        });
        $app = $this->app($config);
        $receiver = new Receiver($app, $config);
        self::assertSame(401, $receiver->handle($this->raw())->status);
        self::assertSame(200, $receiver->handle('ssl_check=1', [], 'application/x-www-form-urlencoded')->status);
        $challenge = '{"type":"url_verification","challenge":"abc"}';
        self::assertSame(200, $receiver->handle($challenge, $this->headers($challenge))->status);
        $app->authorizeUsing(static function (): never {
            throw new AuthorizationException('denied');
        });
        self::assertSame(500, $receiver->handle($this->raw(), $this->headers($this->raw()))->status);
        $app->authorizeUsing(static fn(): Authorization => new Authorization(
            botToken: 'token',
            botUserId: 'U1',
            teamId: 'T1',
        ));
        self::assertSame(200, $receiver->handle($this->raw(), $this->headers($this->raw()))->status);
        self::assertSame(0, $called);
    }

    public function test_factory_failure_reports_context_stage_and_cannot_be_swallowed_into_an_ack(): void
    {
        $seen = [];
        $handlerCalled = false;
        $config = new BoltConfig(
            token: 'fixture',
            signingSecret: self::SECRET,
            httpContextFactory: static function (HttpInput $input): void {
                $input->delivery->context->set('private', 'sensitive-value');
                throw new \RuntimeException('private failure');
            },
        );
        $app = $this->app($config)->onError(static function (FailureContext $failure) use (&$seen): ErrorHandling {
            $seen[] = $failure;
            return ErrorHandling::Handled;
        });
        $app->event('message', static function () use (&$handlerCalled): void {
            $handlerCalled = true;
        });
        $raw = $this->raw();
        $response = new Receiver($app, $config)->handle($raw, $this->headers($raw));
        self::assertSame(500, $response->status);
        self::assertFalse($handlerCalled);
        self::assertCount(1, $seen);
        self::assertSame(FailureStage::Context, $seen[0]->stage);
        self::assertSame('sensitive-value', $seen[0]->delivery?->context->get('private'));
        self::assertFalse($seen[0]->acknowledgementPrepared);
        self::assertStringNotContainsString('sensitive-value', json_encode($seen[0], JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('private failure', $response->body);
    }

    public function test_socket_factory_receives_the_envelope_before_middleware_without_reusing_context(): void
    {
        $http = new RecordingHttpClient([new Response(200, body: '{"ok":true,"url":"wss://socket.slack.test"}')]);
        $client = new Client($http, 'fixture');
        $app = new App(new GenericContainer(), $client, new BoltConfig(token: 'fixture'));
        $seen = [];
        $app->use(static function (Delivery $delivery, \Closure $next): mixed {
            self::assertSame($delivery->metadata['envelopeId'], $delivery->context->get('envelope'));
            return $next();
        });
        $app->event('message', static function (Context $context) use (&$seen): void {
            $seen[] = $context;
        });
        $frames = [json_encode(['type' => 'hello'], JSON_THROW_ON_ERROR)];
        foreach (['E1', 'E2'] as $id) {
            $frames[] = json_encode([
                'type' => 'events_api',
                'envelope_id' => $id,
                'payload' => json_decode($this->raw(), true),
            ], JSON_THROW_ON_ERROR);
        }
        $inputs = [];
        $sent = $this->runFrames(
            $app,
            $client,
            $frames,
            new Config(appToken: 'xapp-test', contextFactory: static function (SocketInput $input) use (
                &$inputs,
            ): void {
                self::assertFalse($input->delivery->context->has('envelope'));
                $input->delivery->context->set('envelope', $input->envelope['envelope_id']);
                $inputs[] = $input;
            }),
        );
        self::assertSame([['envelope_id' => 'E1'], ['envelope_id' => 'E2']], $sent);
        self::assertCount(2, $inputs);
        self::assertNotSame($seen[0], $seen[1]);
        self::assertSame('E1', $seen[0]->get('envelope'));
        self::assertSame('E2', $seen[1]->get('envelope'));
        self::assertStringNotContainsString('fixture', print_r($inputs[0], true));
        $this->expectException(\LogicException::class);
        serialize($inputs[0]);
    }

    public function test_socket_factory_failure_has_no_ack_and_does_not_poison_the_next_envelope(): void
    {
        $http = new RecordingHttpClient([new Response(200, body: '{"ok":true,"url":"wss://socket.slack.test"}')]);
        $client = new Client($http, 'fixture');
        $app = new App(new GenericContainer(), $client, new BoltConfig(token: 'fixture'));
        $failures = [];
        $app->onError(static function (FailureContext $failure) use (&$failures): ErrorHandling {
            $failures[] = $failure;
            return ErrorHandling::Handled;
        });
        $handled = [];
        $app->event('message', static function (Context $context) use (&$handled): void {
            $handled[] = $context->get('envelope');
        });
        $frames = [json_encode(['type' => 'hello'], JSON_THROW_ON_ERROR)];
        foreach (['bad', 'good'] as $id) {
            $frames[] = json_encode([
                'type' => 'events_api',
                'envelope_id' => $id,
                'payload' => json_decode($this->raw(), true),
            ], JSON_THROW_ON_ERROR);
        }
        $sent = $this->runFrames(
            $app,
            $client,
            $frames,
            new Config(appToken: 'xapp-test', contextFactory: static function (SocketInput $input): void {
                self::assertFalse($input->delivery->context->has('envelope'));
                $input->delivery->context->set('envelope', $input->envelope['envelope_id']);
                if ($input->envelope['envelope_id'] === 'bad')
                    throw new \RuntimeException('private');
            }),
        );
        self::assertSame([['envelope_id' => 'good']], $sent);
        self::assertSame(['good'], $handled);
        self::assertCount(1, $failures);
        self::assertSame(FailureStage::Context, $failures[0]->stage);
        self::assertSame('bad', $failures[0]->summary()['envelope_id']);
    }

    private function runFrames(App $app, Client $client, array $frames, Config $config): array
    {
        $sent = [];
        $connection = $this->createStub(Connection::class);
        $connection
            ->method('receive')
            ->willReturnCallback(static function () use (&$frames): ?string {
                return array_shift($frames);
            });
        $connection
            ->method('send')
            ->willReturnCallback(static function (string $frame) use (&$sent): void {
                $sent[] = json_decode($frame, true);
            });
        $connector = $this->createStub(Connector::class);
        $connector->method('connect')->willReturn($connection);
        new Runner($app, $client, $config, $connector)->run(static function () use (&$frames): bool {
            return $frames === [];
        });
        return $sent;
    }

    private function app(BoltConfig $config): App
    {
        return new App(new GenericContainer(), new Client(new RecordingHttpClient(), 'fixture'), $config);
    }

    private function raw(): string
    {
        return '{"type":"event_callback","team_id":"T1","event_id":"Ev1","event":{"type":"message","user":"U1","channel":"C1","text":"hello","ts":"1.1"}}';
    }

    private function headers(string $raw): array
    {
        $timestamp = (string) time();
        return [
            'X-Slack-Signature' => 'v0=' . hash_hmac('sha256', "v0:{$timestamp}:{$raw}", self::SECRET),
            'X-Slack-Request-Timestamp' => $timestamp,
        ];
    }
}
