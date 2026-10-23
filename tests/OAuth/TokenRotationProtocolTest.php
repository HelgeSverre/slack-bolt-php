<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\OAuth;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Thunder\Bolt\OAuth\Installation;
use Thunder\Bolt\OAuth\InstallationQuery;
use Thunder\Bolt\OAuth\OAuthConfig;
use Thunder\Bolt\OAuth\Stores\MemoryInstallationStore;
use Thunder\Bolt\OAuth\TokenRotator;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;

final class TokenRotationProtocolTest extends TestCase
{
    #[DataProvider('expiryBoundaries')]
    public function test_refresh_window_matches_bolt_for_each_grant(
        string $grant,
        int $lifetime,
        int $now,
        bool $expected,
    ): void {
        $store = new MemoryInstallationStore();
        $credentials = ['access_token' => 'old-access', 'refresh_token' => 'old-refresh', 'expires_in' => $lifetime];
        $store->save(Installation::fromOAuthResponse([
            'team' => ['id' => 'T1'],
            ...($grant === 'bot' ? $credentials : ['authed_user' => ['id' => 'U1', ...$credentials]]),
        ], 1_000));
        $http = new RecordingHttpClient([new Response(
            200,
            [],
            '{"ok":true,"access_token":"fresh-access","refresh_token":"fresh-refresh","expires_in":43200}',
        )]);
        $rotator = $this->rotator($store, $http, $now);
        $query = new InstallationQuery('T1');
        $result = $grant === 'bot' ? $rotator->refreshBot($query) : $rotator->refreshUser($query->forUser('U1'));

        self::assertCount($expected ? 1 : 0, $http->requests);
        self::assertSame(
            $expected ? 'fresh-access' : 'old-access',
            $grant === 'bot' ? $result->botToken : $result->userToken,
        );
        self::assertSame(
            $expected ? $now + 43200 : 1_000 + $lifetime,
            $grant === 'bot' ? $result->botTokenExpiresAt : $result->userTokenExpiresAt,
        );
    }

    public static function expiryBoundaries(): iterable
    {
        foreach (['bot', 'user'] as $grant) {
            yield $grant . ' outside window' => [$grant, 7_201, 1_000, false];
            yield $grant . ' exactly two hours' => [$grant, 7_200, 1_000, true];
            yield $grant . ' already expired' => [$grant, 1, 1_002, true];
        }
    }

    #[DataProvider('grants')]
    public function test_long_lived_credential_migration_uses_exchange_form_and_persists_new_pair(string $grant): void
    {
        $store = new MemoryInstallationStore();
        $store->save(Installation::fromOAuthResponse([
            'team' => ['id' => 'T1'],
            ...(
                $grant === 'bot'
                    ? ['access_token' => 'long-lived']
                    : ['authed_user' => ['id' => 'U1', 'access_token' => 'long-lived']]
            ),
        ], 1_000));
        $http = new RecordingHttpClient([new Response(
            200,
            [],
            '{"ok":true,"access_token":"short-lived","refresh_token":"fresh-refresh","expires_in":43200}',
        )]);
        $rotator = $this->rotator($store, $http, 1_000);
        $query = new InstallationQuery('T1');
        $query = $grant === 'bot' ? $query : $query->forUser('U1');
        $grant === 'bot' ? $rotator->migrateBot($query) : $rotator->migrateUser($query);

        self::assertSame('/api/oauth.v2.exchange', $http->requests[0]->getUri()->getPath());
        self::assertSame('', $http->requests[0]->getHeaderLine('Authorization'));
        parse_str((string) $http->requests[0]->getBody(), $form);
        self::assertSame('long-lived', $form['token']);
        $record = $store->fetch($query);
        self::assertSame('short-lived', $grant === 'bot' ? $record->botToken : $record->userToken);
        self::assertSame('fresh-refresh', $grant === 'bot' ? $record->botRefreshToken : $record->userRefreshToken);
        self::assertSame(44_200, $grant === 'bot' ? $record->botTokenExpiresAt : $record->userTokenExpiresAt);
    }

    public static function grants(): iterable
    {
        yield 'bot' => ['bot'];
        yield 'user' => ['user'];
    }

    private function rotator(MemoryInstallationStore $store, RecordingHttpClient $http, int $now): TokenRotator
    {
        $clock = new class($now) implements ClockInterface {
            public function __construct(
                private int $timestamp,
            ) {}

            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('@' . $this->timestamp);
            }
        };

        return new TokenRotator(
            new OAuthConfig('client', 'secret', 'https://example.test/slack/oauth_redirect'),
            new Client($http, 'unrelated'),
            $store,
            $clock,
        );
    }
}
