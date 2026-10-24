<?php

declare(strict_types=1);

namespace Thunder\Bolt\Payloads\Events;

final readonly class ReactionRemoved extends ReactionEvent
{
    public static function fromArray(array $body): self
    {
        return new self($body);
    }
}
