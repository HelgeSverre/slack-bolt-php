<?php

declare(strict_types=1);

namespace Thunder\Bolt\OAuth;

final readonly class InstallationQuery
{
    public function __construct(
        public ?string $teamId = null,
        public ?string $enterpriseId = null,
        public ?string $userId = null,
        public ?string $conversationId = null,
        public bool $isEnterpriseInstall = false,
    ) {
        if ($isEnterpriseInstall) {
            if ($enterpriseId === null || $enterpriseId === '' || $teamId !== null) {
                throw new \InvalidArgumentException(
                    'Enterprise installation queries require an enterprise ID and no team ID.',
                );
            }

            return;
        }

        if ($teamId === null || $teamId === '') {
            throw new \InvalidArgumentException('Workspace installation queries require a team ID.');
        }
    }

    public function forBot(): self
    {
        return new self($this->teamId, $this->enterpriseId, null, $this->conversationId, $this->isEnterpriseInstall);
    }

    public function forUser(string $userId): self
    {
        if ($userId === '') {
            throw new \InvalidArgumentException('A user-scoped installation query needs a user ID.');
        }

        return new self($this->teamId, $this->enterpriseId, $userId, $this->conversationId, $this->isEnterpriseInstall);
    }

    /** A credential-free identity for file and memory store partitioning. */
    public function scopeKey(): string
    {
        return json_encode([
            'teamId' => $this->teamId,
            'enterpriseId' => $this->enterpriseId,
            'isEnterpriseInstall' => $this->isEnterpriseInstall,
        ], JSON_THROW_ON_ERROR);
    }
}
