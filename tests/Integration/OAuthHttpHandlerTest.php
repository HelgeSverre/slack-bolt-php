<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Integration;

use DateTimeImmutable;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Tempest\Http\Cookie\Cookie;
use Tempest\Http\GenericRequest;
use Tempest\Http\Method;
use Thunder\Bolt\Integration\OAuthHttpHandler;
use Thunder\Bolt\OAuth\Installer;
use Thunder\Bolt\OAuth\InstallHooks;
use Thunder\Bolt\OAuth\InstallRequest;
use Thunder\Bolt\OAuth\InstallResponse;
use Thunder\Bolt\OAuth\InstallResult;
use Thunder\Bolt\OAuth\InstallStatus;
use Thunder\Bolt\OAuth\OAuthConfig;
use Thunder\Bolt\OAuth\State\InMemoryStateStore;
use Thunder\Bolt\OAuth\Stores\MemoryInstallationStore;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;

final class OAuthHttpHandlerTest extends TestCase
{
    public function test_host_routes_use_options_and_redirect_after_the_saved_install_and_hook(): void
    {
        $events = [];
        [$installer, $transport, $store] =
            $this->installer(InstallHooks::make()->onSuccess(function (InstallResult $result) use (&$events): void {
                $events[] = 'hook:' . $result->request?->metadata['account_id'];
            }));
        $http = new OAuthHttpHandler($installer, function (InstallResult $result) use (
            $store,
            &$events,
        ): InstallResponse {
            self::assertNotNull($result->installation);
            self::assertSame($result->installation, $store->fetch($result->installation->query()));
            $events[] = 'response';
            return InstallResponse::redirect('/settings/integrations?installed=slack');
        });
        [$request, $cookie] = $this->start($http);
        $response = $http->callback($request);
        self::assertSame(303, $response->status->value);
        self::assertSame('/settings/integrations?installed=slack', $response->headers['Location']->values[0]);
        self::assertSame(['hook:42', 'response'], $events);
        self::assertStringStartsWith($cookie->key . '=', $response->headers['Set-Cookie']->values[0]);
        self::assertStringContainsString('Max-Age=0', $response->headers['Set-Cookie']->values[0]);
        parse_str((string) $transport->requests[0]->getBody(), $exchange);
        self::assertSame('https://example.test/integrations/slack/callback', $exchange['redirect_uri']);
        self::assertCount(2, $transport->requests);
    }

    public function test_custom_failure_response_has_no_unvalidated_metadata_and_clears_cookie(): void
    {
        [$installer, $transport] = $this->installer();
        $http = new OAuthHttpHandler($installer, static function (InstallResult $result): InstallResponse {
            self::assertSame(InstallStatus::InvalidState, $result->status);
            self::assertNull($result->request);
            return InstallResponse::text('Start installation again.', 409);
        });
        $response = $http->callback(new GenericRequest(
            Method::GET,
            '/integrations/slack/callback?state=wrong&code=code',
        ));
        self::assertSame(409, $response->status->value);
        self::assertSame('Start installation again.', $response->body);
        self::assertSame('text/plain; charset=utf-8', $response->headers['Content-Type']->values[0]);
        self::assertStringContainsString('Max-Age=0', $response->headers['Set-Cookie']->values[0]);
        self::assertCount(0, $transport->requests);
    }

    public function test_hook_and_responder_exceptions_fail_safely_without_exchange_replay(): void
    {
        foreach (['hook', 'responder'] as $where) {
            $hooks = $where === 'hook'
                ? InstallHooks::make()->onSuccess(static function (): void {
                    throw new \RuntimeException('private');
                }) : new InstallHooks();
            [$installer, $transport] = $this->installer($hooks);
            $http = new OAuthHttpHandler($installer, static function (): InstallResponse {
                throw new \RuntimeException('private');
            });
            [$request] = $this->start($http);
            $response = $http->callback($request);
            self::assertSame(502, $response->status->value);
            self::assertStringNotContainsString('private', (string) $response->body);
            self::assertStringContainsString('Max-Age=0', $response->headers['Set-Cookie']->values[0]);
            self::assertSame(502, $http->callback($request)->status->value);
            self::assertCount(2, $transport->requests);
        }
    }

    #[DataProvider('unsafeRedirects')]
    public function test_unsafe_redirects_are_rejected(string $target): void
    {
        $this->expectException(\InvalidArgumentException::class);
        InstallResponse::redirect($target);
    }

    public static function unsafeRedirects(): iterable
    {
        foreach ([
            '//elsewhere.test',
            '/\\elsewhere.test',
            'javascript:alert(1)',
            'http://example.test',
            "https://example.test\r\nX: injected",
            'https://user:secret@example.test',
            '',
            'relative/path',
        ] as $target) {
            yield [$target];
        }
    }

    public function test_explicit_https_redirect_and_invalid_text_status(): void
    {
        self::assertSame(
            'https://example.test/settings',
            InstallResponse::redirect('https://example.test/settings')->location,
        );
        $this->expectException(\InvalidArgumentException::class);
        InstallResponse::text('wrong', 302);
    }

    private function start(OAuthHttpHandler $handler): array
    {
        $redirect = $handler->install(InstallRequest::make()->scopes('commands')->metadata(['account_id' => 42]));
        parse_str((string) parse_url($redirect->headers['Location']->values[0], PHP_URL_QUERY), $query);
        self::assertSame('https://example.test/integrations/slack/callback', $query['redirect_uri']);
        $cookie = Cookie::createFromString($redirect->headers['Set-Cookie']->values[0]);
        return [
            new GenericRequest(
                Method::GET,
                'https://example.test/integrations/slack/callback?state=' . $query['state'] . '&code=code',
                headers: ['Cookie' => $cookie->key . '=' . $cookie->value],
            ),
            $cookie,
        ];
    }

    private function installer(InstallHooks $hooks = new InstallHooks()): array
    {
        $transport = new RecordingHttpClient([
            new Response(200, body: '{"ok":true,"access_token":"xoxb-test","team":{"id":"T1"}}'),
            new Response(200, body: '{"ok":true,"bot_id":"B1"}'),
        ]);
        $store = new MemoryInstallationStore();
        return [
            new Installer(
                new OAuthConfig('123', 'secret', 'https://example.test/integrations/slack/callback', hooks: $hooks),
                new Client($transport, ''),
                $store,
                new InMemoryStateStore(),
                new class implements ClockInterface {
                    public function now(): DateTimeImmutable
                    {
                        return new DateTimeImmutable('@100');
                    }
                },
            ),
            $transport,
            $store,
        ];
    }
}
