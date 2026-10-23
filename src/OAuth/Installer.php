<?php

declare(strict_types=1);

namespace Thunder\Bolt\OAuth;

use Psr\Clock\ClockInterface;
use Thunder\Bolt\OAuth\State\State;
use Thunder\Bolt\OAuth\State\StateStore;
use Thunder\Bolt\WebApi\Client;

final readonly class Installer
{
    public const string STATE_COOKIE = 'slack-app-oauth-state';

    public function __construct(
        private OAuthConfig $config,
        private Client $client,
        private InstallationStore $installations,
        private StateStore $states,
        private ClockInterface $clock,
    ) {}

    public function begin(): InstallRedirect
    {
        $state = new State(
            value: $this->randomValue(),
            cookieBinding: $this->randomValue(),
            expiresAt: $this->now() + $this->config->stateTtlSeconds,
        );
        $this->states->put($state);

        $query = [
            'client_id' => $this->config->clientId,
            'redirect_uri' => $this->config->redirectUri,
            'scope' => implode(',', $this->config->scopes),
            'state' => $state->value,
        ];
        if ($this->config->userScopes !== []) {
            $query['user_scope'] = implode(',', $this->config->userScopes);
        }

        return new InstallRedirect(
            authorizationUrl: 'https://slack.com/oauth/v2/authorize?'
                . http_build_query($query, '', '&', PHP_QUERY_RFC3986),
            cookieValue: $state->cookieBinding,
            expiresAt: $state->expiresAt,
            maxAge: $this->config->stateTtlSeconds,
        );
    }

    public function complete(?string $state, ?string $cookieBinding, ?string $code, ?string $error): InstallResult
    {
        // Slack's normal cancellation callback has no useful authorization code
        // or state to exchange. It is safe to end it immediately: no token is
        // requested, no installation is persisted, and the controller clears
        // the browser cookie.
        if ($error === 'access_denied') {
            return InstallResult::denied();
        }
        if (
            $state === null
            || !$this->isStateValue($state)
            || !$this->states->consume($state, $cookieBinding ?? '', $this->now())
        ) {
            return InstallResult::invalidState();
        }
        if ($error !== null) {
            return InstallResult::denied();
        }
        if (!is_string($code) || $code === '') {
            return InstallResult::missingCode();
        }

        $response = $this->client
            ->withToken('')
            ->oauth()
            ->v2()
            ->access([
                'client_id' => $this->config->clientId,
                'client_secret' => $this->config->clientSecret,
                'code' => $code,
                'redirect_uri' => $this->config->redirectUri,
            ]);
        $oauthResponse = $response->data;
        $botToken = $oauthResponse['access_token'] ?? null;
        if (is_string($botToken) && $botToken !== '') {
            $auth = $this->client->withToken($botToken)->auth()->test();
            if (is_string($auth->get('bot_id'))) {
                $oauthResponse['bot_id'] = $auth->get('bot_id');
            }
        }

        $installation = Installation::fromOAuthResponse($oauthResponse, $this->now());
        $this->installations->save($installation);

        return InstallResult::installed($installation);
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    private function randomValue(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function isStateValue(?string $value): bool
    {
        return is_string($value) && preg_match('/\A[A-Za-z0-9_-]{43}\z/', $value) === 1;
    }
}
