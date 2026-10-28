<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Integration;

use DateTimeImmutable;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Tempest\Container\GenericContainer;
use Tempest\Http\Cookie\Cookie;
use Tempest\Http\GenericRequest;
use Tempest\Http\Method;
use Thunder\Bolt\BoltConfig;
use Thunder\Bolt\Integration\OAuthController;
use Thunder\Bolt\OAuth\Installer;
use Thunder\Bolt\OAuth\OAuthConfig;
use Thunder\Bolt\OAuth\State\InMemoryStateStore;
use Thunder\Bolt\OAuth\Stores\MemoryInstallationStore;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;

final class OAuthControllerTest extends TestCase
{
    public function test_install_redirect_sets_a_secure_cookie_and_callback_clears_it_after_success(): void
    {
        $container = new GenericContainer();
        $config = new OAuthConfig('123', 'client-secret', 'https://example.test/slack/oauth_redirect', ['commands']);
        $installer = new Installer(
            $config,
            new Client(new RecordingHttpClient([
                new Response(200, body: '{"ok":true,"access_token":"xoxb-token","team":{"id":"T1"}}'),
                new Response(200, body: '{"ok":true,"bot_id":"B1"}'),
            ]), ''),
            new MemoryInstallationStore(),
            new InMemoryStateStore(),
            new OAuthControllerTestClock(),
        );
        $container->singleton(Installer::class, $installer);
        $controller = new OAuthController(new BoltConfig(oauth: $config), $container);

        $install = $controller->install();
        self::assertSame(302, $install->status->value);
        self::assertStringStartsWith('https://slack.com/oauth/v2/authorize?', $install->headers['Location']->values[0]);
        self::assertStringContainsString('Secure', $install->headers['Set-Cookie']->values[0]);
        self::assertStringContainsString('HttpOnly', $install->headers['Set-Cookie']->values[0]);
        self::assertStringContainsString('SameSite=Lax', $install->headers['Set-Cookie']->values[0]);

        parse_str((string) parse_url($install->headers['Location']->values[0], PHP_URL_QUERY), $query);
        $cookie = Cookie::createFromString($install->headers['Set-Cookie']->values[0]);
        $callback = new GenericRequest(
            Method::GET,
            'https://example.test/slack/oauth_redirect?state=' . rawurlencode($query['state']) . '&code=code',
            headers: ['Cookie' => $cookie->key . '=' . $cookie->value],
        );
        $response = $controller->callback($callback);

        self::assertSame(200, $response->status->value);
        self::assertSame('Thunder Bolt was installed.', $response->body);
        self::assertStringContainsString('Max-Age=0', $response->headers['Set-Cookie']->values[0]);
        self::assertStringStartsWith($cookie->key . '=', $response->headers['Set-Cookie']->values[0]);
    }

    public function test_two_browser_tabs_complete_independently_and_duplicate_cookies_are_rejected(): void
    {
        $container = new GenericContainer();
        $config = new OAuthConfig('123', 'secret', 'https://example.test/slack/oauth_redirect');
        $transport = new RecordingHttpClient();
        $container->singleton(
            Installer::class,
            new Installer(
                $config,
                new Client($transport, ''),
                new MemoryInstallationStore(),
                new InMemoryStateStore(),
                new OAuthControllerTestClock(),
            ),
        );
        $controller = new OAuthController(new BoltConfig(oauth: $config), $container);
        $tabs = [];
        foreach ([1, 2, 3] as $_) {
            $response = $controller->install();
            parse_str((string) parse_url($response->headers['Location']->values[0], PHP_URL_QUERY), $query);
            $tabs[] = [$query['state'], Cookie::createFromString($response->headers['Set-Cookie']->values[0])];
        }
        self::assertNotSame($tabs[0][1]->key, $tabs[1][1]->key);
        $cookies = implode('; ', array_map(
            static fn(array $tab): string => $tab[1]->key . '=' . $tab[1]->value,
            $tabs,
        ));
        foreach (array_slice($tabs, 0, 2) as [$state, $cookie]) {
            $result = $controller->callback(new GenericRequest(
                Method::GET,
                'https://example.test/slack/oauth_redirect?error=access_denied&state=' . $state,
                headers: ['Cookie' => $cookies],
            ));
            self::assertSame('Slack authorization was denied.', $result->body);
            self::assertStringStartsWith($cookie->key . '=', $result->headers['Set-Cookie']->values[0]);
        }
        [$state, $cookie] = $tabs[2];
        $ambiguous = $controller->callback(new GenericRequest(
            Method::GET,
            'https://example.test/slack/oauth_redirect?error=access_denied&state=' . $state,
            headers: ['Cookie' => $cookies . '; ' . $cookie->key . '=' . $cookie->value],
        ));
        self::assertSame('OAuth state could not be verified.', $ambiguous->body);
        self::assertCount(0, $transport->requests);
    }

    public function test_a_pending_legacy_flow_clears_the_fixed_cookie_it_used(): void
    {
        $container = new GenericContainer();
        $config = new OAuthConfig('123', 'secret', 'https://example.test/slack/oauth_redirect');
        $installer = new Installer(
            $config,
            new Client(new RecordingHttpClient(), ''),
            new MemoryInstallationStore(),
            new InMemoryStateStore(),
            new OAuthControllerTestClock(),
        );
        $container->singleton(Installer::class, $installer);
        $redirect = $installer->begin();
        parse_str((string) parse_url($redirect->authorizationUrl, PHP_URL_QUERY), $query);
        $response = new OAuthController(new BoltConfig(oauth: $config), $container)->callback(new GenericRequest(
            Method::GET,
            'https://example.test/slack/oauth_redirect?error=access_denied&state=' . $query['state'],
            headers: ['Cookie' => Installer::STATE_COOKIE . '=' . $redirect->cookieValue],
        ));
        self::assertSame('Slack authorization was denied.', $response->body);
        self::assertStringStartsWith(Installer::STATE_COOKIE . '=', $response->headers['Set-Cookie']->values[0]);
        self::assertStringContainsString('Max-Age=0', $response->headers['Set-Cookie']->values[0]);
    }

    public function test_callback_does_not_exchange_an_invalid_state_and_disabled_oauth_is_unavailable(): void
    {
        $container = new GenericContainer();
        $config = new OAuthConfig('123', 'client-secret', 'https://example.test/slack/oauth_redirect');
        $transport = new RecordingHttpClient();
        $container->singleton(
            Installer::class,
            new Installer(
                $config,
                new Client($transport, ''),
                new MemoryInstallationStore(),
                new InMemoryStateStore(),
                new OAuthControllerTestClock(),
            ),
        );
        $controller = new OAuthController(new BoltConfig(oauth: $config), $container);

        $response = $controller->callback(new GenericRequest(
            Method::GET,
            'https://example.test/slack/oauth_redirect?state=wrong&code=code',
        ));
        self::assertSame(400, $response->status->value);
        self::assertSame('OAuth state could not be verified.', $response->body);
        self::assertCount(0, $transport->requests);

        $denied = $controller->callback(new GenericRequest(
            Method::GET,
            'https://example.test/slack/oauth_redirect?error=access_denied',
        ));
        self::assertSame(400, $denied->status->value);
        self::assertSame('Slack authorization was denied.', $denied->body);
        self::assertStringContainsString('Max-Age=0', $denied->headers['Set-Cookie']->values[0]);
        self::assertCount(0, $transport->requests);

        $disabled = new OAuthController(new BoltConfig(), new GenericContainer());
        self::assertSame(503, $disabled->install()->status->value);
    }
}

final class OAuthControllerTestClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('@1700000000');
    }
}
