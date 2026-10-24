<?php

declare(strict_types=1);

namespace Thunder\Bolt\Functions;

use Thunder\Bolt\Generated\WebApi\Models\Responses\FunctionsCompleteErrorResponse;
use Thunder\Bolt\Generated\WebApi\Models\Responses\FunctionsCompleteSuccessResponse;
use Thunder\Bolt\WebApi\Client;

final class Execution
{
    private ExecutionState $state = ExecutionState::Pending;

    public function __construct(
        private readonly Client $client,
        public readonly ExecutionContext $context,
    ) {}

    public function client(): Client
    {
        return $this->client;
    }

    public function inputs(): Inputs
    {
        return $this->context->inputs;
    }

    public function state(): ExecutionState
    {
        return $this->state;
    }

    public function hasResponded(): bool
    {
        return $this->state !== ExecutionState::Pending;
    }

    public function complete(array|\JsonSerializable $outputs = []): FunctionsCompleteSuccessResponse
    {
        $outputs = $outputs instanceof \JsonSerializable ? $outputs->jsonSerialize() : $outputs;
        if (!is_array($outputs) || array_any(array_keys($outputs), static fn($key): bool => !is_string($key))) {
            throw new \InvalidArgumentException('Function outputs must be an object with named fields.');
        }
        json_encode($outputs, JSON_THROW_ON_ERROR);
        $this->begin();
        try {
            $response = $this->client
                ->functions()
                ->completeSuccess([
                    'function_execution_id' => $this->context->executionId,
                    'outputs' => $outputs === [] ? new \stdClass() : $outputs,
                ]);
            $this->state = ExecutionState::Completed;
            return $response;
        } catch (\Throwable $exception) {
            $this->state = ExecutionState::Uncertain;
            throw $exception;
        }
    }

    public function fail(string $error): FunctionsCompleteErrorResponse
    {
        if (trim($error) === '') {
            throw new \InvalidArgumentException('Function failure requires a non-empty error message.');
        }
        $this->begin();
        try {
            $response = $this->client
                ->functions()
                ->completeError([
                    'function_execution_id' => $this->context->executionId,
                    'error' => $error,
                ]);
            $this->state = ExecutionState::Failed;
            return $response;
        } catch (\Throwable $exception) {
            $this->state = ExecutionState::Uncertain;
            throw $exception;
        }
    }

    public function __debugInfo(): array
    {
        return ['executionId' => $this->context->executionId, 'state' => $this->state->value];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Do not serialize a live function execution. Queue a protected execution reference.');
    }

    private function begin(): void
    {
        if ($this->state !== ExecutionState::Pending) {
            throw new \LogicException('A function execution can be completed or failed only once per delivery.');
        }
        $this->state = ExecutionState::Responding;
    }
}
