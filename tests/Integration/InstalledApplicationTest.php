<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Integration;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Tempest\Container\GenericContainer;
use Thunder\Bolt\App;
use Thunder\Bolt\Authorization\InstallationAuthorizer;
use Thunder\Bolt\BoltConfig;
use Thunder\Bolt\OAuth\InstallationQuery;
use Thunder\Bolt\OAuth\Installer;
use Thunder\Bolt\OAuth\InstallStatus;
use Thunder\Bolt\OAuth\OAuthConfig;
use Thunder\Bolt\OAuth\State\InMemoryStateStore;
use Thunder\Bolt\OAuth\Stores\MemoryInstallationStore;
use Thunder\Bolt\OAuth\TokenRotator;
use Thunder\Bolt\Reply;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;

final class InstalledApplicationTest extends TestCase
{
    public function test_install_then_dispatch_refreshes_and_persists_both_grants_before_the_handler_uses_the_bot_token(): void
    {
        $json = static fn(array $body): Response => new Response(200, [], json_encode([
            'ok' => true,
            ...$body,
        ], JSON_THROW_ON_ERROR));
        $http = new RecordingHttpClient([
            $json([
                'team' => ['id' => 'T1'],
                'access_token' => 'initial-bot',
                'bot_user_id' => 'BUSER',
                'refresh_token' => 'initial-bot-refresh',
                'expires_in' => 1,
                'scope' => 'chat:write',
                'authed_user' => [
                    'id' => 'U1',
                    'access_token' => 'initial-user',
                    'refresh_token' => 'initial-user-refresh',
                    'expires_in' => 1,
                ],
            ]),
            $json(['bot_id' => 'B1', 'user_id' => 'BUSER']),
            $json(['access_token' => 'fresh-bot', 'refresh_token' => 'fresh-bot-refresh', 'expires_in' => 43200]),
            $json(['access_token' => 'fresh-user', 'refresh_token' => 'fresh-user-refresh', 'expires_in' => 43200]),
            $json(['ts' => '1.0']),
            $json(['ts' => '2.0']),
        ]);
        $clock = new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('@1700000000');
            }
        };
        $config = new OAuthConfig('client', 'secret', 'https://example.test/slack/oauth_redirect', ['chat:write']);
        $store = new MemoryInstallationStore();
        $client = new Client($http, '');
        $installer = new Installer($config, $client, $store, new InMemoryStateStore(), $clock);
        $redirect = $installer->begin();
        parse_str(parse_url($redirect->authorizationUrl, PHP_URL_QUERY), $query);
        $outcome = $installer->complete($query['state'], $redirect->cookieValue, 'code', null);
        self::assertSame(InstallStatus::Installed, $outcome->status);
        self::assertSame('B1', $outcome->installation->botId);

        $app = new App(
            new GenericContainer(),
            $client,
            new BoltConfig(),
            new InstallationAuthorizer($store, new TokenRotator($config, $client, $store, $clock)),
        );
        $app->event('app_mention', static fn(Reply $reply) => $reply->inChannel('Hello'));
        foreach (['U1', 'U2'] as $user) {
            $app->dispatch([
                'type' => 'event_callback',
                'team_id' => 'T1',
                'event' => ['type' => 'app_mention', 'user' => $user, 'channel' => 'C1', 'ts' => '1.0'],
            ]);
        }

        self::assertCount(6, $http->requests);
        self::assertSame('', $http->requests[0]->getHeaderLine('Authorization'));
        self::assertSame('Bearer initial-bot', $http->requests[1]->getHeaderLine('Authorization'));
        foreach ([2, 3] as $index) {
            self::assertSame('', $http->requests[$index]->getHeaderLine('Authorization'));
        }
        self::assertSame('Bearer fresh-bot', $http->requests[4]->getHeaderLine('Authorization'));
        self::assertSame('Bearer fresh-bot', $http->requests[5]->getHeaderLine('Authorization'));
        self::assertSame('fresh-bot-refresh', $store->fetch(new InstallationQuery('T1'))->botRefreshToken);
        self::assertSame(
            'fresh-user-refresh',
            $store->fetch(new InstallationQuery('T1', userId: 'U1'))->userRefreshToken,
        );
        self::assertNull($store->fetch(new InstallationQuery('T1', userId: 'U2')));
        self::assertSame(
            InstallStatus::InvalidState,
            $installer->complete($query['state'], $redirect->cookieValue, 'code', null)->status,
        );
        self::assertCount(6, $http->requests);
    }
}
