<?php

declare(strict_types=1);

namespace Thunder\Bolt\Payloads\Events;

use Thunder\Bolt\Exceptions\InvalidPayload;

final readonly class EntityCommentsRequested extends EntityRequest
{
    public ?string $cursor;
    public ?string $threadRootId;
    public int $limit;

    public function __construct(array $body)
    {
        parent::__construct($body);
        if (isset($body['limit']) && (!is_int($body['limit']) || $body['limit'] < 1 || $body['limit'] > 100)) {
            throw new InvalidPayload('Invalid comment page limit.');
        }
        foreach (['cursor', 'thread_root_id'] as $field) {
            if (isset($body[$field]) && !is_string($body[$field])) {
                throw new InvalidPayload('Invalid comment cursor or thread.');
            }
        }
        $this->cursor = is_string($body['cursor'] ?? null) && $body['cursor'] !== '' ? $body['cursor'] : null;
        $this->threadRootId = is_string($body['thread_root_id'] ?? null) && $body['thread_root_id'] !== ''
            ? $body['thread_root_id']
            : null;
        $this->limit = $body['limit'] ?? 100;
    }
}
