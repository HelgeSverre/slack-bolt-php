<?php

declare(strict_types=1);

namespace Thunder\Bolt\Payloads;

final readonly class Action
{
    public function __construct(
        public string $type,
        public ?string $actionId,
        public ?string $blockId,
        public ?string $callbackId,
        public ?string $userId,
        public ?string $channelId,
        public ?string $messageTs,
        public ?string $threadTs,
        public ?string $triggerId,
        public ?string $responseUrl,
        public array $action,
        public array $raw,
    ) {}

    public static function fromArray(array $body): self
    {
        $action = is_array($body['actions'][0] ?? null) ? $body['actions'][0] : [];
        $view = is_array($body['view'] ?? null) ? $body['view'] : [];
        $message = is_array($body['message'] ?? null) ? $body['message'] : [];
        $container = is_array($body['container'] ?? null) ? $body['container'] : [];

        return new self(
            type: self::string($body['type'] ?? null) ?? '',
            actionId: self::string($action['action_id'] ?? null),
            blockId: self::string($action['block_id'] ?? null),
            callbackId: array_key_exists('view', $body)
                ? self::string($view['callback_id'] ?? null)
                : self::string($body['callback_id'] ?? null),
            userId: self::nestedString($body, 'user', 'id'),
            channelId: self::nestedString($body, 'channel', 'id'),
            messageTs: self::string($body['message_ts'] ?? null)
            ?? self::string($container['message_ts'] ?? null)
            ?? self::string($message['ts'] ?? null),
            threadTs: self::string($body['thread_ts'] ?? null) ?? self::string($message['thread_ts'] ?? null),
            triggerId: self::string($body['trigger_id'] ?? null),
            responseUrl: self::string($body['response_url'] ?? null),
            action: $action,
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
