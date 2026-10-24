<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Tempest\Container\Container;
use Tempest\Container\GenericContainer;
use Thunder\Bolt\App;
use Thunder\Bolt\Authorization\Authorization;
use Thunder\Bolt\Authorization\AuthorizationSource;
use Thunder\Bolt\BoltConfig;
use Thunder\Bolt\Http\Receiver;
use Thunder\Bolt\Invocation\Delivery;
use Thunder\Bolt\Payloads\Events\AppMention;
use Thunder\Bolt\Reply;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;

final class AuthorizationDispatchTest extends TestCase
{
    public function test_constructor_and_method_clients_use_the_delivery_token_without_rebinding_the_root_container(): void
    {
        $http = new RecordingHttpClient();
        $client = new Client($http, 'root-token');
        $container = new GenericContainer();
        $container->singleton(Client::class, $client);
        $app = new App($container, $client, new BoltConfig(signingSecret: 'secret'));
        $app->authorizeUsing(static fn(AuthorizationSource $source): Authorization => new Authorization(
            botToken: 'token-' . $source->teamId,
            teamId: $source->teamId,
        ));
        $app->event('app_mention', AuthorizedConstructorListener::class);
        $app->event('app_mention', static function (
            Client $client,
            Authorization $authorization,
            Delivery $delivery,
        ): void {
            self::assertSame($authorization, $delivery->authorization);
            self::assertSame($client, $delivery->client);
            $client->call('auth.test');
        });

        $app->dispatch($this->mention('T1'));
        $app->dispatch($this->mention('T2'));

        self::assertSame(
            ['Bearer token-T1', 'Bearer token-T1', 'Bearer token-T2', 'Bearer token-T2'],
            array_map(static fn($request): string => $request->getHeaderLine('Authorization'), $http->requests),
        );
        self::assertSame($client, $container->get(Client::class));
        $client->call('auth.test');
        self::assertSame('Bearer root-token', $http->requests[4]->getHeaderLine('Authorization'));
    }

    public function test_reply_client_and_self_filter_use_the_authorized_installation(): void
    {
        $http = new RecordingHttpClient();
        $app = new App(new GenericContainer(), new Client($http, ''), new BoltConfig());
        $app->authorizeUsing(static fn(AuthorizationSource $source): Authorization => new Authorization(
            botToken: 'selected-token',
            botUserId: 'BOT-' . $source->teamId,
        ));
        $app->event('app_mention', static fn(Reply $reply) => $reply->inChannel('Hello'));

        $app->dispatch($this->mention('T1', 'BOT-T1'));
        self::assertCount(0, $http->requests);
        $app->dispatch($this->mention('T1'));
        self::assertSame('Bearer selected-token', $http->requests[0]->getHeaderLine('Authorization'));
    }

    public function test_authorization_failure_does_not_execute_global_middleware_or_handlers(): void
    {
        $app = new App(
            new GenericContainer(),
            new Client(new RecordingHttpClient(), ''),
            new BoltConfig(signingSecret: 'secret'),
        );
        $app->authorizeUsing(static fn() => throw new \RuntimeException('Installation not found'));
        $called = false;
        $app->use(static function () use (&$called): void {
            $called = true;
        });
        $app->event('app_mention', static function () use (&$called): void {
            $called = true;
        });
        $receiver = new Receiver($app, new BoltConfig(signingSecret: 'secret'));
        $body = json_encode($this->mention('T1'), JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $response = $receiver->handle($body, [
            'X-Slack-Request-Timestamp' => $timestamp,
            'X-Slack-Signature' => 'v0=' . hash_hmac('sha256', "v0:{$timestamp}:{$body}", 'secret'),
        ]);

        self::assertSame(500, $response->status);
        self::assertFalse($called);
        self::assertStringNotContainsString('Installation not found', $response->body);
    }

    public function test_valid_authorizer_allows_signed_deliveries_without_a_global_bot_token(): void
    {
        $config = new BoltConfig(signingSecret: 'secret');
        $app = new App(new GenericContainer(), new Client(new RecordingHttpClient(), ''), $config);
        $app->authorizeUsing(static fn() => new Authorization(botToken: 'installed-token'));
        $called = false;
        $app->event('app_mention', static function () use (&$called): void {
            $called = true;
        });
        $body = json_encode($this->mention('T1'), JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $response = new Receiver($app, $config)->handle($body, [
            'X-Slack-Request-Timestamp' => $timestamp,
            'X-Slack-Signature' => 'v0=' . hash_hmac('sha256', "v0:{$timestamp}:{$body}", 'secret'),
        ]);

        self::assertSame(200, $response->status);
        self::assertTrue($called);
    }

    public function test_uninstall_and_revocation_events_can_run_after_credentials_are_gone(): void
    {
        $app = new App(new GenericContainer(), new Client(new RecordingHttpClient(), ''), new BoltConfig());
        $app->authorizeUsing(static fn() => throw new \RuntimeException('Lookup must be bypassed'));
        $calls = [];
        foreach (['app_uninstalled', 'tokens_revoked'] as $type) {
            $app->event($type, static function (Delivery $delivery, AuthorizationSource $source) use (
                &$calls,
                $type,
            ): void {
                self::assertNull($delivery->authorization->botToken);
                self::assertSame('T1', $source->teamId);
                self::assertSame('T1', $delivery->authorization->teamId);
                $calls[] = $type;
            });
            $event = ['type' => $type];
            if ($type === 'tokens_revoked') {
                $event['tokens'] = ['bot' => ['U1']];
            }
            $app->dispatch(['type' => 'event_callback', 'team_id' => 'T1', 'event' => $event]);
        }
        self::assertSame(['app_uninstalled', 'tokens_revoked'], $calls);
    }

    public function test_custom_container_cannot_silently_resolve_an_unscoped_constructor_client(): void
    {
        $app = new App(
            $this->createStub(Container::class),
            new Client(new RecordingHttpClient(), 'root-token'),
            new BoltConfig(),
        );
        $this->expectException(\InvalidArgumentException::class);
        $app->authorizeUsing(static fn() => new Authorization(botToken: 'tenant-token'));
    }

    private function mention(string $team, string $user = 'U1'): array
    {
        return [
            'type' => 'event_callback',
            'team_id' => $team,
            'event' => ['type' => 'app_mention', 'user' => $user, 'channel' => 'C1', 'text' => 'Hi', 'ts' => '1.0'],
        ];
    }
}

final readonly class AuthorizedConstructorListener
{
    public function __construct(
        private Client $client,
    ) {}

    public function __invoke(AppMention $event): void
    {
        $this->client->call('auth.test');
    }
}
