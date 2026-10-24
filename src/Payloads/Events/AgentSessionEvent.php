<?php

declare(strict_types=1);

namespace Thunder\Bolt\Payloads\Events;

use Thunder\Bolt\Exceptions\InvalidPayload;
use Thunder\Bolt\Payloads\Event;

abstract readonly class AgentSessionEvent extends Event
{
    public string $eventTs;

    public function __construct(array $body)
    {
        foreach (['type', 'channel', 'user', 'event_ts'] as $field) {
            if (!is_string($body[$field] ?? null) || $body[$field] === '') {
                throw new InvalidPayload('An agent session event requires type, channel, user, and event timestamp.');
            }
        }
        if (isset($body['thread_ts']) && (!is_string($body['thread_ts']) || $body['thread_ts'] === '')) {
            throw new InvalidPayload('An agent session event has an invalid thread timestamp.');
        }

        $this->eventTs = (string) $body['event_ts'];

        parent::__construct(
            type: (string) $body['type'],
            userId: (string) $body['user'],
            channelId: (string) $body['channel'],
            text: null,
            ts: null,
            threadTs: is_string($body['thread_ts'] ?? null) ? $body['thread_ts'] : null,
            raw: $body,
        );
    }
}
