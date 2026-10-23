<?php

declare(strict_types=1);

namespace Thunder\Bolt\OAuth;

use Psr\Clock\ClockInterface;
use Thunder\Bolt\Authorization\AuthorizationException;
use Thunder\Bolt\WebApi\Client;

final readonly class TokenRotator
{
    public function __construct(
        private OAuthConfig $config,
        Client $client,
        private InstallationStore $store,
        private ?ClockInterface $clock = null,
        private int $refreshWindowSeconds = 7_200,
    ) {
        if ($refreshWindowSeconds < 0) {
            throw new \InvalidArgumentException('The token refresh window must not be negative.');
        }
        $this->client = $client->withToken('');
    }

    private Client $client;

    public function refreshBot(InstallationQuery $query): ?Installation
    {
        return $this->refreshCredential($query->forBot(), false);
    }

    public function refreshUser(InstallationQuery $query): ?Installation
    {
        if ($query->userId === null) {
            throw new \InvalidArgumentException('Refreshing a user token requires a user-scoped installation query.');
        }

        return $this->refreshCredential($query, true);
    }

    /**
     * Refreshes both independently scoped credentials. A successful bot refresh
     * is committed before a later user refresh begins, preserving a single-use
     * refresh token if the user operation fails.
     */
    public function refresh(InstallationQuery $query): ?Installation
    {
        $bot = $this->refreshBot($query);
        if ($query->userId === null) {
            return $bot;
        }

        return $this->refreshUser($query) ?? $bot;
    }

    /** Migrates a long-lived bot token through oauth.v2.exchange. */
    public function migrateBot(InstallationQuery $query): ?Installation
    {
        return $this->migrate($query->forBot(), false);
    }

    /** Migrates a long-lived user token through oauth.v2.exchange. */
    public function migrateUser(InstallationQuery $query): ?Installation
    {
        if ($query->userId === null) {
            throw new \InvalidArgumentException('Migrating a user token requires a user-scoped installation query.');
        }

        return $this->migrate($query, true);
    }

    private function refreshCredential(InstallationQuery $query, bool $user): ?Installation
    {
        $now = $this->now();

        return $this->store->update($query, function (?Installation $installation) use ($now, $user): ?Installation {
            if (
                $installation === null
                || !$this->needsRefresh($user ? $installation->userTokenExpiresAt : $installation->botTokenExpiresAt)
            ) {
                return $installation;
            }

            $refreshToken = $user ? $installation->userRefreshToken : $installation->botRefreshToken;
            if ($refreshToken === null) {
                $expiresAt = $user ? $installation->userTokenExpiresAt : $installation->botTokenExpiresAt;
                if ($expiresAt !== null && $expiresAt <= $now) {
                    throw new AuthorizationException(
                        'The Slack installation credential has expired and cannot be refreshed.',
                    );
                }

                return $installation;
            }
            $response = $this->client->call('oauth.v2.access', [
                'client_id' => $this->config->clientId,
                'client_secret' => $this->config->clientSecret,
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
            ])->data;

            return $user
                ? $installation->withUserOAuthResponse($response, $now)
                : $installation->withBotOAuthResponse($response, $now);
        });
    }

    private function migrate(InstallationQuery $query, bool $user): ?Installation
    {
        $now = $this->now();

        return $this->store->update($query, function (?Installation $installation) use ($now, $user): ?Installation {
            if ($installation === null) {
                return null;
            }
            $token = $user ? $installation->userToken : $installation->botToken;
            if ($token === null) {
                return $installation;
            }
            $response = $this->client->call('oauth.v2.exchange', [
                'client_id' => $this->config->clientId,
                'client_secret' => $this->config->clientSecret,
                'token' => $token,
            ])->data;

            return $user
                ? $installation->withUserOAuthResponse($response, $now)
                : $installation->withBotOAuthResponse($response, $now);
        });
    }

    private function needsRefresh(?int $expiresAt): bool
    {
        return $expiresAt !== null && $expiresAt <= ($this->now() + $this->refreshWindowSeconds);
    }

    private function now(): int
    {
        return ($this->clock ?? new SystemClock())->now()->getTimestamp();
    }
}
