<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\OAuth;

use DateTimeImmutable;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Thunder\Bolt\OAuth\Installer;
use Thunder\Bolt\OAuth\InstallStatus;
use Thunder\Bolt\OAuth\OAuthConfig;
use Thunder\Bolt\OAuth\State\InMemoryStateStore;
use Thunder\Bolt\OAuth\Stores\MemoryInstallationStore;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;

final class InstallerTest extends TestCase
{
    public function test_it_uses_a_one_time_state_then_exchanges_and_persists_a_bot_installation(): void
    {
        $clock = new OAuthTestClock(1_700_000_000);
        $transport = new RecordingHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'ok' => true,
                'access_token' => 'xoxb-installed-token',
                'bot_user_id' => 'U_BOT',
                'team' => ['id' => 'T1'],
                'scope' => 'chat:write,commands',
            ], JSON_THROW_ON_ERROR)),
            new Response(200, ['Content-Type' => 'application/json'], '{"ok":true,"bot_id":"B1"}'),
        ]);
        $states = new InMemoryStateStore();
        $installations = new MemoryInstallationStore();
        $installer = new Installer(
            $this->config(),
            new Client($transport, 'xoxb-unrelated-app-token'),
            $installations,
            $states,
            $clock,
        );

        $redirect = $installer->begin();
        parse_str((string) parse_url($redirect->authorizationUrl, PHP_URL_QUERY), $query);

        self::assertSame('123', $query['client_id']);
        self::assertSame('chat:write,commands', $query['scope']);
        self::assertSame('users:read', $query['user_scope']);
        self::assertSame($this->config()->redirectUri, $query['redirect_uri']);
        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/', $query['state']);
        self::assertSame(600, $redirect->maxAge);

        $result = $installer->complete($query['state'], $redirect->cookieValue, 'authorization-code', null);

        self::assertSame(InstallStatus::Installed, $result->status);
        self::assertNotNull($result->installation);
        self::assertSame('B1', $result->installation->botId);
        self::assertSame('U_BOT', $result->installation->botUserId);
        self::assertSame('xoxb-installed-token', $installations->fetch($result->installation->query())?->botToken);
        self::assertSame('', $transport->requests[0]->getHeaderLine('Authorization'));
        self::assertSame('application/x-www-form-urlencoded', $transport->requests[0]->getHeaderLine('Content-Type'));
        parse_str((string) $transport->requests[0]->getBody(), $accessArguments);
        self::assertSame(
            [
                'client_id' => '123',
                'client_secret' => 'client-secret',
                'code' => 'authorization-code',
                'redirect_uri' => 'https://example.test/slack/oauth_redirect',
            ],
            $accessArguments,
        );
        self::assertSame('Bearer xoxb-installed-token', $transport->requests[1]->getHeaderLine('Authorization'));
        self::assertSame('auth.test', basename($transport->requests[1]->getUri()->getPath()));

        self::assertSame(
            InstallStatus::InvalidState,
            $installer->complete($query['state'], $redirect->cookieValue, 'authorization-code', null)->status,
        );
        self::assertCount(2, $transport->requests);
    }

    public function test_invalid_or_expired_state_never_exchanges_a_code(): void
    {
        $clock = new OAuthTestClock(100);
        $transport = new RecordingHttpClient();
        $installer = new Installer(
            $this->config(),
            new Client($transport, ''),
            new MemoryInstallationStore(),
            new InMemoryStateStore(),
            $clock,
        );
        $redirect = $installer->begin();
        parse_str((string) parse_url($redirect->authorizationUrl, PHP_URL_QUERY), $query);

        self::assertSame(
            InstallStatus::InvalidState,
            $installer->complete($query['state'], 'wrong-cookie', 'code', null)->status,
        );
        self::assertCount(0, $transport->requests);

        $states = new InMemoryStateStore();
        $expired = new Installer(
            $this->config(),
            new Client($transport, ''),
            new MemoryInstallationStore(),
            $states,
            $clock,
        );
        $redirect = $expired->begin();
        parse_str((string) parse_url($redirect->authorizationUrl, PHP_URL_QUERY), $query);
        $clock->timestamp = 700;

        self::assertSame(
            InstallStatus::InvalidState,
            $expired->complete($query['state'], $redirect->cookieValue, 'code', null)->status,
        );
        self::assertCount(0, $transport->requests);
    }

    public function test_access_denied_is_a_safe_distinct_outcome_without_state_or_exchange(): void
    {
        $transport = new RecordingHttpClient();
        $installer = new Installer(
            $this->config(),
            new Client($transport, ''),
            new MemoryInstallationStore(),
            new InMemoryStateStore(),
            new OAuthTestClock(100),
        );

        self::assertSame(InstallStatus::Denied, $installer->complete(null, null, null, 'access_denied')->status);
        self::assertCount(0, $transport->requests);
    }

    private function config(): OAuthConfig
    {
        return new OAuthConfig(
            '123',
            'client-secret',
            'https://example.test/slack/oauth_redirect',
            ['chat:write', 'commands'],
            ['users:read'],
        );
    }
}

final class OAuthTestClock implements ClockInterface
{
    public function __construct(
        public int $timestamp,
    ) {}

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('@' . $this->timestamp);
    }
}
