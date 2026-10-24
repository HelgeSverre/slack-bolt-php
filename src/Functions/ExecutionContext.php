<?php

declare(strict_types=1);

namespace Thunder\Bolt\Functions;

use Thunder\Bolt\Exceptions\InvalidPayload;

final readonly class ExecutionContext
{
    public function __construct(
        public string $executionId,
        public Inputs $inputs,
        public ?string $callbackId = null,
        public ?string $functionId = null,
        public ?string $workflowExecutionId = null,
        #[\SensitiveParameter]
        private ?string $token = null,
        public ?string $interactivityPointer = null,
    ) {
        if (trim($executionId) === '') {
            throw new InvalidPayload('Function execution requires an execution ID.');
        }
        foreach ([$callbackId, $functionId, $workflowExecutionId, $token, $interactivityPointer] as $value) {
            if ($value !== null && trim($value) === '') {
                throw new InvalidPayload('Function context identifiers and credentials cannot be empty.');
            }
        }
    }

    public static function fromArray(array $body): ?self
    {
        $event = $body['event'] ?? null;
        if (
            ($body['type'] ?? null) === 'event_callback'
            && is_array($event)
            && ($event['type'] ?? null) === 'function_executed'
        ) {
            $definition = $event['function'] ?? null;
            if (!is_array($definition)) {
                throw new InvalidPayload('Function execution requires its function definition.');
            }
            $callback = self::field($definition, 'callback_id');
            if ($callback === null) {
                throw new InvalidPayload('Function execution requires a callback ID.');
            }
            $inputs = $event['inputs'] ?? [];
            if (!is_array($inputs)) {
                throw new InvalidPayload('Function inputs must be an object.');
            }
            $interactivity = $event['interactivity'] ?? [];
            if (!is_array($interactivity)) {
                throw new InvalidPayload('Function interactivity must be an object.');
            }
            return new self(
                self::field($event, 'function_execution_id') ?? throw new InvalidPayload(
                    'Missing function execution ID.',
                ),
                self::inputs($inputs),
                $callback,
                self::field($definition, 'id'),
                self::field($event, 'workflow_execution_id'),
                self::field($event, 'bot_access_token'),
                self::field($interactivity, 'interactivity_pointer'),
            );
        }
        if (
            !in_array(
                $body['type'] ?? null,
                ['block_actions', 'view_submission', 'view_closed', 'block_suggestion'],
                true,
            )
            || !array_key_exists('function_data', $body)
        ) {
            return null;
        }
        $data = $body['function_data'];
        if (!is_array($data)) {
            throw new InvalidPayload('Function interaction metadata must be an object.');
        }
        $inputs = $data['inputs'] ?? [];
        if (!is_array($inputs)) {
            throw new InvalidPayload('Function interaction inputs must be an object.');
        }
        $definition = $data['function'] ?? [];
        if (!is_array($definition)) {
            throw new InvalidPayload('Function interaction definition must be an object.');
        }
        return new self(
            self::field($data, 'execution_id') ?? throw new InvalidPayload(
                'Missing function interaction execution ID.',
            ),
            self::inputs($inputs),
            self::field($definition, 'callback_id') ?? self::field($data, 'callback_id'),
            self::field($definition, 'id') ?? self::field($data, 'function_id'),
            self::field($data, 'workflow_execution_id'),
            self::field($body, 'bot_access_token'),
        );
    }

    /** Explicit credential access for a protected, short-lived handoff store. Never put this in a command. */
    public function workflowToken(): ?string
    {
        return $this->token;
    }

    public function snapshot(): array
    {
        return [
            'execution_id' => $this->executionId,
            'inputs' => $this->inputs->toArray(),
            'callback_id' => $this->callbackId,
            'function_id' => $this->functionId,
            'workflow_execution_id' => $this->workflowExecutionId,
            'interactivity_pointer' => $this->interactivityPointer,
        ];
    }

    public static function restore(array $snapshot, #[\SensitiveParameter] ?string $token = null): self
    {
        $inputs = $snapshot['inputs'] ?? null;
        if (!is_array($inputs)) {
            throw new InvalidPayload('Stored function inputs must be an object.');
        }
        return new self(
            self::field($snapshot, 'execution_id') ?? throw new InvalidPayload('Missing stored function execution ID.'),
            self::inputs($inputs),
            self::field($snapshot, 'callback_id'),
            self::field($snapshot, 'function_id'),
            self::field($snapshot, 'workflow_execution_id'),
            $token,
            self::field($snapshot, 'interactivity_pointer'),
        );
    }

    public function __debugInfo(): array
    {
        return [
            'executionId' => $this->executionId,
            'callbackId' => $this->callbackId,
            'inputNames' => array_keys($this->inputs->toArray()),
            'hasWorkflowCredential' => $this->token !== null,
        ];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Do not serialize live function context. Queue a protected execution reference.');
    }

    private static function field(array $data, string $name): ?string
    {
        if (!array_key_exists($name, $data) || $data[$name] === null) {
            return null;
        }
        $value = $data[$name];
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidPayload("Invalid function context field: {$name}.");
        }
        return $value;
    }

    private static function inputs(array $values): Inputs
    {
        try {
            return new Inputs($values);
        } catch (InvalidInputs) {
            throw new InvalidPayload('Function inputs must be an object with named fields.');
        }
    }
}
