<?php

declare(strict_types=1);

namespace Thunder\Bolt\Payloads\Events;

use Thunder\Bolt\Exceptions\InvalidPayload;
use Thunder\Bolt\Payloads\Event;

abstract readonly class ReactionEvent extends Event
{
    public string $reaction;
    public ?string $itemUserId;
    public array $item;
    public string $eventTs;

    public function __construct(array $body)
    {
        foreach (['type', 'user', 'reaction', 'event_ts'] as $field) {
            if (!is_string($body[$field] ?? null) || $body[$field] === '') {
                throw new InvalidPayload('A reaction event requires type, user, reaction, and event timestamp.');
            }
        }
        if (
            !is_array($body['item'] ?? null)
            || array_is_list($body['item'])
            || !is_string($body['item']['type'] ?? null)
            || $body['item']['type'] === ''
        ) {
            throw new InvalidPayload('A reaction event requires an item reference.');
        }

        $item = $body['item'];
        $this->reaction = (string) $body['reaction'];
        $this->itemUserId = is_string($body['item_user'] ?? null) && $body['item_user'] !== ''
            ? $body['item_user']
            : null;
        $this->item = $item;
        $this->eventTs = (string) $body['event_ts'];

        parent::__construct(
            type: (string) $body['type'],
            userId: (string) $body['user'],
            channelId: null,
            text: null,
            ts: null,
            threadTs: null,
            raw: $body,
        );
    }
}
