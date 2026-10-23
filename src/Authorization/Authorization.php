<?php

declare(strict_types=1);

namespace Thunder\Bolt\Authorization;

final readonly class Authorization
{
    /**
     * @param  list<string>  $botScopes
     * @param  list<string>  $userScopes
     */
    public function __construct(
        #[\SensitiveParameter]
        public ?string $botToken = null,
        #[\SensitiveParameter]
        public ?string $userToken = null,
        public ?string $botUserId = null,
        public ?string $botId = null,
        public ?string $userId = null,
        public ?string $teamId = null,
        public ?string $enterpriseId = null,
        public bool $isEnterpriseInstall = false,
        public array $botScopes = [],
        public array $userScopes = [],
        public ?int $botTokenExpiresAt = null,
        public ?int $userTokenExpiresAt = null,
    ) {}

    /** Slack's bot credential deliberately wins when both are installed. */
    public function token(): string
    {
        if ($this->botToken !== null && $this->botToken !== '') {
            return $this->botToken;
        }
        if ($this->userToken !== null && $this->userToken !== '') {
            return $this->userToken;
        }

        throw new AuthorizationException('No Slack credential is available for this installation.');
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'botToken' => $this->botToken === null ? null : '[redacted]',
            'userToken' => $this->userToken === null ? null : '[redacted]',
            'botUserId' => $this->botUserId,
            'botId' => $this->botId,
            'userId' => $this->userId,
            'teamId' => $this->teamId,
            'enterpriseId' => $this->enterpriseId,
            'isEnterpriseInstall' => $this->isEnterpriseInstall,
            'botScopes' => $this->botScopes,
            'userScopes' => $this->userScopes,
            'botTokenExpiresAt' => $this->botTokenExpiresAt,
            'userTokenExpiresAt' => $this->userTokenExpiresAt,
        ];
    }
}
