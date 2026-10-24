<?php

declare(strict_types=1);

namespace Thunder\Bolt\Assistant;

use Thunder\Bolt\Conversation\ConversationKey;

final readonly class ThreadIdentity
{
    public function __construct(
        public ConversationKey $key,
        public string $userId,
        public ?string $botUserId = null,
    ) {
        if ($key->threadTs === null) {
            throw new \InvalidArgumentException('An assistant thread identity requires a root timestamp.');
        }
        if ($userId === '') {
            throw new \InvalidArgumentException('An assistant thread identity requires a user ID.');
        }
    }

    public function storageKey(): ConversationKey
    {
        return new ConversationKey(
            conversationId: 'assistant:' . $this->key->conversationId,
            teamId: $this->key->teamId,
            enterpriseId: $this->key->enterpriseId,
            isEnterpriseInstall: $this->key->isEnterpriseInstall,
            threadTs: $this->key->threadTs,
        );
    }

    public function channelId(): string
    {
        return $this->key->conversationId;
    }

    public function threadTs(): string
    {
        return $this->key->threadTs ?? throw new \LogicException('An assistant thread needs a root timestamp.');
    }
}
