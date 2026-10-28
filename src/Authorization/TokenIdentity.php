<?php

declare(strict_types=1);

namespace Thunder\Bolt\Authorization;

final readonly class TokenIdentity implements \JsonSerializable
{
    public function __construct(
        public string $userId,
        public ?string $botId,
        public ?string $teamId,
        public ?string $enterpriseId,
        public bool $isEnterpriseInstall = false,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'user_id' => $this->userId,
            'bot_id' => $this->botId,
            'team_id' => $this->teamId,
            'enterprise_id' => $this->enterpriseId,
            'is_enterprise_install' => $this->isEnterpriseInstall,
        ];
    }
}
