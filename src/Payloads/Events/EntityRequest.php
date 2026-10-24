<?php

declare(strict_types=1);

namespace Thunder\Bolt\Payloads\Events;

use Thunder\Bolt\Exceptions\InvalidPayload;
use Thunder\Bolt\Payloads\Event;
use Thunder\Bolt\WorkObjects\ExternalReference;

readonly class EntityRequest extends Event
{
    public string $triggerId;
    public ExternalReference $reference;
    public ?string $entityUrl;

    public function __construct(array $body)
    {
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
        if (
            !is_string($body['trigger_id'] ?? null)
            || $body['trigger_id'] === ''
            || !is_array($body['external_ref'] ?? null)
        ) {
            throw new InvalidPayload('An entity request requires a trigger and external reference.');
        }
        $this->triggerId = $body['trigger_id'];
        try {
            $this->reference = ExternalReference::fromArray($body['external_ref']);
        } catch (\InvalidArgumentException) {
            throw new InvalidPayload('Invalid entity reference.');
        }
        $this->entityUrl = is_string($body['entity_url'] ?? null) ? $body['entity_url'] : null;
    }

    public static function fromArray(array $body): static
    {
        return new static($body);
    }
}
