<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\OAuth;

use DateTimeImmutable;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Thunder\Bolt\Authorization\AuthorizationException;
use Thunder\Bolt\OAuth\Installation;
use Thunder\Bolt\OAuth\InstallationQuery;
use Thunder\Bolt\OAuth\OAuthConfig;
use Thunder\Bolt\OAuth\Stores\MemoryInstallationStore;
use Thunder\Bolt\OAuth\TokenRotator;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;
use Thunder\Bolt\WebApi\SlackApiException;

final class TokenRotatorTest extends TestCase
{
    public function test_refreshes_an_expiring_bot_grant_with_a_token_free_oauth_call(): void
    {
        $store = new MemoryInstallationStore();
        $store->save($this->installation());
        $http = new RecordingHttpClient([new Response(200, [], json_encode([
            'ok' => true,
            'access_token' => 'xoxb-new',
            'refresh_token' => 'bot-refresh-new',
            'expires_in' => 600,
        ], JSON_THROW_ON_ERROR))]);
        $rotator = $this->rotator($store, $http);

        $updated = $rotator->refreshBot(new InstallationQuery(teamId: 'T1'));

        self::assertSame('xoxb-new', $updated->botToken);
        self::assertSame('xoxb-new', $store->fetch(new InstallationQuery(teamId: 'T1'))->botToken);
        self::assertSame('', $http->requests[0]->getHeaderLine('Authorization'));
        parse_str((string) $http->requests[0]->getBody(), $form);
        self::assertSame('refresh_token', $form['grant_type']);
        self::assertSame('bot-refresh', $form['refresh_token']);
    }

    public function test_successful_bot_refresh_survives_a_later_user_refresh_failure(): void
    {
        $store = new MemoryInstallationStore();
        $store->save($this->installation());
        $http = new RecordingHttpClient([
            new Response(
                200,
                [],
                '{"ok":true,"access_token":"xoxb-new","refresh_token":"bot-refresh-new","expires_in":600}',
            ),
            new Response(200, [], '{"ok":false,"error":"invalid_refresh_token"}'),
        ]);
        $rotator = $this->rotator($store, $http);
        $query = new InstallationQuery(teamId: 'T1')->forUser('U1');

        try {
            $rotator->refresh($query);
            self::fail('Expected the user refresh to fail.');
        } catch (SlackApiException $exception) {
            self::assertSame('Slack API error: invalid_refresh_token', $exception->getMessage());
        }

        self::assertSame('xoxb-new', $store->fetch($query->forBot())->botToken);
        self::assertSame('xoxp-old', $store->fetch($query)->userToken);
    }

    public function test_refresh_returns_the_fresh_bot_and_user_grants_when_both_are_selected(): void
    {
        $store = new MemoryInstallationStore();
        $store->save($this->installation());
        $http = new RecordingHttpClient([
            new Response(
                200,
                [],
                '{"ok":true,"access_token":"xoxb-new","refresh_token":"bot-refresh-new","expires_in":600}',
            ),
            new Response(
                200,
                [],
                '{"ok":true,"access_token":"xoxp-new","refresh_token":"user-refresh-new","expires_in":600}',
            ),
        ]);

        $result = $this->rotator($store, $http)->refresh(new InstallationQuery(teamId: 'T1', userId: 'U1'));

        self::assertSame('xoxb-new', $result?->botToken);
        self::assertSame('bot-refresh-new', $result?->botRefreshToken);
        self::assertSame('xoxp-new', $result?->userToken);
        self::assertSame('user-refresh-new', $result?->userRefreshToken);
        self::assertSame('xoxb-new', $result?->raw['bot_refresh']['access_token']);
        self::assertSame('xoxp-new', $result?->raw['user_refresh']['access_token']);
    }

    public function test_refresh_with_no_matching_user_grant_returns_a_bot_only_result(): void
    {
        $store = new MemoryInstallationStore();
        $store->save($this->installation());
        $store->delete(new InstallationQuery(teamId: 'T1', userId: 'U1'));
        $http = new RecordingHttpClient([new Response(
            200,
            [],
            '{"ok":true,"access_token":"xoxb-new","refresh_token":"bot-refresh-new","expires_in":600}',
        )]);

        $result = $this->rotator($store, $http)->refresh(new InstallationQuery(teamId: 'T1', userId: 'U2'));

        self::assertSame('xoxb-new', $result?->botToken);
        self::assertNull($result?->userId);
        self::assertNull($result?->userToken);
        self::assertNull($result?->userRefreshToken);
        self::assertSame([], $result?->userScopes);
        self::assertArrayNotHasKey('authed_user', $result?->raw ?? []);
        self::assertArrayNotHasKey('user_refresh', $result?->raw ?? []);
    }

    public function test_expired_credentials_without_a_refresh_token_are_not_authorized(): void
    {
        $store = new MemoryInstallationStore();
        $store->save(Installation::fromOAuthResponse([
            'team' => ['id' => 'T1'],
            'access_token' => 'xoxb-expired',
            'expires_in' => 1,
        ], 1_000));

        $this->expectException(AuthorizationException::class);
        $this->rotator($store, new RecordingHttpClient(), now: 1_002)->refreshBot(new InstallationQuery(teamId: 'T1'));
    }

    public function test_malformed_refresh_response_does_not_replace_the_stored_single_use_credential(): void
    {
        $store = new MemoryInstallationStore();
        $store->save($this->installation());
        $http = new RecordingHttpClient([new Response(
            200,
            [],
            '{"ok":true,"access_token":"xoxb-new","expires_in":600}',
        )]);

        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->rotator($store, $http)->refreshBot(new InstallationQuery(teamId: 'T1'));
        } finally {
            self::assertSame('bot-refresh', $store->fetch(new InstallationQuery(teamId: 'T1'))->botRefreshToken);
        }
    }

    private function rotator(MemoryInstallationStore $store, RecordingHttpClient $http, int $now = 1_000): TokenRotator
    {
        return new TokenRotator(
            new OAuthConfig('client', 'secret', 'https://example.test/oauth/callback'),
            new Client($http, 'unrelated-token'),
            $store,
            new FixedClock($now),
        );
    }

    private function installation(): Installation
    {
        return Installation::fromOAuthResponse([
            'team' => ['id' => 'T1'],
            'access_token' => 'xoxb-old',
            'refresh_token' => 'bot-refresh',
            'expires_in' => 10,
            'authed_user' => [
                'id' => 'U1',
                'access_token' => 'xoxp-old',
                'refresh_token' => 'user-refresh',
                'expires_in' => 10,
            ],
        ], 1_000);
    }
}

final readonly class FixedClock implements ClockInterface
{
    public function __construct(
        private int $timestamp,
    ) {}

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable()->setTimestamp($this->timestamp);
    }
}
