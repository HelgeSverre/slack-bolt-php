<?php

declare(strict_types=1);

namespace Thunder\Bolt\Payloads\Events;

use Thunder\Bolt\Assistant\AppContext;
use Thunder\Bolt\Payloads\Values\EventFields;

final readonly class Message
{
    public function __construct(
        public string $userId,
        public string $channelId,
        public string $text,
        public string $ts,
        public ?string $threadTs,
        public array $raw,
        public ?AppContext $appContext = null,
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
            appContext: isset($body['app_context'])
                ? AppContext::fromArray(EventFields::object($body, 'app_context'))
                : null,
        );
    }
}
