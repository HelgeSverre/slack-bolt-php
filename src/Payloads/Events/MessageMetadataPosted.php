<?php

declare(strict_types=1);

namespace Thunder\Bolt\Payloads\Events;

use Thunder\Bolt\Payloads\Values\EventFields;

final readonly class MessageMetadataPosted extends MessageMetadataEvent
{
    public function __construct(array $body)
    {
        EventFields::type($body, 'message_metadata_posted');
        EventFields::object($body, 'metadata');
        parent::__construct($body);
    }

    public static function fromArray(array $body): self
    {
        return new self($body);
    }
}
