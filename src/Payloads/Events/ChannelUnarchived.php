<?php

declare(strict_types=1);

namespace Thunder\Bolt\Payloads\Events;

use Thunder\Bolt\Payloads\Values\EventFields;

final readonly class ChannelUnarchived extends ChannelEvent
{
    public function __construct(array $body)
    {
        EventFields::type($body, 'channel_unarchive');
        parent::__construct($body);
    }

    public static function fromArray(array $body): self
    {
        return new self($body);
    }
}
