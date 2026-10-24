<?php

declare(strict_types=1);

namespace Thunder\Bolt\Payloads\Events;

use Thunder\Bolt\Exceptions\InvalidPayload;
use Thunder\Bolt\Functions\ExecutionContext;
use Thunder\Bolt\Functions\Inputs;
use Thunder\Bolt\Payloads\Event;

final readonly class FunctionExecuted extends Event
{
    public ExecutionContext $execution;
    public string $callbackId;
    public string $executionId;
    public ?string $functionId;
    public ?string $workflowExecutionId;
    public Inputs $inputs;

    public function __construct(array $body)
    {
        $context = ExecutionContext::fromArray([
            'type' => 'event_callback',
            'event' => $body,
        ]) ?? throw new InvalidPayload('Expected a function_executed event.');
        $this->execution = $context;
        $this->callbackId = $context->callbackId ?? throw new InvalidPayload('Missing function callback ID.');
        $this->executionId = $context->executionId;
        $this->functionId = $context->functionId;
        $this->workflowExecutionId = $context->workflowExecutionId;
        $this->inputs = $context->inputs;
        $event = Event::fromArray($body);
        parent::__construct(
            $event->type,
            $event->userId,
            $event->channelId,
            $event->text,
            $event->ts,
            $event->threadTs,
            $body,
        );
    }

    public static function fromArray(array $body): self
    {
        return new self($body);
    }

    public function __debugInfo(): array
    {
        return [
            'type' => $this->type,
            'callbackId' => $this->callbackId,
            'executionId' => $this->executionId,
            'inputNames' => array_keys($this->inputs->toArray()),
            'raw' => '[redacted]',
        ];
    }
}
