<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Functions;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response as HttpResponse;
use PHPUnit\Framework\TestCase;
use Thunder\Bolt\Functions\Execution;
use Thunder\Bolt\Functions\ExecutionContext;
use Thunder\Bolt\Functions\ExecutionState;
use Thunder\Bolt\Tests\Support\FunctionFailingHttpClient;
use Thunder\Bolt\Tests\Support\FunctionPayloads;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;
use Thunder\Bolt\WebApi\SlackApiException;

final class ExecutionTest extends TestCase
{
    public function test_interaction_context_reads_the_nested_function_definition(): void
    {
        $context = ExecutionContext::fromArray([
            'type' => 'view_submission',
            'function_data' => [
                'execution_id' => 'Fx1',
                'function' => ['callback_id' => 'format_text', 'id' => 'Fn1'],
                'inputs' => [],
            ],
        ]);
        self::assertSame('format_text', $context?->callbackId);
        self::assertSame('Fn1', $context?->functionId);
    }

    public function test_terminal_calls_are_not_retried_by_an_opted_in_client(): void
    {
        $http = new RecordingHttpClient([new HttpResponse(500, [], '{"ok":false}')]);
        $client = new Client($http, 'workflow-test')->retrying(
            \Thunder\Bolt\WebApi\RetryPolicy::transientErrors(initialDelay: 0, maxDelay: 0, jitter: false),
            static fn() => null,
        );
        $context = ExecutionContext::fromArray(FunctionPayloads::executed()) ?? throw new \LogicException();
        $execution = new Execution($client, $context);
        try {
            $execution->complete();
            self::fail('Expected remote failure.');
        } catch (SlackApiException) {
            self::assertCount(1, $http->requests);
            self::assertSame(ExecutionState::Uncertain, $execution->state());
        }
    }

    public function test_complete_uses_the_scoped_token_and_serializes_empty_outputs_as_an_object(): void
    {
        $http = new RecordingHttpClient();
        $execution = $this->execution($http);

        $execution->complete();

        self::assertSame(ExecutionState::Completed, $execution->state());
        self::assertTrue($execution->hasResponded());
        self::assertSame('functions.completeSuccess', basename($http->requests[0]->getUri()->getPath()));
        self::assertSame('Bearer xwfp-function-token', $http->requests[0]->getHeaderLine('Authorization'));
        self::assertSame(
            '{"function_execution_id":"Fx-function","outputs":{}}',
            (string) $http->requests[0]->getBody(),
        );
    }

    public function test_fail_is_terminal_and_sends_a_human_readable_error(): void
    {
        $http = new RecordingHttpClient();
        $execution = $this->execution($http);

        $execution->fail('The document could not be read.');

        self::assertSame(ExecutionState::Failed, $execution->state());
        self::assertSame('functions.completeError', basename($http->requests[0]->getUri()->getPath()));
        self::assertSame(
            ['function_execution_id' => 'Fx-function', 'error' => 'The document could not be read.'],
            $http->sentPayload(),
        );

        $this->expectException(\LogicException::class);
        $execution->complete(['summary' => 'Too late']);
    }

    public function test_api_failure_leaves_execution_uncertain_and_never_attempts_the_opposite_terminal_call(): void
    {
        $http = new RecordingHttpClient([new HttpResponse(200, [], '{"ok":false,"error":"internal_error"}')]);
        $execution = $this->execution($http);

        try {
            $execution->complete(['summary' => 'Maybe accepted']);
            self::fail('Expected Slack API failure.');
        } catch (SlackApiException $exception) {
            self::assertSame('internal_error', $exception->error);
        }

        self::assertSame(ExecutionState::Uncertain, $execution->state());
        $this->expectException(\LogicException::class);
        $execution->fail('Do not turn an uncertain completion into a failure.');
    }

    public function test_network_failure_is_uncertain_and_prevents_a_duplicate_request(): void
    {
        $transport = new FunctionFailingHttpClient(
            new ConnectException(
                'Network unavailable',
                new Request('POST', 'https://slack.com/api/functions.completeSuccess'),
            ),
        );
        $execution = $this->execution($transport);

        $this->expectException(ConnectException::class);
        try {
            $execution->complete(['summary' => 'Retry manually with protected state.']);
        } finally {
            self::assertSame(ExecutionState::Uncertain, $execution->state());
            self::assertNotNull($transport->request);
            try {
                $execution->complete(['summary' => 'Second attempt']);
                self::fail('Expected terminal guard.');
            } catch (\LogicException) {
                // An uncertain Slack outcome cannot be retried blindly.
            }
        }
    }

    public function test_context_and_live_execution_are_not_serializable_or_token_revealing(): void
    {
        $context = ExecutionContext::fromArray(FunctionPayloads::executed()) ?? throw new \LogicException(
            'Fixture must describe an execution.',
        );
        $execution = new Execution(new Client(new RecordingHttpClient(), 'xwfp-function-token'), $context);

        self::assertStringNotContainsString('xwfp-function-token', json_encode(
            $context->__debugInfo(),
            JSON_THROW_ON_ERROR,
        ));
        self::assertArrayNotHasKey('token', $context->snapshot());

        $this->expectException(\LogicException::class);
        serialize($execution);
    }

    private function execution(\Psr\Http\Client\ClientInterface $http): Execution
    {
        $context = ExecutionContext::fromArray(FunctionPayloads::executed()) ?? throw new \LogicException(
            'Fixture must describe an execution.',
        );

        return new Execution(new Client($http, $context->workflowToken() ?? ''), $context);
    }
}
