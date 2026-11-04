<?php

declare(strict_types=1);

namespace Thunder\Bolt\WebApi\Requests;

use Thunder\Bolt\Messages\Message;
use Thunder\Bolt\WebApi\RequestPayload;

final readonly class ChatPostEphemeral implements RequestPayload
{
    public function __construct(
        public string $channel,
        public string $user,
        public string|Message $message,
        public ?string $threadTs = null,
    ) {
        self::required($channel, 'channel');
        self::required($user, 'user');
        if ($threadTs !== null) {
            self::timestamp($threadTs);
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $message = $this->message instanceof Message ? $this->message : Message::text($this->message);
        $content = $message->toArray();

        return [
            'channel' => $this->channel,
            'user' => $this->user,
            ...self::fields($content),
            ...($this->threadTs !== null ? ['thread_ts' => $this->threadTs] : []),
        ];
    }

    /** @param array<array-key, mixed> $content @return array<string, mixed> */
    private static function fields(array $content): array
    {
        return array_intersect_key($content, array_flip([
            'as_user',
            'attachments',
            'blocks',
            'icon_emoji',
            'icon_url',
            'link_names',
            'markdown_text',
            'metadata',
            'parse',
            'text',
            'username',
        ]));
    }

    private static function required(string $value, string $name): void
    {
        if ($value === '') {
            throw new \InvalidArgumentException("chat.postEphemeral requires a {$name} ID.");
        }
    }

    private static function timestamp(string $timestamp): void
    {
        if (!preg_match('/\A[0-9]+\.[0-9]+\z/', $timestamp)) {
            throw new \InvalidArgumentException(
                'threadTs must be a Slack parent message timestamp, such as 1234567890.123456.',
            );
        }
    }
}
