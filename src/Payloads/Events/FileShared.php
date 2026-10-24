<?php

declare(strict_types=1);

namespace Thunder\Bolt\Payloads\Events;

use Thunder\Bolt\Exceptions\InvalidPayload;
use Thunder\Bolt\Payloads\Event;

final readonly class FileShared extends Event
{
    public string $fileId;
    public array $file;
    public string $eventTs;

    public function __construct(array $body)
    {
        foreach (['type', 'channel_id', 'file_id', 'user_id', 'event_ts'] as $field) {
            if (!is_string($body[$field] ?? null) || $body[$field] === '') {
                throw new InvalidPayload(
                    'A file shared event requires type, channel, file, user, and event timestamp.',
                );
            }
        }
        if (
            !is_array($body['file'] ?? null)
            || array_is_list($body['file'])
            || !is_string($body['file']['id'] ?? null)
            || $body['file']['id'] === ''
        ) {
            throw new InvalidPayload('A file shared event requires a file object with an ID.');
        }

        $this->fileId = (string) $body['file_id'];
        $this->file = $body['file'];
        $this->eventTs = (string) $body['event_ts'];

        parent::__construct(
            type: (string) $body['type'],
            userId: (string) $body['user_id'],
            channelId: (string) $body['channel_id'],
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
