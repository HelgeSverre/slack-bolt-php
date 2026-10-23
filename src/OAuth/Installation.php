<?php

declare(strict_types=1);

namespace Thunder\Bolt\OAuth;

final readonly class Installation
{
    /**
     * @param  list<string>  $botScopes
     * @param  list<string>  $userScopes
     */
    public function __construct(
        public ?string $teamId,
        public ?string $enterpriseId,
        public bool $isEnterpriseInstall,
        #[\SensitiveParameter]
        public ?string $botToken,
        #[\SensitiveParameter]
        public ?string $botRefreshToken,
        public ?int $botTokenExpiresAt,
        public array $botScopes,
        public ?string $botId,
        public ?string $botUserId,
        public ?string $userId,
        #[\SensitiveParameter]
        public ?string $userToken,
        #[\SensitiveParameter]
        public ?string $userRefreshToken,
        public ?int $userTokenExpiresAt,
        public array $userScopes,
        public int $installedAt,
        public array $raw,
    ) {
        if (
            $isEnterpriseInstall && ($enterpriseId === null || $enterpriseId === '' || $teamId !== null)
            || !$isEnterpriseInstall && ($teamId === null || $teamId === '')
        ) {
            throw new \InvalidArgumentException('An installation must be scoped to one Slack workspace or enterprise.');
        }
    }

    public static function fromOAuthResponse(array $response, int $now): self
    {
        $team = self::array($response['team'] ?? null);
        $enterprise = self::array($response['enterprise'] ?? null);
        $authedUser = self::array($response['authed_user'] ?? null);
        $enterpriseInstall = ($response['is_enterprise_install'] ?? false) === true;

        return new self(
            teamId: $enterpriseInstall ? null : self::string($team['id'] ?? $response['team_id'] ?? null),
            enterpriseId: self::string($enterprise['id'] ?? $response['enterprise_id'] ?? null),
            isEnterpriseInstall: $enterpriseInstall,
            botToken: self::string($response['access_token'] ?? null),
            botRefreshToken: self::string($response['refresh_token'] ?? null),
            botTokenExpiresAt: self::expiry($response['expires_in'] ?? null, $now),
            botScopes: self::scopes($response['scope'] ?? null),
            botId: self::string($response['bot_id'] ?? null),
            botUserId: self::string($response['bot_user_id'] ?? null),
            userId: self::string($authedUser['id'] ?? null),
            userToken: self::string($authedUser['access_token'] ?? null),
            userRefreshToken: self::string($authedUser['refresh_token'] ?? null),
            userTokenExpiresAt: self::expiry($authedUser['expires_in'] ?? null, $now),
            userScopes: self::scopes($authedUser['scope'] ?? null),
            installedAt: $now,
            raw: $response,
        );
    }

    public static function fromStoredArray(array $data): self
    {
        return new self(
            teamId: self::string($data['teamId'] ?? null),
            enterpriseId: self::string($data['enterpriseId'] ?? null),
            isEnterpriseInstall: ($data['isEnterpriseInstall'] ?? false) === true,
            botToken: self::string($data['botToken'] ?? null),
            botRefreshToken: self::string($data['botRefreshToken'] ?? null),
            botTokenExpiresAt: self::integer($data['botTokenExpiresAt'] ?? null),
            botScopes: self::stringList($data['botScopes'] ?? []),
            botId: self::string($data['botId'] ?? null),
            botUserId: self::string($data['botUserId'] ?? null),
            userId: self::string($data['userId'] ?? null),
            userToken: self::string($data['userToken'] ?? null),
            userRefreshToken: self::string($data['userRefreshToken'] ?? null),
            userTokenExpiresAt: self::integer($data['userTokenExpiresAt'] ?? null),
            userScopes: self::stringList($data['userScopes'] ?? []),
            installedAt: self::integer($data['installedAt'] ?? null) ?? 0,
            raw: self::array($data['raw'] ?? null),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public function withBotOAuthResponse(array $response, int $now): self
    {
        $token = self::string($response['access_token'] ?? null);
        $refreshToken = self::string($response['refresh_token'] ?? null);
        if ($token === null || $refreshToken === null) {
            throw new \InvalidArgumentException('A token refresh response requires access_token and refresh_token.');
        }

        return new self(
            $this->teamId,
            $this->enterpriseId,
            $this->isEnterpriseInstall,
            $token,
            $refreshToken,
            self::requiredExpiry($response['expires_in'] ?? null, $now),
            self::scopes($response['scope'] ?? null) ?: $this->botScopes,
            self::string($response['bot_id'] ?? null) ?? $this->botId,
            self::string($response['bot_user_id'] ?? null) ?? $this->botUserId,
            $this->userId,
            $this->userToken,
            $this->userRefreshToken,
            $this->userTokenExpiresAt,
            $this->userScopes,
            $this->installedAt,
            [...$this->raw, 'bot_refresh' => $response],
        );
    }

    public function withUserOAuthResponse(array $response, int $now): self
    {
        $user = self::array($response['authed_user'] ?? null);
        $token = self::string($user['access_token'] ?? $response['access_token'] ?? null);
        $refreshToken = self::string($user['refresh_token'] ?? $response['refresh_token'] ?? null);
        if ($token === null || $refreshToken === null) {
            throw new \InvalidArgumentException(
                'A user token refresh response requires access_token and refresh_token.',
            );
        }

        return new self(
            $this->teamId,
            $this->enterpriseId,
            $this->isEnterpriseInstall,
            $this->botToken,
            $this->botRefreshToken,
            $this->botTokenExpiresAt,
            $this->botScopes,
            $this->botId,
            $this->botUserId,
            self::string($user['id'] ?? null) ?? $this->userId,
            $token,
            $refreshToken,
            self::requiredExpiry($user['expires_in'] ?? $response['expires_in'] ?? null, $now),
            self::scopes($user['scope'] ?? $response['scope'] ?? null) ?: $this->userScopes,
            $this->installedAt,
            [...$this->raw, 'user_refresh' => $response],
        );
    }

    public function query(): InstallationQuery
    {
        return new InstallationQuery(
            $this->teamId,
            $this->enterpriseId,
            isEnterpriseInstall: $this->isEnterpriseInstall,
        );
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'teamId' => $this->teamId,
            'enterpriseId' => $this->enterpriseId,
            'isEnterpriseInstall' => $this->isEnterpriseInstall,
            'botToken' => self::redacted($this->botToken),
            'botRefreshToken' => self::redacted($this->botRefreshToken),
            'botTokenExpiresAt' => $this->botTokenExpiresAt,
            'botScopes' => $this->botScopes,
            'botId' => $this->botId,
            'botUserId' => $this->botUserId,
            'userId' => $this->userId,
            'userToken' => self::redacted($this->userToken),
            'userRefreshToken' => self::redacted($this->userRefreshToken),
            'userTokenExpiresAt' => $this->userTokenExpiresAt,
            'userScopes' => $this->userScopes,
            'installedAt' => $this->installedAt,
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

    private static function integer(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    private static function expiry(mixed $seconds, int $now): ?int
    {
        if (is_int($seconds) || is_string($seconds) && ctype_digit($seconds)) {
            return $now + (int) $seconds;
        }

        return null;
    }

    private static function requiredExpiry(mixed $seconds, int $now): int
    {
        $expiry = self::expiry($seconds, $now);
        if ($expiry === null || $expiry <= $now) {
            throw new \InvalidArgumentException('A token refresh response requires a positive expires_in value.');
        }

        return $expiry;
    }

    /** @return list<string> */
    private static function scopes(mixed $scopes): array
    {
        if (is_string($scopes)) {
            $scopes = explode(',', $scopes);
        }

        return self::stringList($scopes);
    }

    /** @return list<string> */
    private static function stringList(mixed $values): array
    {
        if (!is_array($values) || !array_is_list($values)) {
            return [];
        }

        $strings = [];
        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                $strings[] = $value;
            }
        }
        return $strings;
    }

    private static function redacted(#[\SensitiveParameter] ?string $token): ?string
    {
        return $token === null ? null : '[redacted]';
    }
}
