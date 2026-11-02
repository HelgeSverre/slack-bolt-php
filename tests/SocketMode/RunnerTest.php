<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\SocketMode;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Tempest\Container\GenericContainer;
use Thunder\Bolt\App;
use Thunder\Bolt\BoltConfig;
use Thunder\Bolt\Invocation\Delivery;
use Thunder\Bolt\Responses\CommandResponse;
use Thunder\Bolt\SocketMode\AuthenticationException;
use Thunder\Bolt\SocketMode\Config;
use Thunder\Bolt\SocketMode\Runner;
use Thunder\Bolt\SocketMode\Transport\Connection;
use Thunder\Bolt\SocketMode\Transport\ConnectionClosed;
use Thunder\Bolt\SocketMode\Transport\Connector;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;

final class RunnerTest extends TestCase
{
    public function test_it_waits_for_hello_then_dispatches_events_and_acknowledges_them(): void
    {
        $http = new RecordingHttpClient([new Response(200, [], json_encode([
            'ok' => true,
            'url' => 'wss://socket.slack.test/?ticket=secret',
        ], JSON_THROW_ON_ERROR))]);
        $client = new Client($http, 'xoxb-bot');
        $app = new App(new GenericContainer(), $client, new BoltConfig(token: 'xoxb-bot'));
        $metadata = [];
        $app->event('app_mention', static function (Delivery $delivery) use (&$metadata): void {
            $metadata = $delivery->metadata;
        });
        $connection = new RecordingConnection([
            self::frame(['type' => 'hello']),
            self::frame([
                'type' => 'events_api',
                'envelope_id' => 'E1',
                'retry_attempt' => 2,
                'retry_reason' => 'http_timeout',
                'accepts_response_payload' => false,
                'payload' => [
                    'type' => 'event_callback',
                    'team_id' => 'T1',
                    'event' => [
                        'type' => 'app_mention',
                        'user' => 'U1',
                        'channel' => 'C1',
                        'text' => 'Hello',
                        'ts' => '1.000001',
                    ],
                ],
            ]),
        ]);

        $this->runner($app, $client, $connection)->run(static fn(): bool => $connection->receiveCalls >= 2);

        self::assertSame(['envelope_id' => 'E1'], self::sent($connection, 0));
        self::assertSame(
            [
                'transport' => 'socket_mode',
                'envelopeId' => 'E1',
                'retryNum' => 2,
                'retryReason' => 'http_timeout',
            ],
            $metadata,
        );
        self::assertSame('Bearer xapp-app-token', $http->requests[0]->getHeaderLine('Authorization'));
        self::assertTrue($connection->closed);
    }

    public function test_it_sends_an_interaction_response_only_when_the_envelope_accepts_it(): void
    {
        $http = new RecordingHttpClient([new Response(200, [], json_encode([
            'ok' => true,
            'url' => 'wss://socket.slack.test/?ticket=secret',
        ], JSON_THROW_ON_ERROR))]);
        $client = new Client($http, 'xoxb-bot');
        $app = new App(new GenericContainer(), $client, new BoltConfig(token: 'xoxb-bot'));
        $app->command('/hello', static fn(): CommandResponse => CommandResponse::ephemeral('Hi'));
        $connection = new RecordingConnection([
            self::frame(['type' => 'hello']),
            self::frame(self::commandEnvelope('E1', true)),
            self::frame(self::commandEnvelope('E2', false)),
        ]);

        $this->runner($app, $client, $connection)->run(static fn(): bool => $connection->receiveCalls >= 3);

        self::assertSame(
            [
                'envelope_id' => 'E1',
                'payload' => ['response_type' => 'ephemeral', 'text' => 'Hi'],
            ],
            self::sent($connection, 0),
        );
        self::assertSame(['envelope_id' => 'E2'], self::sent($connection, 1));
    }

