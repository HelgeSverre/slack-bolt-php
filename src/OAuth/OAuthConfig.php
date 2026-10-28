<?php

declare(strict_types=1);

namespace Thunder\Bolt\OAuth;

final readonly class OAuthConfig
{
    private const REDACTED = '[redacted]';

    /**
     * @param  list<string>  $scopes
     * @param  list<string>  $userScopes
     */
    public function __construct(
        public string $clientId,
        #[\SensitiveParameter]
        public string $clientSecret,
        public string $redirectUri,
        public array $scopes = [],
        public array $userScopes = [],
        public int $stateTtlSeconds = 600,
    ) {
        if ($clientId === '' || $clientSecret === '' || $stateTtlSeconds < 1) {
            throw new \InvalidArgumentException(
                'OAuth requires a client ID, client secret, and positive state lifetime.',
            );
        }
        $parts = parse_url($redirectUri);
        if (
            !is_array($parts)
            || ($parts['scheme'] ?? '') !== 'https'
            || ($parts['host'] ?? '') === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || isset($parts['query'])
        ) {
            throw new \InvalidArgumentException(
                'The OAuth redirect URI must use HTTPS without credentials, query, or fragment.',
            );
        }
        self::validateScopes($scopes);
        self::validateScopes($userScopes);
    }

    /** @internal */
    public static function validateScopes(array $scopes): void
    {
        if (!array_is_list($scopes)) {
            throw new \InvalidArgumentException('OAuth scopes must be lists.');
        }
        foreach ($scopes as $scope) {
            if (!is_string($scope) || !preg_match('/\A[a-zA-Z0-9:_-]+\z/', $scope)) {
                throw new \InvalidArgumentException('OAuth scopes must be nonempty Slack scope names.');
            }
        }
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'clientId' => $this->clientId,
            'clientSecret' => self::REDACTED,
            'redirectUri' => $this->redirectUri,
            'scopes' => $this->scopes,
            'userScopes' => $this->userScopes,
        ];
    }
}
