<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\OAuth;

use DateTimeImmutable;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Thunder\Bolt\OAuth\Installation;
use Thunder\Bolt\OAuth\InstallationQuery;
use Thunder\Bolt\OAuth\InstallationStore;
use Thunder\Bolt\OAuth\Installer;
use Thunder\Bolt\OAuth\InstallHookFailed;
use Thunder\Bolt\OAuth\InstallHooks;
use Thunder\Bolt\OAuth\InstallRequest;
use Thunder\Bolt\OAuth\InstallResult;
use Thunder\Bolt\OAuth\InstallStatus;
use Thunder\Bolt\OAuth\OAuthConfig;
use Thunder\Bolt\OAuth\State\InMemoryStateStore;
use Thunder\Bolt\OAuth\State\State;
use Thunder\Bolt\OAuth\State\StateStore;
use Thunder\Bolt\OAuth\Stores\MemoryInstallationStore;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;

final class InstallHooksTest extends TestCase
{
    public function test_success_hook_runs_after_storage_and_has_the_validated_request(): void
    {
        $store = new MemoryInstallationStore();
        $seen = [];
        $base = InstallHooks::make();
        $hooks = $base->onSuccess(function (InstallResult $result) use ($store, &$seen): void {
            self::assertNotNull($result->installation);
            self::assertSame($result->installation, $store->fetch($result->installation->query()));
            $seen[] = $result->request?->metadata;
        })->onFailure(static function (): void {
            self::fail('Success must not invoke failure hooks.');
        });
        [$installer, $transport] = $this->installer($hooks, $store);
        [$state, $cookie] = $this->begin($installer);
        $result = $installer->complete($state, $cookie, 'code', null);
        self::assertSame(InstallStatus::Installed, $result->status);
        self::assertSame([['account_id' => 42]], $seen);
        self::assertCount(2, $transport->requests);
        $base->notify(InstallResult::denied());
    }

    public function test_success_hook_failure_keeps_the_installation_and_cannot_repeat_the_exchange(): void
    {
        $store = new MemoryInstallationStore();
        $calls = 0;
        $failures = [];
        [$installer, $transport] = $this->installer(InstallHooks::make()
            ->onSuccess(function () use (&$calls): void {
                $calls++;
                throw new \RuntimeException('onboarding failed');
            })
            ->onFailure(function (InstallResult $result) use (&$failures): void {
                $failures[] = $result->status;
            }), $store);
        [$state, $cookie] = $this->begin($installer);
        try {
            $installer->complete($state, $cookie, 'code', null);
            self::fail('Expected a hook failure.');
        } catch (InstallHookFailed $error) {
            self::assertSame(InstallStatus::Installed, $error->result->status);
            self::assertSame('onboarding failed', $error->getPrevious()?->getMessage());
            self::assertNotNull($error->result->installation);
            self::assertSame($error->result->installation, $store->fetch($error->result->installation->query()));
        }
        self::assertSame([], $failures);
        self::assertSame(InstallStatus::InvalidState, $installer->complete($state, $cookie, 'code', null)->status);
        self::assertSame([InstallStatus::InvalidState], $failures);
        self::assertSame(1, $calls);
        self::assertCount(2, $transport->requests);
    }

    public function test_exchange_errors_have_a_typed_failure_and_cannot_be_replayed(): void
    {
        $seen = [];
        $hooks = InstallHooks::make()->onFailure(function (InstallResult $result) use (&$seen): void {
            $seen[] = $result;
        });
        [$installer, $transport] = $this->installer($hooks, new MemoryInstallationStore(), [new Response(
            200,
            body: '{"ok":false,"error":"invalid_code"}',
        )]);
        [$state, $cookie] = $this->begin($installer);
        $result = $installer->complete($state, $cookie, 'code', null);
        self::assertSame(InstallStatus::Failed, $result->status);
        self::assertNotNull($result->error);
        self::assertNull($result->installation);
        self::assertSame(['account_id' => 42], $result->request?->metadata);
        self::assertSame($result, $seen[0]);
        self::assertSame(InstallStatus::InvalidState, $installer->complete($state, $cookie, 'code', null)->status);
        self::assertNull($seen[1]->request);
        self::assertCount(1, $transport->requests);
    }

    public function test_storage_error_is_a_failure_and_never_calls_success(): void
    {
        $store = $this->createStub(InstallationStore::class);
        $store->method('save')->willThrowException(new \RuntimeException('disk full'));
        $seen = null;
        [$installer] = $this->installer(InstallHooks::make()
            ->onSuccess(static function (): void {
                self::fail('Storage did not succeed.');
            })
            ->onFailure(function (InstallResult $result) use (&$seen): void {
                $seen = $result;
            }), $store);
        [$state, $cookie] = $this->begin($installer);
        $result = $installer->complete($state, $cookie, 'code', null);
        self::assertSame($result, $seen);
        self::assertSame(InstallStatus::Failed, $result->status);
        self::assertSame('disk full', $result->error?->getMessage());
    }

    public function test_state_storage_failure_never_exposes_an_unvalidated_request(): void
    {
        $states = $this->createStub(StateStore::class);
        $states->method('consume')->willThrowException(new \RuntimeException('state unavailable'));
        $seen = null;
        $transport = new RecordingHttpClient();
        $hooks = InstallHooks::make()->onFailure(function (InstallResult $result) use (&$seen): void {
            $seen = $result;
        });
        $installer = new Installer(
            new OAuthConfig('123', 'secret', 'https://example.test/callback', hooks: $hooks),
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
        $result = $installer->complete(str_repeat('s', 43), str_repeat('c', 43), 'code', null);
        self::assertSame($result, $seen);
        self::assertSame(InstallStatus::Failed, $result->status);
        self::assertNull($result->request);
        self::assertCount(0, $transport->requests);
    }

    public function test_failure_hook_errors_retain_the_original_outcome_and_error(): void
    {
        [$installer, $transport] = $this->installer(InstallHooks::make()->onFailure(static function (): void {
            throw new \RuntimeException('hook failed');
        }), new MemoryInstallationStore());
        [$state, $cookie] = $this->begin($installer);
        try {
            $installer->complete($state, $cookie, null, 'access_denied');
            self::fail('Expected failure hook exception.');
        } catch (InstallHookFailed $error) {
            self::assertSame(InstallStatus::Denied, $error->result->status);
            self::assertSame(['account_id' => 42], $error->result->request?->metadata);
        }
        self::assertCount(0, $transport->requests);
    }

    private function installer(InstallHooks $hooks, InstallationStore $store, ?array $responses = null): array
    {
        $transport = new RecordingHttpClient(
            $responses ?? [
                new Response(200, body: '{"ok":true,"access_token":"xoxb-test","team":{"id":"T1"}}'),
                new Response(200, body: '{"ok":true,"bot_id":"B1"}'),
            ],
        );
        return [
            new Installer(
                new OAuthConfig('123', 'secret', 'https://example.test/custom/callback', hooks: $hooks),
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
        ];
    }

    private function begin(Installer $installer): array
    {
        $redirect = $installer->begin(InstallRequest::make()->metadata(['account_id' => 42]));
        parse_str((string) parse_url($redirect->authorizationUrl, PHP_URL_QUERY), $query);
        return [$query['state'], $redirect->cookieValue];
    }
}
