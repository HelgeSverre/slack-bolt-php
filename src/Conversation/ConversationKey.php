<?php

declare(strict_types=1);

namespace Thunder\Bolt\Conversation;

final readonly class ConversationKey
{
    public function __construct(
        public string $conversationId,
        public ?string $teamId = null,
        public ?string $enterpriseId = null,
        public bool $isEnterpriseInstall = false,
        public ?string $threadTs = null,
    ) {
        if ($conversationId === '') {
            throw new \InvalidArgumentException('A conversation key needs a conversation ID.');
        }
        if ($isEnterpriseInstall && ($enterpriseId === null || $enterpriseId === '')) {
            throw new \InvalidArgumentException('Enterprise conversation keys require an enterprise ID.');
        }
        if ($threadTs === '') {
            throw new \InvalidArgumentException('A thread timestamp must not be empty.');
        }
    }

    public function thread(string $threadTs): self
    {
        return new self(
            $this->conversationId,
            $this->teamId,
            $this->enterpriseId,
            $this->isEnterpriseInstall,
            $threadTs,
        );
    }

    /** Opaque, stable storage identity; the Slack IDs themselves are never filenames. */
    public function key(): string
    {
        return hash('sha256', json_encode([
            'tenant' => $this->tenant(),
            'conversationId' => $this->conversationId,
            'threadTs' => $this->threadTs,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array{scope:'enterprise'|'team'|'static', id:?string} */
    private function tenant(): array
    {
        if ($this->isEnterpriseInstall) {
            return ['scope' => 'enterprise', 'id' => $this->enterpriseId];
        }
        if ($this->teamId !== null && $this->teamId !== '') {
            return ['scope' => 'team', 'id' => $this->teamId];
        }

        return ['scope' => 'static', 'id' => null];
    }
}
