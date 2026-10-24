<?php

declare(strict_types=1);

namespace Thunder\Bolt\Payloads\Events;

use Thunder\Bolt\Payloads\Event;

final readonly class AppUninstalled extends Event
{
    public function __construct(array $body)
    {
        parent::__construct(
            type: is_string($body['type'] ?? null) ? $body['type'] : 'app_uninstalled',
            userId: null,
            channelId: null,
            text: null,
            ts: null,
            threadTs: null,
            raw: $body,
        );
    }

    public static function fromArray(array $body): self
    {
        return new self($body);
    }
}
