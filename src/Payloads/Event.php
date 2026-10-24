<?php

declare(strict_types=1);

namespace Thunder\Bolt\Payloads;

readonly class Event
{
    public function __construct(
        public string $type,
        public ?string $userId,
        public ?string $channelId,
        public ?string $text,
        public ?string $ts,
        public ?string $threadTs,
        public array $raw,
    ) {}

    public static function fromArray(array $body): self
    {
        return new self(
            type: (string) ($body['type'] ?? 'unknown'),
            userId: self::stringOrNull($body['user'] ?? null),
            channelId: self::stringOrNull($body['channel'] ?? null),
            text: self::stringOrNull($body['text'] ?? null),
            ts: self::stringOrNull($body['ts'] ?? null),
            threadTs: self::stringOrNull($body['thread_ts'] ?? null),
            raw: $body,
        );
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) || is_numeric($value) ? (string) $value : null;
    }
}
