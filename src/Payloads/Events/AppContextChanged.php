<?php

declare(strict_types=1);

namespace Thunder\Bolt\Payloads\Events;

use Thunder\Bolt\Assistant\AppContext;
use Thunder\Bolt\Payloads\Event;
use Thunder\Bolt\Payloads\Values\EventFields;

final readonly class AppContextChanged extends Event
{
    public AppContext $context;
    public ?string $eventTs;

    public function __construct(array $body)
    {
        EventFields::type($body, 'app_context_changed');
        $this->context = AppContext::fromArray(EventFields::object($body, 'context'));
        $this->eventTs = EventFields::optionalText($body, 'event_ts');
        parent::__construct(
            'app_context_changed',
            EventFields::optionalText($body, 'user'),
            EventFields::optionalText($body, 'channel'),
            null,
            null,
            null,
            $body,
        );
    }

    public static function fromArray(array $body): self
    {
        return new self($body);
    }
}
