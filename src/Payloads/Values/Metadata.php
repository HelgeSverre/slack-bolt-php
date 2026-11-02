<?php

declare(strict_types=1);

namespace Thunder\Bolt\Payloads\Values;

/** Received metadata preserves fields without applying outbound builder restrictions. */
final readonly class Metadata
{
    public string $eventType;
    public array $eventPayload;

    public function __construct(
        public array $raw,
    ) {
        $this->eventType = EventFields::text($raw, 'event_type');
        $this->eventPayload = isset($raw['event_payload']) ? EventFields::object($raw, 'event_payload') : [];
    }

    public static function fromArray(array $body): self
    {
        return new self($body);
    }
}
