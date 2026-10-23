<?php

declare(strict_types=1);

namespace Thunder\Bolt\Payloads;

final readonly class SlashCommand
{
    public function __construct(
        public string $text,
        public string $userId,
        public string $channelId,
        public string $command,
        public string $responseUrl,
        public array $raw,
    ) {}

    public static function fromArray(array $body): self
    {
        return new self(
            text: (string) ($body['text'] ?? ''),
            userId: (string) ($body['user_id'] ?? ''),
            channelId: (string) ($body['channel_id'] ?? ''),
            command: (string) ($body['command'] ?? ''),
            responseUrl: (string) ($body['response_url'] ?? ''),
            raw: $body,
        );
    }
}
