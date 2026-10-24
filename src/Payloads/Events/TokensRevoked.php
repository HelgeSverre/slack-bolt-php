<?php

declare(strict_types=1);

namespace Thunder\Bolt\Payloads\Events;

use Thunder\Bolt\Exceptions\InvalidPayload;
use Thunder\Bolt\Payloads\Event;

final readonly class TokensRevoked extends Event
{
    public array $oauthUserIds;
    public array $botUserIds;

    public function __construct(array $body)
    {
        $tokens = $body['tokens'] ?? null;
        if (!is_array($tokens) || array_is_list($tokens)) {
            throw new InvalidPayload('A tokens revoked event requires token groups.');
        }
        $this->oauthUserIds = self::userIds($tokens['oauth'] ?? null);
        $this->botUserIds = self::userIds($tokens['bot'] ?? null);

        parent::__construct(
            type: is_string($body['type'] ?? null) ? $body['type'] : 'tokens_revoked',
            userId: null,
            channelId: null,
            text: null,
            ts: null,
            threadTs: null,
            raw: $body,
        );
    }

    public static function fromArray(array $body): self
    {
        return new self($body);
    }

    private static function userIds(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidPayload('A revoked token group must be a list of user IDs.');
        }
        $userIds = [];
        foreach ($value as $userId) {
            if (!is_string($userId) || $userId === '') {
                throw new InvalidPayload('A revoked token group must contain user IDs.');
            }
            $userIds[] = $userId;
        }

        return $userIds;
    }
}
