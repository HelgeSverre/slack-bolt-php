<?php

declare(strict_types=1);

namespace Thunder\Bolt\WebApi\Requests;

use Thunder\Bolt\Messages\Message;
use Thunder\Bolt\WebApi\RequestPayload;

final readonly class ChatUpdate implements RequestPayload
{
    public function __construct(
        public string $channel,
        public string $timestamp,
        public string|Message $message,
    ) {
        self::channel($channel);
        self::timestamp($timestamp);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $message = $this->message instanceof Message ? $this->message : Message::text($this->message);
        $content = $message->toArray();

        return [
            'channel' => $this->channel,
            'ts' => $this->timestamp,
            ...self::fields($content),
        ];
    }

    /** @param array<array-key, mixed> $content @return array<string, mixed> */
    private static function fields(array $content): array
    {
        return array_intersect_key($content, array_flip([
            'as_user',
            'attachments',
            'blocks',
            'file_ids',
            'link_names',
            'markdown_text',
            'metadata',
            'parse',
            'reply_broadcast',
            'text',
        ]));
    }

    private static function channel(string $channel): void
    {
        if ($channel === '') {
            throw new \InvalidArgumentException('chat.update requires a channel ID.');
        }
    }

    private static function timestamp(string $timestamp): void
    {
        if (!preg_match('/\A[0-9]+\.[0-9]+\z/', $timestamp)) {
            throw new \InvalidArgumentException(
                'timestamp must be a Slack message timestamp, such as 1234567890.123456.',
            );
        }
    }
}
