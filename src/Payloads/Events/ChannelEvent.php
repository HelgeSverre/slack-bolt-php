<?php

declare(strict_types=1);

namespace Thunder\Bolt\Payloads\Events;

use Thunder\Bolt\Payloads\Event;
use Thunder\Bolt\Payloads\Values\EventFields;

abstract readonly class ChannelEvent extends Event
{
    public ?string $eventTs;
    public ?string $name;
    public ?string $creatorId;
    public array $channel;

    public function __construct(array $body)
    {
        $channel = is_array($body['channel'] ?? null)
            ? EventFields::object($body, 'channel')
            : ['id' => EventFields::text($body, 'channel')];
        $this->channel = $channel;
        $this->name = EventFields::optionalText($channel, 'name');
        $this->creatorId = EventFields::optionalText($channel, 'creator');
        $this->eventTs = EventFields::optionalText($body, 'event_ts');
        parent::__construct(
            EventFields::text($body, 'type'),
            EventFields::optionalText($body, 'user'),
            EventFields::text($channel, 'id'),
            null,
            null,
            null,
            $body,
        );
    }
}
