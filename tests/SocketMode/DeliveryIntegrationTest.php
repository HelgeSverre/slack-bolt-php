<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\SocketMode;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;
use Tempest\Container\GenericContainer;
use Thunder\Bolt\App;
use Thunder\Bolt\Authorization\Authorization;
use Thunder\Bolt\Authorization\AuthorizationSource;
use Thunder\Bolt\Authorization\CallbackAuthorizer;
use Thunder\Bolt\BoltConfig;
use Thunder\Bolt\Conversation\ConversationContext;
use Thunder\Bolt\Conversation\Stores\MemoryConversationStore;
use Thunder\Bolt\Conversation\ThreadContext;
use Thunder\Bolt\Invocation\Delivery;
use Thunder\Bolt\Reply;
use Thunder\Bolt\Responses\Acknowledgement;
use Thunder\Bolt\Routing\InteractionConstraints;
use Thunder\Bolt\Routing\Regex;
use Thunder\Bolt\SocketMode\Config;
use Thunder\Bolt\SocketMode\Runner;
use Thunder\Bolt\SocketMode\Transport\Connection;
use Thunder\Bolt\SocketMode\Transport\Connector;
use Thunder\Bolt\Tests\Http\InteractivityTest;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;

final class DeliveryIntegrationTest extends TestCase
{
    #[DataProviderExternal(InteractivityTest::class, 'acknowledgements')]
    public function test_socket_interactions_preserve_the_http_acknowledgement_contract(
        string $method,
        string|Regex|InteractionConstraints $selector,
        array $body,
        Acknowledgement $acknowledgement,
        array $expected,
    ): void {
        $http = new RecordingHttpClient([
            new Response(200, [], '{"ok":true,"url":"wss://socket.example.test/"}'),
        ]);
        $client = new Client($http, 'test-bot');
        $app = new App(new GenericContainer(), $client, new BoltConfig(token: 'test-bot'));
        $app->{$method}($selector, static fn() => $acknowledgement);
        $connection = new class($body) implements Connection {
            public ?string $sent = null;
            public int $received = 0;
            private array $frames;

            public function __construct(array $body)
            {
                $this->frames = [
                    '{"type":"hello"}',
                    json_encode([
                        'type' => 'interactive',
                        'envelope_id' => 'interaction',
                        'accepts_response_payload' => true,
                        'payload' => $body,
                    ], JSON_THROW_ON_ERROR),
                ];
            }

            public function receive(float $timeoutSeconds): ?string
            {
                $this->received++;
                return array_shift($this->frames);
            }

            public function send(string $payload): void
            {
                $this->sent = $payload;
            }

            public function close(): void {}
        };
        $connector = new class($connection) implements Connector {
            public function __construct(
                private Connection $connection,
            ) {}

            public function connect(string $url): Connection
            {
                return $this->connection;
            }
        };
        new Runner($app, $client, new Config('xapp-test'), $connector)->run(
            static fn(): bool => $connection->received >= 2,
        );

        $response = json_decode($connection->sent, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('interaction', $response['envelope_id']);
        self::assertSame($expected, $response['payload'] ?? []);
        if ($expected === [] && array_key_exists('payload', $response)) {
            self::assertStringContainsString('"payload":{}', $connection->sent);
        }
    }

    public function test_one_connection_keeps_installation_credentials_and_context_isolated_between_deliveries(): void
    {
        $http = new RecordingHttpClient([
            new Response(200, [], '{"ok":true,"url":"wss://socket.example.test/?ticket=private"}'),
        ]);
        $client = new Client($http, 'root-token-must-not-be-used');
        $authorized = [];
        $app = new App(
            new GenericContainer(),
            $client,
            new BoltConfig(),
            CallbackAuthorizer::from(static function (AuthorizationSource $source) use (&$authorized): Authorization {
                $authorized[] = $source->teamId;

                return new Authorization(botToken: 'bot-' . $source->teamId, teamId: $source->teamId);
            }),
            new MemoryConversationStore(),
        );
        $seen = [];
        $app->event('app_mention', static function (
            Delivery $delivery,
            ConversationContext $conversation,
            ThreadContext $thread,
            Reply $reply,
        ) use (&$seen): void {
            $seen[] = [
                $delivery->authorization?->teamId,
                $conversation->value(0),
                $thread->value(0),
                $delivery->metadata,
            ];
            $conversation->update(static fn(mixed $value): int => ($value ?? 0) + 1);
            $thread->update(static fn(mixed $value): int => ($value ?? 0) + 1);
            $reply->inThread('Hello');
        });
        $messages = [json_encode(['type' => 'hello'], JSON_THROW_ON_ERROR)];
        foreach (['T1', 'T2', 'T1'] as $index => $team) {
            $messages[] = json_encode([
                'type' => 'events_api',
                'envelope_id' => 'envelope-' . $index,
                'accepts_response_payload' => false,
                'retry_attempt' => $index,
                'retry_reason' => 'timeout',
                'payload' => [
                    'type' => 'event_callback',
                    'team_id' => $team,
                    'event' => [
                        'type' => 'app_mention',
                        'user' => 'U1',
                        'channel' => 'C1',
                        'ts' => '100.001',
                        'text' => 'Hello',
                    ],
                ],
            ], JSON_THROW_ON_ERROR);
        }
        $connection = new class($messages) implements Connection {
            public array $sent = [];
            public bool $closed = false;
            public int $received = 0;

            public function __construct(
                private array $messages,
            ) {}

            public function receive(float $timeoutSeconds): ?string
            {
                $this->received++;
                return array_shift($this->messages);
            }

            public function send(string $payload): void
            {
                $this->sent[] = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
            }

            public function close(): void
            {
                $this->closed = true;
            }
        };
        $connector = new class($connection) implements Connector {
            public function __construct(
                private Connection $connection,
            ) {}

            public function connect(string $url): Connection
            {
                return $this->connection;
            }
        };
        $runner = new Runner($app, $client, new Config(appToken: 'xapp-test'), $connector);
        $runner->run(static fn(): bool => $connection->received >= 4);

        self::assertSame(['T1', 'T2', 'T1'], $authorized);
        self::assertSame([0, 0, 1], array_column($seen, 1));
        self::assertSame([0, 0, 1], array_column($seen, 2));
        foreach ($seen as $index => $delivery) {
            self::assertSame($index, $delivery[3]['retryNum']);
            self::assertSame('timeout', $delivery[3]['retryReason']);
            self::assertSame('socket_mode', $delivery[3]['transport']);
            self::assertSame('envelope-' . $index, $delivery[3]['envelopeId']);
        }
        self::assertSame('Bearer xapp-test', $http->requests[0]->getHeaderLine('Authorization'));
        foreach (['T1', 'T2', 'T1'] as $index => $team) {
            self::assertSame('Bearer bot-' . $team, $http->requests[$index + 1]->getHeaderLine('Authorization'));
        }
        self::assertSame(
            [
                ['envelope_id' => 'envelope-0'],
                ['envelope_id' => 'envelope-1'],
                ['envelope_id' => 'envelope-2'],
            ],
            $connection->sent,
        );
        self::assertTrue($connection->closed);
    }
}
