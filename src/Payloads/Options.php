<?php

declare(strict_types=1);

namespace Thunder\Bolt\Payloads;

final readonly class Options
{
    public function __construct(
        public string $type,
        public ?string $actionId,
        public ?string $blockId,
        public ?string $callbackId,
        public string $value,
        public ?string $userId,
        public ?string $channelId,
        public array $raw,
    ) {}

    public static function fromArray(array $body): self
    {
        $view = is_array($body['view'] ?? null) ? $body['view'] : [];

        return new self(
            type: self::string($body['type'] ?? null) ?? '',
            actionId: self::string($body['action_id'] ?? null),
            blockId: self::string($body['block_id'] ?? null),
            callbackId: array_key_exists('view', $body)
                ? self::string($view['callback_id'] ?? null)
                : self::string($body['callback_id'] ?? null),
            value: self::string($body['value'] ?? null) ?? '',
            userId: self::nestedString($body, 'user', 'id'),
            channelId: self::nestedString($body, 'channel', 'id'),
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
