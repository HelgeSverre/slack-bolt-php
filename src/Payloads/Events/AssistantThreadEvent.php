<?php

declare(strict_types=1);

namespace Thunder\Bolt\Payloads\Events;

use Thunder\Bolt\Assistant\ThreadContext;
use Thunder\Bolt\Exceptions\InvalidPayload;
use Thunder\Bolt\Payloads\Event;

readonly class AssistantThreadEvent extends Event
{
    public ThreadContext $context;

    public function __construct(array $body)
    {
        $thread = $body['assistant_thread'] ?? null;
        if (!is_array($thread)) {
            throw new InvalidPayload('An assistant lifecycle event requires assistant_thread.');
        }
        foreach (['channel_id', 'thread_ts', 'user_id'] as $field) {
            if (!is_string($thread[$field] ?? null) || trim($thread[$field]) === '') {
                throw new InvalidPayload('An assistant lifecycle event requires channel, thread and user identifiers.');
            }
        }
        $context = $thread['context'] ?? [];
        if (!is_array($context) || array_any(array_keys($context), static fn($key): bool => !is_string($key))) {
            throw new InvalidPayload('Assistant thread context must be an object.');
        }
        try {
            /** @var array<string, mixed> $context */
            $this->context = ThreadContext::fromArray($context);
        } catch (\InvalidArgumentException $exception) {
            throw new InvalidPayload($exception->getMessage());
        }
        parent::__construct(
            (string) ($body['type'] ?? 'unknown'),
            (string) $thread['user_id'],
            (string) $thread['channel_id'],
            null,
            is_string($body['event_ts'] ?? null) ? $body['event_ts'] : null,
            (string) $thread['thread_ts'],
            $body,
        );
    }

    public static function fromArray(array $body): static
    {
        return new static($body);
    }
}
