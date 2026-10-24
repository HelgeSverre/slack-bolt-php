<?php

declare(strict_types=1);

namespace Thunder\Bolt\Jobs;

use Thunder\Bolt\Authorization\AuthorizationSource;
use Thunder\Bolt\Conversation\ConversationKey;
use Thunder\Bolt\Invocation\Delivery;

/** Portable identity, never a live delivery, client, credential or conversation snapshot. */
final readonly class JobContext
{
    public function __construct(
        public ?string $teamId = null,
        public ?string $enterpriseId = null,
        public bool $isEnterpriseInstall = false,
        public ?string $channelId = null,
        public ?string $threadTs = null,
        public ?string $userId = null,
        public ?string $deliveryId = null,
    ) {
        foreach ([$teamId, $enterpriseId, $channelId, $threadTs, $userId, $deliveryId] as $id) {
            if ($id !== null && (trim($id) === '' || strlen($id) > 512)) {
                throw new \InvalidArgumentException('Job context identifiers must contain 1 to 512 bytes.');
            }
        }
        if ($isEnterpriseInstall ? $enterpriseId === null : $teamId === null) {
            throw new \InvalidArgumentException(
                'A job needs an explicit workspace or enterprise installation identity.',
            );
        }
        if ($threadTs !== null && $channelId === null) {
            throw new \InvalidArgumentException('A job thread needs a channel.');
        }
    }

    public static function workspace(
        string $teamId,
        ?string $channelId = null,
        ?string $threadTs = null,
        ?string $userId = null,
    ): self {
        return new self(teamId: $teamId, channelId: $channelId, threadTs: $threadTs, userId: $userId);
    }

    public static function fromDelivery(#[\SensitiveParameter] Delivery $delivery): self
    {
        if ($delivery->execution !== null) {
            throw new \LogicException(
                'Workflow jobs require an explicit protected execution reference; do not queue a live function delivery.',
            );
        }
        $source = $delivery->authorizationSource ?? throw new \LogicException(
            'A job needs a trusted authorization source.',
        );
        $authorization = $delivery->authorization;
        $thread = $delivery->thread?->key;
        $conversation = $delivery->conversation?->key;
        return new self(
            teamId: $authorization?->teamId ?? $source->teamId,
            enterpriseId: $authorization?->enterpriseId ?? $source->enterpriseId,
            isEnterpriseInstall: ($authorization?->isEnterpriseInstall ?? false) || $source->isEnterpriseInstall,
            channelId: $conversation?->conversationId ?? $source->conversationId,
            threadTs: $thread?->threadTs,
            userId: $source->userId,
            deliveryId: is_string($delivery->metadata['eventId'] ?? null) ? $delivery->metadata['eventId'] : null,
        );
    }

    public function tenant(): string
    {
        return $this->isEnterpriseInstall
            ? 'enterprise:' . (string) $this->enterpriseId
            : 'team:' . (string) $this->teamId;
    }

    public function withDeliveryId(string $id): self
    {
        return new self(
            $this->teamId,
            $this->enterpriseId,
            $this->isEnterpriseInstall,
            $this->channelId,
            $this->threadTs,
            $this->userId,
            $id,
        );
    }

    public function source(): AuthorizationSource
    {
        return new AuthorizationSource(
            $this->teamId,
            $this->enterpriseId,
            $this->userId,
            $this->channelId,
            $this->isEnterpriseInstall,
        );
    }

    public function conversationKey(): ?ConversationKey
    {
        return $this->channelId === null
            ? null
            : new ConversationKey(
                $this->channelId,
                $this->isEnterpriseInstall ? null : $this->teamId,
                $this->enterpriseId,
                $this->isEnterpriseInstall,
            );
    }

    public function threadKey(): ?ConversationKey
    {
        return $this->threadTs === null ? null : $this->conversationKey()?->thread($this->threadTs);
    }

    public function toArray(): array
    {
        return [
            'team_id' => $this->teamId,
            'enterprise_id' => $this->enterpriseId,
            'enterprise_install' => $this->isEnterpriseInstall,
            'channel_id' => $this->channelId,
            'thread_ts' => $this->threadTs,
            'user_id' => $this->userId,
            'delivery_id' => $this->deliveryId,
        ];
    }

    public static function fromArray(array $data): self
    {
        if (!is_bool($data['enterprise_install'] ?? null)) {
            throw new \UnexpectedValueException('Invalid stored job installation scope.');
        }
        return new self(
            self::field($data, 'team_id'),
            self::field($data, 'enterprise_id'),
            $data['enterprise_install'],
            self::field($data, 'channel_id'),
            self::field($data, 'thread_ts'),
            self::field($data, 'user_id'),
            self::field($data, 'delivery_id'),
        );
    }

    private static function field(array $data, string $name): ?string
    {
        $value = $data[$name] ?? null;
        return $value === null || is_string($value)
            ? $value
            : throw new \UnexpectedValueException('Invalid stored job context.');
    }
}
