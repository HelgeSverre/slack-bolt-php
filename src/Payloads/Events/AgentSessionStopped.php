<?php

declare(strict_types=1);

namespace Thunder\Bolt\Payloads\Events;

use Thunder\Bolt\Exceptions\InvalidPayload;

final readonly class AgentSessionStopped extends AgentSessionEvent
{
    public array $streamingMessageTs;

    public function __construct(array $body)
    {
        parent::__construct($body);
        $this->streamingMessageTs = self::timestamps($body['streaming_message_ts'] ?? null);
    }

    public static function fromArray(array $body): self
    {
        return new self($body);
    }

    private static function timestamps(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidPayload('An agent session stopped event requires streaming message timestamps.');
        }
        $timestamps = [];
        foreach ($value as $timestamp) {
            if (!is_string($timestamp) || $timestamp === '') {
                throw new InvalidPayload('An agent session stopped event has an invalid streaming message timestamp.');
            }
            $timestamps[] = $timestamp;
        }

        return $timestamps;
    }
}
