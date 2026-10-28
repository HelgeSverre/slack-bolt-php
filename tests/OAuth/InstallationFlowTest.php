<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\OAuth;

use DateTimeImmutable;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Thunder\Bolt\OAuth\Installer;
use Thunder\Bolt\OAuth\InstallRequest;
use Thunder\Bolt\OAuth\InstallStatus;
use Thunder\Bolt\OAuth\OAuthConfig;
use Thunder\Bolt\OAuth\State\FileStateStore;
use Thunder\Bolt\OAuth\State\InMemoryStateStore;
use Thunder\Bolt\OAuth\State\State;
use Thunder\Bolt\OAuth\State\StateStore;
use Thunder\Bolt\OAuth\Stores\MemoryInstallationStore;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;

final class InstallationFlowTest extends TestCase
{
    public function test_per_install_options_survive_a_new_worker_without_leaking_metadata_into_slack(): void
    {
        $directory = sys_get_temp_dir() . '/bolt-request-' . bin2hex(random_bytes(8));
        try {
            $transport = new RecordingHttpClient([
                new Response(200, body: '{"ok":true,"access_token":"xoxb-test","team":{"id":"T2"}}'),
                new Response(200, body: '{"ok":true,"bot_id":"B1"}'),
            ]);
            $options = InstallRequest::make()
                ->scopes('chat:write')
                ->userScopes()
                ->team('T1')
                ->metadata(['account' => 42]);
            $redirect = $this->installer(new FileStateStore($directory), $transport)->begin($options);
            parse_str((string) parse_url($redirect->authorizationUrl, PHP_URL_QUERY), $query);
            self::assertSame('chat:write', $query['scope']);
            self::assertArrayNotHasKey('user_scope', $query);
            self::assertSame('T1', $query['team']);
            self::assertStringNotContainsString('account', $redirect->authorizationUrl);
            self::assertStringNotContainsString('42', json_encode(array_diff_key($query, [
                'state' => true,
            ]), JSON_THROW_ON_ERROR));
            self::assertSame(Installer::cookieName($query['state']), $redirect->cookieName);

            $worker = $this->installer(new FileStateStore($directory), $transport);
            $result = $worker->complete($query['state'], $redirect->cookieValue, 'code', null);
            self::assertSame(InstallStatus::Installed, $result->status);
            self::assertSame($options->toArray(), $result->request?->toArray());
            self::assertSame('T2', $result->installation?->teamId);
            self::assertSame(
                InstallStatus::InvalidState,
                $worker->complete($query['state'], $redirect->cookieValue, 'code', null)->status,
            );
            self::assertCount(2, $transport->requests);
        } finally {
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            if (is_file($directory . '/.lock')) {
                unlink($directory . '/.lock');
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function test_denial_consumes_valid_state_and_invalid_state_never_exposes_metadata(): void
    {
        $transport = new RecordingHttpClient();
        $installer = $this->installer(new InMemoryStateStore(), $transport);
        $redirect = $installer->begin(InstallRequest::make()->metadata(['secret' => 'private']));
        parse_str((string) parse_url($redirect->authorizationUrl, PHP_URL_QUERY), $query);
        $result = $installer->complete($query['state'], $redirect->cookieValue, null, 'access_denied');
        self::assertSame(InstallStatus::Denied, $result->status);
        self::assertSame(['secret' => 'private'], $result->request?->metadata);
        $replay = $installer->complete($query['state'], $redirect->cookieValue, 'code', null);
        self::assertSame(InstallStatus::InvalidState, $replay->status);
        self::assertNull($replay->request);
        self::assertNull($installer->complete(null, null, null, 'access_denied')->request);

        $redirect = $installer->begin(InstallRequest::make()->metadata(['secret' => 'private']));
        parse_str((string) parse_url($redirect->authorizationUrl, PHP_URL_QUERY), $query);
        $wrongBrowser = $installer->complete($query['state'], str_repeat('x', 43), null, 'access_denied');
        self::assertSame(InstallStatus::InvalidState, $wrongBrowser->status);
        self::assertNull($wrongBrowser->request);
        self::assertCount(0, $transport->requests);
    }

    public function test_legacy_custom_stores_retain_default_flows_but_cannot_silently_lose_options(): void
    {
        $store = new class implements StateStore {
            private InMemoryStateStore $inner;

            public function __construct()
            {
                $this->inner = new InMemoryStateStore();
            }

            public function put(State $state): void
            {
                $this->inner->put($state);
            }

            public function consume(string $value, string $cookieBinding, int $now): bool
            {
                return $this->inner->consume($value, $cookieBinding, $now);
            }
        };
        $installer = $this->installer($store, new RecordingHttpClient());
        self::assertNotEmpty($installer->begin()->cookieName);
        $this->expectException(\LogicException::class);
        $installer->begin(InstallRequest::make()->metadata(['account' => 42]));
    }

    private function installer(StateStore $states, RecordingHttpClient $transport): Installer
    {
        return new Installer(
            new OAuthConfig('123', 'secret', 'https://example.test/callback', ['commands'], ['users:read']),
            new Client($transport, ''),
            new MemoryInstallationStore(),
            $states,
            new class implements ClockInterface {
                public function now(): DateTimeImmutable
                {
                    return new DateTimeImmutable('@100');
                }
            },
        );
    }
}
