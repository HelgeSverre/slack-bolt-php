<?php

declare(strict_types=1);

namespace Thunder\Bolt\Payloads\Events;

use Thunder\Bolt\Exceptions\InvalidPayload;
use Thunder\Bolt\Payloads\Event;

abstract readonly class ChannelMembership extends Event
{
    public string $channelType;
    public string $teamId;
    public ?string $inviterId;
    public ?string $enterpriseId;
    public ?string $eventTs;

    public function __construct(array $body)
    {
        foreach (['type', 'user', 'channel', 'channel_type', 'team'] as $field) {
            if (!is_string($body[$field] ?? null) || $body[$field] === '') {
                throw new InvalidPayload(
                    'A channel membership event requires type, user, channel, channel type, and team.',
                );
            }
        }
        if (isset($body['event_ts']) && (!is_string($body['event_ts']) || $body['event_ts'] === '')) {
            throw new InvalidPayload('A channel membership event has an invalid event timestamp.');
        }

        $this->channelType = (string) $body['channel_type'];
        $this->teamId = (string) $body['team'];
        $this->inviterId = is_string($body['inviter'] ?? null) && $body['inviter'] !== '' ? $body['inviter'] : null;
        $this->enterpriseId = is_string($body['enterprise'] ?? null) && $body['enterprise'] !== ''
            ? $body['enterprise']
            : null;
        $this->eventTs = is_string($body['event_ts'] ?? null) ? $body['event_ts'] : null;

        parent::__construct(
            type: (string) $body['type'],
            userId: (string) $body['user'],
            channelId: (string) $body['channel'],
            text: null,
            ts: null,
            threadTs: null,
            raw: $body,
        );
    }
}
