<?php

declare(strict_types=1);

namespace Thunder\Bolt\Payloads\Events;

use Thunder\Bolt\Exceptions\InvalidPayload;
use Thunder\Bolt\Payloads\Event;

final readonly class LinkShared extends Event
{
    public string $messageTs;
    public array $links;
    public ?bool $isBotUserMember;
    public ?bool $isUnfurlRefresh;
    public ?string $unfurlId;
    public ?string $source;
    public ?string $userLocale;

    public function __construct(array $body)
    {
        foreach (['type', 'channel', 'user', 'message_ts'] as $field) {
            if (!is_string($body[$field] ?? null) || $body[$field] === '') {
                throw new InvalidPayload('A link shared event requires type, channel, user, and message timestamp.');
            }
        }
        foreach (['is_bot_user_member', 'is_unfurl_refresh'] as $field) {
            if (isset($body[$field]) && !is_bool($body[$field])) {
                throw new InvalidPayload('A link shared event has an invalid boolean field.');
            }
        }

        $this->messageTs = (string) $body['message_ts'];
        $this->links = self::links($body['links'] ?? null);
        $this->isBotUserMember = is_bool($body['is_bot_user_member'] ?? null) ? $body['is_bot_user_member'] : null;
        $this->isUnfurlRefresh = is_bool($body['is_unfurl_refresh'] ?? null) ? $body['is_unfurl_refresh'] : null;
        $this->unfurlId = self::optionalString($body, 'unfurl_id');
        $this->source = self::optionalString($body, 'source');
        $this->userLocale = self::optionalString($body, 'user_locale');

        parent::__construct(
            type: (string) $body['type'],
            userId: (string) $body['user'],
            channelId: (string) $body['channel'],
            text: null,
            ts: null,
            threadTs: self::optionalString($body, 'thread_ts'),
            raw: $body,
        );
    }

    public static function fromArray(array $body): self
    {
        return new self($body);
    }

    private static function optionalString(array $body, string $field): ?string
    {
        return is_string($body[$field] ?? null) && $body[$field] !== '' ? $body[$field] : null;
    }

    private static function links(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw new InvalidPayload('A link shared event requires one or more links.');
        }
        foreach ($value as $link) {
            if (
                !is_array($link)
                || !is_string($link['domain'] ?? null)
                || $link['domain'] === ''
                || !is_string($link['url'] ?? null)
                || $link['url'] === ''
            ) {
                throw new InvalidPayload('A link shared event has an invalid link.');
            }
        }

        return $value;
    }
}
