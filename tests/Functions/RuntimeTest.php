<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Functions;

use PHPUnit\Framework\TestCase;
use Tempest\Container\GenericContainer;
use Thunder\Bolt\App;
use Thunder\Bolt\Attributes\FromInputs;
use Thunder\Bolt\Attributes\InputName;
use Thunder\Bolt\Attributes\SlackFunction;
use Thunder\Bolt\Authorization\Authorization;
use Thunder\Bolt\BoltConfig;
use Thunder\Bolt\Functions\Execution;
use Thunder\Bolt\Functions\ExecutionContext;
use Thunder\Bolt\Functions\ExecutionState;
use Thunder\Bolt\Functions\Inputs;
use Thunder\Bolt\Functions\InvalidInputs;
use Thunder\Bolt\Http\Receiver;
use Thunder\Bolt\Invocation\Delivery;
use Thunder\Bolt\Payloads\Events\FunctionExecuted;
use Thunder\Bolt\Responses\ActionResponse;
use Thunder\Bolt\Responses\OptionsResponse;
use Thunder\Bolt\Responses\ViewResponse;
use Thunder\Bolt\Routing\InteractionConstraints;
use Thunder\Bolt\Tests\Support\FunctionPayloads;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;

final class RuntimeTest extends TestCase
{
    private const string SECRET = 'function-runtime-signing-secret';

    public function test_callback_selector_typed_event_and_ordinary_event_listener_remain_compatible(): void
    {
        [$app] = $this->application();
        $seen = [];
        $otherWasCalled = false;

        $app->function('collect_feedback', static function (
            FunctionExecuted $event,
            Inputs $inputs,
            ExecutionContext $context,
            Execution $execution,
            Delivery $delivery,
        ) use (&$seen): void {
            $seen = [
                $event->callbackId,
                $event->executionId,
                $inputs->string('summary_text'),
                $context->workflowExecutionId,
                $execution->state(),
                $delivery->execution === $execution,
            ];
        });
        $app->function('another_function', static function () use (&$otherWasCalled): void {
            $otherWasCalled = true;
        });
        $app->event('function_executed', static function (FunctionExecuted $event) use (&$seen): void {
            $seen[] = 'ordinary:' . $event->callbackId;
        });

        self::assertNull($app->dispatch(FunctionPayloads::executed()));
        self::assertSame(
            [
                'collect_feedback',
                'Fx-function',
                'Ship this',
                'Wx-function',
                ExecutionState::Pending,
                true,
                'ordinary:collect_feedback',
            ],
            $seen,
        );
        self::assertFalse($otherWasCalled);
    }

    public function test_from_inputs_dto_injection_honours_aliases_and_defaults(): void
    {
        [$app] = $this->application();
        $received = null;
        $app->function('collect_feedback', static function (#[FromInputs] RuntimeFeedbackInput $input) use (
            &$received,
        ): void {
            $received = $input;
        });
        $body = FunctionPayloads::executed();
        $body['event']['inputs'] = ['summary_text' => 'Use the default retry count'];

        $app->dispatch($body);

        self::assertInstanceOf(RuntimeFeedbackInput::class, $received);
        self::assertSame('Use the default retry count', $received->summary);
        self::assertSame(3, $received->retries);
    }

