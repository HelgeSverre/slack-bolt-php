<?php

declare(strict_types=1);

namespace Thunder\Bolt\Payloads\Events;

use Thunder\Bolt\Payloads\Event;
use Thunder\Bolt\Payloads\Values\EventFields;
use Thunder\Bolt\Payloads\Values\Metadata;

abstract readonly class MessageMetadataEvent extends Event
{
    public string $messageTs;
    public string $eventTs;
    public ?string $appId;
    public ?string $botId;
    public ?string $teamId;
    public ?string $deletedTs;
    public ?Metadata $metadata;
    public ?Metadata $previousMetadata;

    public function __construct(array $body)
    {
        $this->messageTs = EventFields::text($body, 'message_ts');
        $this->eventTs = EventFields::text($body, 'event_ts');
        $this->appId = EventFields::optionalText($body, 'app_id');
        $this->botId = EventFields::optionalText($body, 'bot_id');
        $this->teamId = EventFields::optionalText($body, 'team_id');
        $this->deletedTs = EventFields::optionalText($body, 'deleted_ts');
        $this->metadata = isset($body['metadata']) ? Metadata::fromArray(EventFields::object($body, 'metadata')) : null;
        $this->previousMetadata = isset($body['previous_metadata'])
            ? Metadata::fromArray(EventFields::object($body, 'previous_metadata'))
            : null;
        parent::__construct(
            EventFields::text($body, 'type'),
            EventFields::optionalText($body, 'user_id'),
            EventFields::text($body, 'channel_id'),
            null,
            null,
            null,
            $body,
        );
    }
}
