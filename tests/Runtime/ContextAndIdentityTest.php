<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Runtime;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Tempest\Container\GenericContainer;
use Thunder\Bolt\App;
use Thunder\Bolt\BoltConfig;
use Thunder\Bolt\Context;
use Thunder\Bolt\Invocation\Delivery;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;

final class ContextAndIdentityTest extends TestCase
{
    public function test_ordinary_static_listeners_keep_application_singleton_identity(): void
    {
        $container = new GenericContainer();
        $constructed = 0;
        $container->singleton(\stdClass::class, static function () use (&$constructed): \stdClass {
            return (object) ['instance' => ++$constructed];
        });
        $app = new App($container, new Client(new RecordingHttpClient(), 'fixture'), new BoltConfig(token: 'fixture'));
        $seen = [];
        $app->event('message', static function (\stdClass $service, Context $context) use (&$seen): void {
            $seen[] = $service;
        });
        $app->dispatch(self::body());
        $app->dispatch(self::body());
        self::assertSame($seen[0], $seen[1]);
        self::assertSame($container->get(\stdClass::class), $seen[0]);
        self::assertSame(1, $constructed);
    }

    public function test_verified_workspace_requires_an_identifiable_installation(): void
    {
        $http = new RecordingHttpClient([new Response(200, [], '{"ok":true,"user_id":"U-bot","team_id":"T1"}')]);
        $app = new App(new GenericContainer(), new Client($http, 'fixture'), new BoltConfig(token: 'fixture'));
        $app->verifyToken();
        $body = self::body();
        unset($body['team_id']);
        $this->expectException(\Thunder\Bolt\Authorization\AuthorizationException::class);
        $app->dispatch($body);
    }

    public function test_nested_dispatch_does_not_replace_the_outer_context(): void
    {
        $app = new App(
            new GenericContainer(),
            new Client(new RecordingHttpClient(), 'fixture'),
            new BoltConfig(token: 'fixture'),
        );
        $app->use(static function (Delivery $delivery, \Closure $next): mixed {
            $delivery->context->set('marker', $delivery->payload->text);
            return $next();
        });
        $app->event('message', static function (Context $context) use ($app): void {
            if ($context->require('marker') === 'outer') {
                $body = self::body();
                $body['event']['text'] = 'inner';
                $app->dispatch($body);
                self::assertSame('outer', $context->require('marker'));
            } else {
                self::assertSame('inner', $context->require('marker'));
            }
        });
        $body = self::body();
        $body['event']['text'] = 'outer';
        $app->dispatch($body);
    }

    public function test_middleware_and_method_constructor_injection_share_only_one_delivery(): void
    {
        $container = new GenericContainer();
        $http = new RecordingHttpClient();
        $app = new App($container, new Client($http, 'fixture'), new BoltConfig(token: 'fixture'));
        $seen = [];
        $app->use(static function (Delivery $delivery, \Closure $next): mixed {
            self::assertFalse($delivery->context->has('ticket'));
            $delivery->context->set('ticket', new \stdClass());
            $delivery->context->set('nullable', null);
            return $next();
        });
        $app->event('message', static function (Context $context) use (&$seen): void {
            self::assertTrue($context->has('nullable'));
            self::assertNull($context->get('nullable', 'fallback'));
            $seen[] = $context->require('ticket');
            $context->set('handled', true);
        });
        $app->event('message', ContextConstructorListener::class);
        $app->dispatch(self::body());
        $otherTenant = self::body();
        $otherTenant['team_id'] = 'T2';
        $app->dispatch($otherTenant);
        self::assertCount(2, $seen);
        self::assertNotSame($seen[0], $seen[1]);
        self::assertFalse($container->has(Context::class));
    }

    public function test_context_debug_and_serialization_do_not_capture_live_values(): void
    {
        $context = new Context()->set('ticket', 'private-ticket');
        self::assertStringNotContainsString('private-ticket', print_r($context, true));
        $context->remove('ticket');
        self::assertFalse($context->has('ticket'));
        self::assertSame('default', $context->get('ticket', 'default'));
        $this->expectException(\LogicException::class);
        serialize($context);
    }

    public function test_explicit_verification_is_cached_and_hydrates_self_filter_for_bot_messages_without_user(): void
    {
        $http = new RecordingHttpClient([new Response(
            200,
            [],
            '{"ok":true,"user_id":"U-bot","bot_id":"B-bot","team_id":"T1"}',
        )]);
        $app = new App(
            new GenericContainer(),
            new Client($http, 'wrong-client-default'),
            new BoltConfig(token: 'fixture'),
        );
        self::assertCount(0, $http->requests);
        $identity = $app->verifyToken();
        self::assertSame($identity, $app->verifyToken());
        self::assertSame('Bearer fixture', $http->requests[0]->getHeaderLine('Authorization'));
        self::assertCount(1, $http->requests);
        $calls = 0;
        $app->event('message', static function () use (&$calls): void {
            $calls++;
        });
        $body = self::body();
        $body['event']['bot_id'] = 'B-bot';
        $body['event']['subtype'] = 'bot_message';
        unset($body['event']['user']);
        $app->dispatch($body);
        self::assertSame(0, $calls);
        self::assertStringNotContainsString('fixture', json_encode($identity, JSON_THROW_ON_ERROR));
    }

