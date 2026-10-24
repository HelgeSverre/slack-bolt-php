<?php

declare(strict_types=1);

namespace Thunder\Bolt\Authorization;

use Thunder\Bolt\OAuth\InstallationQuery;

final readonly class AuthorizationSource
{
    public function __construct(
        public ?string $teamId = null,
        public ?string $enterpriseId = null,
        public ?string $userId = null,
        public ?string $conversationId = null,
        public bool $isEnterpriseInstall = false,
        public array $raw = [],
    ) {}

    public static function fromArray(array $envelope): self
    {
        if (is_array($envelope['event'] ?? null)) {
            return self::event($envelope);
        }
        if (is_string($envelope['command'] ?? null)) {
            return new self(
                teamId: self::string($envelope['team_id'] ?? null),
                enterpriseId: self::string($envelope['enterprise_id'] ?? null),
                userId: self::string($envelope['user_id'] ?? null),
                conversationId: self::string($envelope['channel_id'] ?? null),
                isEnterpriseInstall: self::enterpriseInstall($envelope['is_enterprise_install'] ?? null),
                raw: $envelope,
            );
        }

        $team = self::array($envelope['team'] ?? null);
        $enterprise = self::array($envelope['enterprise'] ?? null);
        $user = self::array($envelope['user'] ?? null);
        $channel = self::array($envelope['channel'] ?? null);
        $enterpriseInstall = self::enterpriseInstall($envelope['is_enterprise_install'] ?? null);
        $view = self::array($envelope['view'] ?? null);
        $viewTeamId = self::string($view['app_installed_team_id'] ?? null);

        $conversationId = in_array($envelope['type'] ?? null, ['shortcut', 'view_submission', 'view_closed'], true)
            ? null
            : self::string($envelope['channel_id'] ?? $channel['id'] ?? null);

        return new self(
            teamId: $viewTeamId ?? self::string($team['id'] ?? null) ?? self::string($user['team_id'] ?? null),
            enterpriseId: self::string($enterprise['id'] ?? null) ?? self::string($team['enterprise_id'] ?? null),
            userId: self::string($user['id'] ?? null),
            conversationId: $conversationId,
            isEnterpriseInstall: $enterpriseInstall,
            raw: $envelope,
        );
    }

    public function query(): InstallationQuery
    {
        return new InstallationQuery(
            teamId: $this->isEnterpriseInstall ? null : $this->teamId,
            enterpriseId: $this->enterpriseId,
            conversationId: $this->conversationId,
            isEnterpriseInstall: $this->isEnterpriseInstall,
        );
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'teamId' => $this->teamId,
            'enterpriseId' => $this->enterpriseId,
            'userId' => $this->userId,
            'conversationId' => $this->conversationId,
            'isEnterpriseInstall' => $this->isEnterpriseInstall,
            'raw' => '[redacted]',
        ];
    }

    private static function array(mixed $value): array
    {
        return is_array($value) && !array_is_list($value) ? $value : [];
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function event(array $envelope): self
    {
        $authorizations = $envelope['authorizations'] ?? null;
        $authorization = is_array($authorizations) ? self::array($authorizations[0] ?? null) : [];
        $event = self::array($envelope['event'] ?? null);
        $assistantThread = in_array(
            $event['type'] ?? null,
            ['assistant_thread_started', 'assistant_thread_context_changed'],
            true,
        )
            ? self::array($event['assistant_thread'] ?? null)
            : [];
        $eventUser = $event['user'] ?? null;
        $eventUserId = is_string($eventUser)
            ? self::string($eventUser)
            : self::string(self::array($eventUser)['id'] ?? null);
        $eventChannel = $event['channel'] ?? null;
        $item = self::array($event['item'] ?? null);
        $conversationId =
            self::string($item['channel'] ?? null)
            ?? self::string($event['channel_id'] ?? null)
            ?? (
                is_string($eventChannel)
                    ? self::string($eventChannel)
                    : self::string(self::array($eventChannel)['id'] ?? null)
            );
        $creator = self::string(self::array($eventChannel)['creator'] ?? null);
        $subteam = self::array($event['subteam'] ?? null);
        $enterpriseInstall = $authorization === []
            ? self::enterpriseInstall($envelope['is_enterprise_install'] ?? null)
            : self::enterpriseInstall($authorization['is_enterprise_install'] ?? null);

        // The event authorization identifies the installation Slack selected;
        // its enterprise null intentionally does not fall back to envelope data.
        return new self(
            teamId: $authorization !== []
            && array_key_exists('team_id', $authorization)
            && $authorization['team_id'] !== null
                ? self::string($authorization['team_id'])
                : self::string($envelope['team_id'] ?? null),
            enterpriseId: $authorization !== []
                ? self::string($authorization['enterprise_id'] ?? null)
                : self::string($envelope['enterprise_id'] ?? null),
            userId: self::string($assistantThread['user_id'] ?? null)
            ?? $eventUserId
            ?? $creator
            ?? self::string($subteam['created_by'] ?? null),
            conversationId: self::string($assistantThread['channel_id'] ?? null) ?? $conversationId,
            isEnterpriseInstall: $enterpriseInstall,
            raw: $envelope,
        );
    }

    private static function enterpriseInstall(mixed $value): bool
    {
        return $value === true || $value === 'true';
    }
}
