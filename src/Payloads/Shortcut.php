<?php

declare(strict_types=1);

namespace Thunder\Bolt\Payloads;

final readonly class Shortcut
{
    public function __construct(
        public string $type,
        public string $callbackId,
        public ?string $triggerId,
        public ?string $userId,
        public ?string $channelId,
        public ?string $messageTs,
        public ?string $threadTs,
        public ?string $responseUrl,
        public array $raw,
    ) {}

    public static function fromArray(array $body): self
    {
        $message = is_array($body['message'] ?? null) ? $body['message'] : [];

        return new self(
            type: self::string($body['type'] ?? null) ?? '',
            callbackId: self::string($body['callback_id'] ?? null) ?? '',
            triggerId: self::string($body['trigger_id'] ?? null),
            userId: self::nestedString($body, 'user', 'id'),
            channelId: self::nestedString($body, 'channel', 'id'),
            messageTs: self::string($body['message_ts'] ?? null) ?? self::string($message['ts'] ?? null),
            threadTs: self::string($body['thread_ts'] ?? null) ?? self::string($message['thread_ts'] ?? null),
            responseUrl: self::string($body['response_url'] ?? null),
            raw: $body,
        );
    }

    private static function nestedString(array $body, string $container, string $key): ?string
    {
        return is_array($body[$container] ?? null) ? self::string($body[$container][$key] ?? null) : null;
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
