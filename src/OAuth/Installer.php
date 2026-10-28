<?php

declare(strict_types=1);

namespace Thunder\Bolt\OAuth;

use Psr\Clock\ClockInterface;
use Thunder\Bolt\OAuth\State\RequestStateStore;
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

    public function begin(?InstallRequest $request = null): InstallRedirect
    {
        $request ??= InstallRequest::make();
        if (!$this->states instanceof RequestStateStore && $request != InstallRequest::make()) {
            throw new \LogicException('Per-install options require a RequestStateStore adapter.');
        }
        $request = new InstallRequest(
            $request->scopes ?? $this->config->scopes,
            $request->userScopes ?? $this->config->userScopes,
            $request->team,
            $request->metadata,
        );
        $state = new State(
            value: $this->randomValue(),
            cookieBinding: $this->randomValue(),
            expiresAt: $this->now() + $this->config->stateTtlSeconds,
            request: $request,
        );
        $this->states->put($state);

        $query = [
            'client_id' => $this->config->clientId,
            'redirect_uri' => $this->config->redirectUri,
            'scope' => implode(',', $request->scopes ?? []),
            'state' => $state->value,
        ];
        if ($request->userScopes !== []) {
            $query['user_scope'] = implode(',', $request->userScopes ?? []);
        }
        if ($request->team !== null) {
            $query['team'] = $request->team;
        }

        return new InstallRedirect(
            authorizationUrl: 'https://slack.com/oauth/v2/authorize?'
                . http_build_query($query, '', '&', PHP_QUERY_RFC3986),
            cookieValue: $state->cookieBinding,
            expiresAt: $state->expiresAt,
            maxAge: $this->config->stateTtlSeconds,
            cookieName: self::cookieName($state->value),
        );
    }

    public function complete(?string $state, ?string $cookieBinding, ?string $code, ?string $error): InstallResult
    {
        // An unbound cancellation remains a safe display-only outcome. It must
        // never expose metadata or represent a validated installation request.
        if ($error === 'access_denied' && $state === null) {
            return InstallResult::denied();
        }
        if ($state === null || !$this->isStateValue($state)) {
            return InstallResult::invalidState();
        }
        $request = $this->states instanceof RequestStateStore
            ? $this->states->consumeRequest($state, $cookieBinding ?? '', $this->now())
            : (
                $this->states->consume($state, $cookieBinding ?? '', $this->now())
                    ? new InstallRequest($this->config->scopes, $this->config->userScopes)
                    : null
            );
        if ($request === null) {
            return InstallResult::invalidState();
        }
        if ($error !== null) {
            return InstallResult::denied($request);
        }
        if (!is_string($code) || $code === '') {
            return InstallResult::missingCode($request);
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

        return InstallResult::installed($installation, $request);
    }

    public static function cookieName(?string $state): string
    {
        return is_string($state) && preg_match('/\A[A-Za-z0-9_-]{43}\z/', $state) === 1
            ? self::STATE_COOKIE . '-' . hash('sha256', $state)
            : self::STATE_COOKIE;
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