    public function test_it_does_not_redispatch_a_frame_when_its_acknowledgement_send_fails(): void
    {
        $http = new RecordingHttpClient([new Response(200, [], json_encode([
            'ok' => true,
            'url' => 'wss://socket.slack.test/?ticket=secret',
        ], JSON_THROW_ON_ERROR))]);
        $client = new Client($http, 'xoxb-bot');
        $app = new App(new GenericContainer(), $client, new BoltConfig(token: 'xoxb-bot'));
        $calls = 0;
        $app->command('/hello', static function () use (&$calls): CommandResponse {
            $calls++;

            return CommandResponse::empty();
        });
        $connection = new RecordingConnection([
            self::frame(['type' => 'hello']),
            self::frame(self::commandEnvelope('E1', true)),
        ], failSend: true);

        $this->runner($app, $client, $connection)->run(static fn(): bool => $connection->sendCalls >= 1);

        self::assertSame(1, $calls);
        self::assertSame(1, $connection->sendCalls);
        self::assertSame([], $connection->sent);
    }

    public function test_it_ignores_malformed_and_unsupported_deliveries_without_acknowledging_them(): void
    {
        $http = new RecordingHttpClient([new Response(200, [], json_encode([
            'ok' => true,
            'url' => 'wss://socket.slack.test/?ticket=secret',
        ], JSON_THROW_ON_ERROR))]);
        $client = new Client($http, 'xoxb-bot');
        $connection = new RecordingConnection([
            self::frame(['type' => 'hello']),
            '{not-json',
            self::frame(['type' => 'unsupported', 'envelope_id' => 'E1', 'payload' => []]),
        ]);

        $this->runner($this->app($client), $client, $connection)->run(
            static fn(): bool => $connection->receiveCalls >= 3,
        );

        self::assertSame([], $connection->sent);
    }

    public function test_refresh_requested_opens_a_new_url_without_backoff(): void
    {
        $http = new RecordingHttpClient([
            new Response(200, [], '{"ok":true,"url":"wss://socket-one.slack.test/?ticket=secret"}'),
            new Response(200, [], '{"ok":true,"url":"wss://socket-two.slack.test/?ticket=secret"}'),
        ]);
        $client = new Client($http, 'xoxb-bot');
        $connection = new RecordingConnection([
            self::frame(['type' => 'hello']),
            self::frame(['type' => 'disconnect', 'reason' => 'refresh_requested']),
            self::frame(['type' => 'hello']),
            self::frame(self::commandEnvelope('E1', true)),
        ]);
        $app = $this->app($client);
        $app->command('/hello', static fn(): CommandResponse => CommandResponse::empty());

        $this->runner($app, $client, $connection)->run(static fn(): bool => $connection->receiveCalls >= 4);

        self::assertCount(2, $http->requests);
        self::assertSame(['envelope_id' => 'E1'], self::sent($connection, 0));
    }

    public function test_link_disabled_stops_without_reopening_a_connection(): void
    {
        $http = new RecordingHttpClient([new Response(
            200,
            [],
            '{"ok":true,"url":"wss://socket.slack.test/?ticket=secret"}',
        )]);
        $client = new Client($http, 'xoxb-bot');
        $connection = new RecordingConnection([
            self::frame(['type' => 'hello']),
            self::frame(['type' => 'disconnect', 'reason' => 'link_disabled']),
        ]);

        $this->runner($this->app($client), $client, $connection)->run();

        self::assertCount(1, $http->requests);
        self::assertTrue($connection->closed);
    }

    public function test_rejected_app_token_is_not_retried(): void
    {
        $http = new RecordingHttpClient([new Response(200, [], '{"ok":false,"error":"invalid_auth"}')]);
        $client = new Client($http, 'xoxb-bot');

        $this->expectException(AuthenticationException::class);
        $this->runner($this->app($client), $client, new RecordingConnection([]))->run();

        self::assertCount(1, $http->requests);
    }

