<?php

declare(strict_types=1);

namespace Thunder\Bolt\Payloads\Events;

use Thunder\Bolt\Payloads\Values\EventFields;

final readonly class ChannelRenamed extends ChannelEvent
{
    public function __construct(array $body)
    {
        EventFields::type($body, 'channel_rename');
        $channel = EventFields::object($body, 'channel');
        EventFields::text($channel, 'name');
        parent::__construct($body);
    }

    public static function fromArray(array $body): self
    {
        return new self($body);
    }
}
