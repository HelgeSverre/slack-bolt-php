<?php

declare(strict_types=1);

namespace Thunder\Bolt\Payloads\Events;

final readonly class Message
{
    public function __construct(
        public string $userId,
        public string $channelId,
        public string $text,
        public string $ts,
        public ?string $threadTs,
        public array $raw,
    ) {}

    public static function fromArray(array $body): self
    {
        return new self(
            userId: (string) ($body['user'] ?? ''),
            channelId: (string) ($body['channel'] ?? ''),
            text: (string) ($body['text'] ?? ''),
            ts: (string) ($body['ts'] ?? ''),
            threadTs: isset($body['thread_ts']) ? (string) $body['thread_ts'] : null,
            raw: $body,
        );
    }
}