    public function test_failed_handlers_are_not_acknowledged(): void
    {
        $http = new RecordingHttpClient([new Response(
            200,
            [],
            '{"ok":true,"url":"wss://socket.slack.test/?ticket=secret"}',
        )]);
        $client = new Client($http, 'xoxb-bot');
        $app = $this->app($client);
        $app->command('/hello', static function (): never {
            throw new \RuntimeException('handler failed');
        });
        $connection = new RecordingConnection([
            self::frame(['type' => 'hello']),
            self::frame(self::commandEnvelope('E1', true)),
        ]);

        $this->runner($app, $client, $connection)->run(static fn(): bool => $connection->receiveCalls >= 2);

        self::assertSame([], $connection->sent);
    }

    public function test_hello_deadline_retries_with_the_configured_backoff(): void
    {
        $http = new RecordingHttpClient([new Response(
            200,
            [],
            '{"ok":true,"url":"wss://socket.slack.test/?ticket=secret"}',
        )]);
        $client = new Client($http, 'xoxb-bot');
        $connection = new RecordingConnection([]);
        $time = 1.0;
        $clockCalls = 0;
        $sleeps = [];
        $runner = new Runner(
            $this->app($client),
            $client,
            new Config(appToken: 'xapp-app-token', helloTimeoutSeconds: 0.1, initialBackoffMilliseconds: 100),
            new RecordingConnector($connection),
            sleep: static function (float $seconds) use (&$time, &$sleeps): void {
                $sleeps[] = $seconds;
                $time += $seconds;
            },
            clock: static function () use (&$time, &$clockCalls): float {
                $clockCalls++;

                return $clockCalls === 1 ? 0.0 : $time;
            },
        );

        $runner->run(static function () use (&$sleeps): bool {
            return $sleeps !== [];
        });

        self::assertSame([0.1], $sleeps);
        self::assertSame(0, $connection->receiveCalls);
    }

    public function test_reconnect_backoff_is_capped_and_interruptible(): void
    {
        $client = new Client(new RecordingHttpClient([
            new Response(200, [], '{"ok":true,"url":"wss://socket-one.slack.test/?ticket=secret"}'),
            new Response(200, [], '{"ok":true,"url":"wss://socket-two.slack.test/?ticket=secret"}'),
        ]), 'xoxb-bot');
        $time = 0.0;
        $sleeps = [];
        $runner = new Runner(
            $this->app($client),
            $client,
            new Config(appToken: 'xapp-app-token', initialBackoffMilliseconds: 100, maxBackoffMilliseconds: 150),
            new FailingConnector(),
            sleep: static function (float $seconds) use (&$time, &$sleeps): void {
                $sleeps[] = $seconds;
                $time += $seconds;
            },
            clock: static function () use (&$time): float {
                return $time;
            },
        );

        $runner->run(static function () use (&$sleeps): bool {
            return count($sleeps) >= 3;
        });

        // The runner checks for a stop signal every 100ms, so its 150ms second
        // backoff is represented by two interruptible sleep chunks.
        self::assertEqualsWithDelta([0.1, 0.1, 0.05], $sleeps, 0.000_001);
    }

    public function test_rate_limited_url_requests_wait_for_slacks_retry_after_value(): void
    {
        $client = new Client(new RecordingHttpClient([
            new Response(429, ['Retry-After' => '2'], 'rate_limited'),
        ]), 'xoxb-bot');
        $time = 0.0;
        $runner = new Runner(
            $this->app($client),
            $client,
            new Config(appToken: 'xapp-app-token'),
            new FailingConnector(),
            sleep: static function (float $seconds) use (&$time): void {
                $time += $seconds;
            },
            clock: static function () use (&$time): float {
                return $time;
            },
        );

        $runner->run(static function () use (&$time): bool {
            return $time >= 2.0;
        });

        self::assertGreaterThanOrEqual(2.0, $time);
        self::assertLessThan(2.001, $time);
    }