    public function test_from_inputs_dto_validation_stays_strict(): void
    {
        [$app] = $this->application();
        $app->function('collect_feedback', static function (#[FromInputs] RuntimeFeedbackInput $input): void {});
        $body = FunctionPayloads::executed();
        $body['event']['inputs'] = ['summary_text' => 'Valid', 'retry_count' => 'not-an-integer'];

        $this->expectException(InvalidInputs::class);
        $this->expectExceptionMessage('Input "retry_count" must be an integer.');
        $app->dispatch($body);
    }

    public function test_attributed_function_registration_uses_the_same_callback_selector(): void
    {
        AttributedFunctionListener::$callbackId = null;
        [$app] = $this->application();
        $app->registerClass(AttributedFunctionListener::class);

        $app->dispatch(FunctionPayloads::executed());

        self::assertSame('collect_feedback', AttributedFunctionListener::$callbackId);
    }

    public function test_function_scoped_action_view_closed_view_submission_and_options_receive_execution_helpers(): void
    {
        [$app] = $this->application();
        $seen = [];
        $app->action('approve_feedback', static function (Execution $execution, Inputs $inputs) use (
            &$seen,
        ): ActionResponse {
            $seen[] = ['action', $execution->context->executionId, $inputs->string('summary_text')];

            return ActionResponse::empty();
        });
        $app->view('feedback_modal', static function (Execution $execution) use (&$seen): ViewResponse {
            $seen[] = ['view', $execution->context->executionId];

            return ViewResponse::empty();
        });
        $app->view(
            InteractionConstraints::view('feedback_modal', 'view_closed'),
            static function (Execution $execution) use (&$seen): ViewResponse {
                $seen[] = ['view', $execution->context->executionId];

                return ViewResponse::empty();
            },
        );
        $app->options('feedback_search', static function (Execution $execution) use (&$seen): OptionsResponse {
            $seen[] = ['options', $execution->context->executionId];

            return OptionsResponse::empty();
        });

        self::assertSame([], $app->dispatch(FunctionPayloads::action())?->toArray());
        self::assertSame([], $app->dispatch(FunctionPayloads::viewSubmission())?->toArray());
        self::assertSame([], $app->dispatch(FunctionPayloads::viewClosed())?->toArray());
        self::assertSame(['options' => []], $app->dispatch(FunctionPayloads::blockSuggestion())?->toArray());
        self::assertSame(
            [
                ['action', 'Fx-interaction', 'Ship this'],
                ['view', 'Fx-interaction'],
                ['view', 'Fx-interaction'],
                ['options', 'Fx-interaction'],
            ],
            $seen,
        );
    }

    public function test_jit_client_is_delivery_scoped_while_the_application_client_keeps_its_installation_token(): void
    {
        [$app, $http, $root] = $this->application('xoxb-installation-token');
        $app->function('collect_feedback', static function (Client $client, Execution $execution): void {
            $client->call('auth.test');
            $execution->complete(['summary' => 'Completed with the JIT token.']);
        });

        $app->dispatch(FunctionPayloads::executed());
        $root->call('auth.test');

        self::assertSame(
            ['Bearer xwfp-function-token', 'Bearer xwfp-function-token', 'Bearer xoxb-installation-token'],
            array_map(static fn($request): string => $request->getHeaderLine('Authorization'), $http->requests),
        );
    }

    public function test_function_scoped_interaction_client_uses_its_own_jit_token(): void
    {
        [$app, $http, $root] = $this->application('xoxb-installation-token');
        $app->action('approve_feedback', static function (Client $client, Execution $execution): ActionResponse {
            $client->call('auth.test');
            self::assertSame('Fx-interaction', $execution->context->executionId);

            return ActionResponse::empty();
        });

        $app->dispatch(FunctionPayloads::action());
        $root->call('auth.test');

        self::assertSame(
            ['Bearer xwfp-interaction-token', 'Bearer xoxb-installation-token'],
            array_map(static fn($request): string => $request->getHeaderLine('Authorization'), $http->requests),
        );
    }

    public function test_scoped_constructor_client_uses_jit_without_a_static_authorizer(): void
    {
        [$app, $http] = $this->application('xoxb-installation-token');
        $app->function('collect_feedback', FunctionScopedClientListener::class);

        $app->dispatch(FunctionPayloads::executed());

        self::assertSame('Bearer xwfp-function-token', $http->requests[0]->getHeaderLine('Authorization'));
    }

    public function test_authorizer_policy_is_not_bypassed_by_a_jit_token(): void
    {
        [$app, $http] = $this->application();
        $called = false;
        $app->authorizeUsing(static fn() => throw new \RuntimeException('Installation rejected'));
        $app->function('collect_feedback', static function () use (&$called): void {
            $called = true;
        });

        $raw = json_encode(FunctionPayloads::executed(), JSON_THROW_ON_ERROR);
        $response = new Receiver($app, new BoltConfig(signingSecret: self::SECRET))->handle($raw, $this->headers($raw));

        self::assertSame(500, $response->status);
        self::assertFalse($called);
        self::assertSame([], $http->requests);
    }

    public function test_signed_jit_function_delivery_can_run_without_installation_credentials_but_normal_events_cannot(): void
    {
        [$app] = $this->application('');
        $called = false;
        $app->function('collect_feedback', static function (Execution $execution) use (&$called): void {
            $called = $execution->state() === ExecutionState::Pending;
        });
        $receiver = new Receiver($app, new BoltConfig(signingSecret: self::SECRET));
        $functionRaw = json_encode(FunctionPayloads::executed(), JSON_THROW_ON_ERROR);
        $normalRaw = json_encode([
            'type' => 'event_callback',
            'event' => ['type' => 'app_mention', 'user' => 'U1', 'channel' => 'C1', 'text' => 'hello', 'ts' => '1.0'],
        ], JSON_THROW_ON_ERROR);

        self::assertSame(200, $receiver->handle($functionRaw, $this->headers($functionRaw))->status);
        self::assertTrue($called);
        self::assertSame(503, $receiver->handle($normalRaw, $this->headers($normalRaw))->status);
    }

    /** @return array{App, RecordingHttpClient, Client} */
    private function application(string $token = 'xoxb-installation-token'): array
    {
        $http = new RecordingHttpClient();
        $client = new Client($http, $token);
        $container = new GenericContainer();
        $container->singleton(Client::class, $client);

        return [
            new App($container, $client, new BoltConfig($token, self::SECRET)),
            $http,
            $client,
        ];
    }

    /** @return array<string, string> */
    private function headers(string $raw): array
    {
        $timestamp = (string) time();

        return [
            'X-Slack-Request-Timestamp' => $timestamp,
            'X-Slack-Signature' => 'v0=' . hash_hmac('sha256', "v0:{$timestamp}:{$raw}", self::SECRET),
        ];
    }
}

final readonly class RuntimeFeedbackInput
{
    public function __construct(
        #[InputName('summary_text')]
        public string $summary,
        #[InputName('retry_count')]
        public int $retries = 3,
    ) {}
}

final class AttributedFunctionListener
{
    public static ?string $callbackId = null;

    #[SlackFunction('collect_feedback')]
    public function handle(FunctionExecuted $event): void
    {
        self::$callbackId = $event->callbackId;
    }
}

final readonly class FunctionScopedClientListener
{
    public function __construct(
        private Client $client,
    ) {}

    public function __invoke(FunctionExecuted $event): void
    {
        $this->client->call('auth.test');
    }
}