    public function test_failed_verification_is_not_cached_and_manual_identity_can_detect_a_mismatch(): void
    {
        $http = new RecordingHttpClient([
            new Response(200, [], '{"ok":false,"error":"invalid_auth"}'),
            new Response(200, [], '{"ok":true,"user_id":"U-bot","bot_id":"B-bot","team_id":"T1"}'),
        ]);
        $app = new App(new GenericContainer(), new Client($http, 'fixture'), new BoltConfig(token: 'fixture'));
        try {
            $app->verifyToken();
            self::fail('Expected failure.');
        } catch (\Thunder\Bolt\WebApi\SlackApiException) {
        }
        self::assertSame('U-bot', $app->verifyToken()->userId);
        self::assertCount(2, $http->requests);
        $other = new App(
            new GenericContainer(),
            new Client(new RecordingHttpClient([new Response(
                200,
                [],
                '{"ok":true,"user_id":"U-other","team_id":"T1"}',
            )]), 'fixture'),
            new BoltConfig(token: 'fixture', botUserId: 'U-bot'),
        );
        $this->expectException(\Thunder\Bolt\Authorization\AuthorizationException::class);
        $other->verifyToken();
    }

    public function test_verified_workspace_identity_rejects_another_tenant(): void
    {
        $http = new RecordingHttpClient([new Response(
            200,
            [],
            '{"ok":true,"user_id":"U-bot","bot_id":"B-bot","team_id":"T1"}',
        )]);
        $app = new App(new GenericContainer(), new Client($http, 'fixture'), new BoltConfig(token: 'fixture'));
        $app->verifyToken();
        $body = self::body();
        $body['team_id'] = 'T2';
        $this->expectException(\Thunder\Bolt\Authorization\AuthorizationException::class);
        $app->dispatch($body);
    }

    public function test_malformed_identity_responses_never_become_cached_identity(): void
    {
        foreach ([
            ['team_id' => 'T1'],
            ['user_id' => '', 'team_id' => 'T1'],
            ['user_id' => 'U1', 'team_id' => []],
            ['user_id' => 'U1', 'team_id' => 'T1', 'bot_id' => 42],
            ['user_id' => 'U1', 'team_id' => 'T1', 'is_enterprise_install' => true],
            ['user_id' => 'U1', 'team_id' => 'T1', 'is_enterprise_install' => 'true'],
        ] as $data) {
            $http = new RecordingHttpClient([new Response(200, [], json_encode([
                'ok' => true,
                ...$data,
            ], JSON_THROW_ON_ERROR))]);
            $app = new App(new GenericContainer(), new Client($http, 'fixture'), new BoltConfig(token: 'fixture'));
            try {
                $app->verifyToken();
                self::fail('Invalid identity must fail.');
            } catch (\Thunder\Bolt\Authorization\AuthorizationException) {
                self::assertCount(1, $http->requests);
            }
        }
    }

    public function test_configured_bot_id_filters_messages_without_network_verification(): void
    {
        $http = new RecordingHttpClient();
        $app = new App(
            new GenericContainer(),
            new Client($http, 'fixture'),
            new BoltConfig(token: 'fixture', botId: 'B1'),
        );
        $app->event('message', static function (): never {
            self::fail('Own bot message must be filtered.');
        });
        $body = self::body();
        $body['event']['subtype'] = 'bot_message';
        $body['event']['bot_id'] = 'B1';
        unset($body['event']['user']);
        $app->dispatch($body);
        self::assertCount(0, $http->requests);
    }

    public function test_verified_enterprise_identity_preserves_installation_scope(): void
    {
        $http = new RecordingHttpClient([new Response(
            200,
            [],
            '{"ok":true,"user_id":"U-bot","bot_id":"B1","enterprise_id":"E1","is_enterprise_install":true}',
        )]);
        $app = new App(new GenericContainer(), new Client($http, 'fixture'), new BoltConfig(token: 'fixture'));
        self::assertTrue($app->verifyToken()->isEnterpriseInstall);
        $app->event('message', static function (\Thunder\Bolt\Authorization\Authorization $authorization): void {
            self::assertTrue($authorization->isEnterpriseInstall);
            self::assertSame('E1', $authorization->enterpriseId);
        });
        $body = self::body();
        $body['authorizations'] = [['team_id' => null, 'enterprise_id' => 'E1', 'is_enterprise_install' => true]];
        $app->dispatch($body);
        $body['authorizations'][0]['enterprise_id'] = 'E2';
        $this->expectException(\Thunder\Bolt\Authorization\AuthorizationException::class);
        $app->dispatch($body);
    }

    public static function body(): array
    {
        return [
            'type' => 'event_callback',
            'team_id' => 'T1',
            'event_id' => 'Ev1',
            'event' => ['type' => 'message', 'user' => 'U1', 'channel' => 'C1', 'text' => 'hi', 'ts' => '1.000001'],
        ];
    }
}

final readonly class ContextConstructorListener
{
    public function __construct(
        private Context $context,
    ) {}

    public function __invoke(Context $context): void
    {
        TestCase::assertSame($this->context, $context);
        TestCase::assertTrue($context->require('handled'));
    }
}