    public function test_monitor_confirms_hello_and_removes_the_record_on_graceful_stop(): void
    {
        $directory = sys_get_temp_dir() . '/bolt-socket-monitor-' . bin2hex(random_bytes(6));
        $monitor = new \Thunder\Bolt\Runtime\RuntimeMonitor($directory);
        $http = new RecordingHttpClient([new Response(
            200,
            [],
            '{"ok":true,"url":"wss://socket.slack.test/?ticket=private"}',
        )]);
        $client = new Client($http, 'xoxb-fixture');
        $app = $this->app($client);
        $observed = false;
        $app->command('/hello', static function () use ($monitor, &$observed): CommandResponse {
            $records = $monitor->snapshot();
            self::assertCount(1, $records);
            self::assertSame('connected', $records[0]['state']);
            self::assertFalse($records[0]['stale']);
            self::assertStringNotContainsString('private', json_encode($records, JSON_THROW_ON_ERROR));
            $observed = true;
            return CommandResponse::empty();
        });
        $connection = new RecordingConnection([
            self::frame(['type' => 'hello']),
            self::frame(self::commandEnvelope('E-monitor', false)),
        ]);
        try {
            new Runner(
                $app,
                $client,
                new Config(appToken: 'xapp-fixture'),
                new RecordingConnector($connection),
                monitor: $monitor,
            )->run(static fn(): bool => $connection->receiveCalls >= 2);
            self::assertTrue($observed);
            self::assertSame([], $monitor->snapshot());
        } finally {
            foreach (glob($directory . '/*') ?: [] as $file)
                unlink($file);
            if (is_dir($directory))
                rmdir($directory);
        }
    }

    private function runner(App $app, Client $client, RecordingConnection $connection): Runner
    {
        return new Runner(
            $app,
            $client,
            new Config(appToken: 'xapp-app-token', receiveTimeoutSeconds: 0.01, helloTimeoutSeconds: 0.1),
            new RecordingConnector($connection),
        );
    }

    private function app(Client $client): App
    {
        return new App(new GenericContainer(), $client, new BoltConfig(token: 'xoxb-bot'));
    }

    /** @return array<string, mixed> */
    private static function commandEnvelope(string $envelopeId, bool $acceptsResponsePayload): array
    {
        return [
            'type' => 'slash_commands',
            'envelope_id' => $envelopeId,
            'accepts_response_payload' => $acceptsResponsePayload,
            'payload' => [
                'command' => '/hello',
                'text' => 'there',
                'user_id' => 'U1',
                'channel_id' => 'C1',
                'response_url' => 'https://hooks.slack.com/commands/secret',
            ],
        ];
    }

    /** @param array<string, mixed> $message */
    private static function frame(array $message): string
    {
        return json_encode($message, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private static function sent(RecordingConnection $connection, int $offset): array
    {
        return json_decode($connection->sent[$offset], true, flags: JSON_THROW_ON_ERROR);
    }
}

final class RecordingConnector implements Connector
{
    public ?string $url = null;

    public function __construct(
        private readonly RecordingConnection $connection,
    ) {}

    public function connect(string $url): Connection
    {
        $this->url = $url;

        return $this->connection;
    }
}

final class RecordingConnection implements Connection
{
    /** @var list<string|null> */
    private array $frames;

    /** @var list<string> */
    public array $sent = [];

    public int $receiveCalls = 0;

    public int $sendCalls = 0;

    public bool $closed = false;

    /** @param list<string|null> $frames */
    public function __construct(
        array $frames,
        private readonly bool $failSend = false,
    ) {
        $this->frames = $frames;
    }

    public function receive(float $timeoutSeconds): ?string
    {
        $this->receiveCalls++;

        return array_shift($this->frames);
    }

    public function send(string $payload): void
    {
        $this->sendCalls++;
        if ($this->failSend) {
            throw new ConnectionClosed('closed');
        }
        $this->sent[] = $payload;
    }

    public function close(): void
    {
        $this->closed = true;
    }
}

final class FailingConnector implements Connector
{
    public function connect(string $url): Connection
    {
        throw new ConnectionClosed('closed');
    }
}
