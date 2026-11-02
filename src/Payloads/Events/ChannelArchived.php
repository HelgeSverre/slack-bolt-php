<?php

declare(strict_types=1);

namespace Thunder\Bolt\Payloads\Events;

use Thunder\Bolt\Payloads\Values\EventFields;

final readonly class ChannelArchived extends ChannelEvent
{
    public function __construct(array $body)
    {
        EventFields::type($body, 'channel_archive');
        parent::__construct($body);
    }

    public static function fromArray(array $body): self
    {
        return new self($body);
    }
}
