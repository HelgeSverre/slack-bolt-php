<?php

declare(strict_types=1);

namespace Thunder\Bolt\Payloads\Events;

use Thunder\Bolt\Payloads\Event;
use Thunder\Bolt\Payloads\Values\EventFields;

final readonly class AppHomeOpened extends Event
{
    public ?string $tab;
    public ?string $eventTs;
    public ?array $view;
    public ?array $context;

    public function __construct(array $body)
    {
        EventFields::type($body, 'app_home_opened');
        $this->tab = EventFields::optionalText($body, 'tab');
        $this->eventTs = EventFields::optionalText($body, 'event_ts');
        $this->view = isset($body['view']) ? EventFields::object($body, 'view') : null;
        $this->context = isset($body['context']) ? EventFields::object($body, 'context') : null;
        parent::__construct(
            'app_home_opened',
            EventFields::text($body, 'user'),
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
