<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Tempest\Container\GenericContainer;
use Thunder\Bolt\App;
use Thunder\Bolt\BoltConfig;
use Thunder\Bolt\Errors\ErrorHandling;
use Thunder\Bolt\Errors\FailureContext;
use Thunder\Bolt\Errors\FailureStage;
use Thunder\Bolt\Exceptions\HandlerFailures;
use Thunder\Bolt\Invocation\Delivery;
use Thunder\Bolt\Responses\CommandResponse;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;

final class FailureContextTest extends TestCase
{
    public function test_failures_keep_listener_context_and_aggregate_original_errors(): void
    {
        $app = $this->app();
        $seen = [];
        $one = new \RuntimeException('private first');
        $two = new \LogicException('private second');
        $app->use(static function (Delivery $delivery, \Closure $next): mixed {
            $delivery->context->set('private', 'sensitive-value');
            return $next();
        });
        $app->event('message', static function () use ($one): void {
            throw $one;
        });
        $app->event('message', static function () use ($two): void {
            throw $two;
        });
        $app->onError(static function (FailureContext $failure) use (&$seen): ErrorHandling {
            $seen[] = $failure;
            return ErrorHandling::Rethrow;
        });
        try {
            $app->dispatch(ContextAndIdentityTest::body(), ['transport' => 'fixture', 'secret' => 'sensitive-value']);
            self::fail('Expected failures.');
        } catch (HandlerFailures $error) {
            self::assertSame([$one, $two], $error->failures);
        }
        self::assertCount(2, $seen);
        self::assertSame($one, $seen[0]->error);
        self::assertSame(FailureStage::Listener, $seen[0]->stage);
        self::assertNotNull($seen[0]->delivery->listener);
        self::assertSame('T1', $seen[0]->summary()['team_id']);
        self::assertSame('Ev1', $seen[0]->summary()['event_id']);
        self::assertSame('sensitive-value', $seen[0]->delivery->context->get('private'));
        self::assertStringNotContainsString('sensitive-value', json_encode($seen[0], JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('private first', print_r($seen[0], true));
    }

    public function test_handled_listener_error_preserves_a_prepared_command_response(): void
    {
        $seen = [];
        $app = $this->app()->command('/test', static fn() => CommandResponse::ephemeral('ready'));
        $app->command('/test', static function (): void {
            throw new \RuntimeException('fixture');
        });
        $app->onError(static function (FailureContext $failure) use (&$seen): ErrorHandling {
            $seen[] = $failure;
            return ErrorHandling::Handled;
        });
        $response = $app->dispatch(['command' => '/test', 'team_id' => 'T1', 'user_id' => 'U1', 'channel_id' => 'C1']);
        self::assertSame('ready', $response->toArray()['text']);
        self::assertTrue($seen[0]->acknowledgementPrepared);
        self::assertFalse($seen[0]->summary()['acknowledgement_sent']);
    }

    public function test_handling_cannot_manufacture_a_missing_acknowledgement(): void
    {
        $seen = [];
        $app = $this->app()->command('/test', static fn() => null);
        $app->onError(static function (FailureContext $failure) use (&$seen): ErrorHandling {
            $seen[] = $failure->stage;
            return ErrorHandling::Handled;
        });
        try {
            $app->dispatch(['command' => '/test', 'team_id' => 'T1']);
            self::fail('Expected missing response.');
        } catch (\Thunder\Bolt\Exceptions\MissingCommandResponse) {
        }
        self::assertSame([FailureStage::Acknowledgement], $seen);
    }

    public function test_authorization_and_intake_failures_cannot_be_swallowed(): void
    {
        foreach (['authorization', 'intake'] as $kind) {
            $app = $this->app()->onError(static fn(FailureContext $failure) => ErrorHandling::Handled);
            $error = $kind === 'authorization'
                ? new \Thunder\Bolt\Authorization\AuthorizationException('fixture')
                : new \Thunder\Bolt\Jobs\IntakeFailed('fixture');
            if ($kind === 'authorization') {
                $app->authorizeUsing(static function () use ($error): never {
                    throw $error;
                });
            } else {
                $app->event('message', static function () use ($error): never {
                    throw $error;
                });
            }
            try {
                $app->dispatch(ContextAndIdentityTest::body());
                self::fail('Expected original failure.');
            } catch (\Throwable $actual) {
                self::assertSame($error, $actual);
            }
        }
    }

    public function test_policy_failure_keeps_the_original_failure(): void
    {
        $app = $this->app();
        $original = new \RuntimeException('original');
        $policyError = new \LogicException('policy');
        $app->event('message', static function () use ($original): never {
            throw $original;
        });
        $app->onError(static function () use ($policyError): never {
            throw $policyError;
        });
        try {
            $app->dispatch(ContextAndIdentityTest::body());
            self::fail('Expected aggregate.');
        } catch (HandlerFailures $error) {
            self::assertSame([$original, $policyError], $error->failures);
        }
    }

    public function test_middleware_failure_after_a_successful_handler_is_not_labelled_listener_failure(): void
    {
        $app = $this->app();
        $seen = null;
        $app->event(
            'message',
            static function (Delivery $delivery, \Closure $next): void {
                $next();
                throw new \RuntimeException('middleware');
            },
            static fn() => null,
        );
        $app->onError(static function (FailureContext $failure) use (&$seen): ErrorHandling {
            $seen = $failure;
            return ErrorHandling::Handled;
        });
        $app->dispatch(ContextAndIdentityTest::body());
        self::assertSame(FailureStage::Middleware, $seen->stage);
    }

    public function test_middleware_replacing_a_handler_error_is_identified_as_middleware(): void
    {
        $app = $this->app();
        $seen = null;
        $app->event(
            'message',
            static function (Delivery $delivery, \Closure $next): void {
                try {
                    $next();
                } catch (\RuntimeException $error) {
                    throw new \LogicException('middleware', previous: $error);
                }
            },
            static function (): never {
                throw new \RuntimeException('listener');
            },
        );
        $app->onError(static function (FailureContext $failure) use (&$seen): ErrorHandling {
            $seen = $failure;
            return ErrorHandling::Handled;
        });
        $app->dispatch(ContextAndIdentityTest::body());
        self::assertSame(FailureStage::Middleware, $seen->stage);
        self::assertSame('listener', $seen->error->getPrevious()->getMessage());
    }

    private function app(): App
    {
        return new App(
            new GenericContainer(),
            new Client(new RecordingHttpClient(), 'fixture'),
            new BoltConfig(token: 'fixture'),
        );
    }
}
