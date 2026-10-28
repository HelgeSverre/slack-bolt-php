<?php

declare(strict_types=1);

namespace Thunder\Bolt\Integration;

use Tempest\Container\Container;
use Tempest\Http\Cookie\Cookie;
use Tempest\Http\Cookie\SameSite;
use Tempest\Http\GenericResponse;
use Tempest\Http\Request;
use Tempest\Http\Status;
use Tempest\Router\Get;
use Tempest\Router\Stateless;
use Thunder\Bolt\App;
use Thunder\Bolt\BoltConfig;
use Thunder\Bolt\OAuth\Installer;
use Thunder\Bolt\OAuth\InstallStatus;

#[Stateless]
final readonly class OAuthController
{
    public function __construct(
        private BoltConfig $config,
        private Container $container,
    ) {}

    #[Get('/slack/install')]
    public function install(): GenericResponse
    {
        $installer = $this->installer();
        if ($installer === null) {
            return $this->unavailable();
        }

        try {
            $redirect = $installer->begin();
        } catch (\Throwable) {
            return new GenericResponse(Status::INTERNAL_SERVER_ERROR, 'OAuth installation could not be started.');
        }

        return new GenericResponse(Status::FOUND, headers: [
            'Location' => $redirect->authorizationUrl,
            'Set-Cookie' => (string) new Cookie(
                key: $redirect->cookieName,
                value: $redirect->cookieValue,
                expiresAt: $redirect->expiresAt,
                maxAge: $redirect->maxAge,
                path: '/',
                secure: true,
                httpOnly: true,
                sameSite: SameSite::LAX,
            ),
        ]);
    }

    #[Get('/slack/oauth_redirect')]
    public function callback(Request $request): GenericResponse
    {
        $installer = $this->installer();
        if ($installer === null) {
            return $this->unavailable();
        }

        $state = $this->stringQuery($request, 'state');
        [$cookieBinding, $cookieName] = $this->stateCookie($request);
        try {
            $result = $installer->complete(
                state: $state,
                cookieBinding: $cookieBinding,
                code: $this->stringQuery($request, 'code'),
                error: $this->stringQuery($request, 'error'),
            );
        } catch (\Throwable) {
            return $this->response(Status::BAD_GATEWAY, 'OAuth installation could not be completed.', $cookieName);
        }

        return match ($result->status) {
            InstallStatus::Installed => $this->response(Status::OK, 'Thunder Bolt was installed.', $cookieName),
            InstallStatus::Denied => $this->response(
                Status::BAD_REQUEST,
                'Slack authorization was denied.',
                $cookieName,
            ),
            InstallStatus::InvalidState => $this->response(
                Status::BAD_REQUEST,
                'OAuth state could not be verified.',
                $cookieName,
            ),
            InstallStatus::MissingCode => $this->response(
                Status::BAD_REQUEST,
                'OAuth authorization did not include a code.',
                $cookieName,
            ),
        };
    }

    private function installer(): ?Installer
    {
        if ($this->config->oauth === null) {
            return null;
        }
        if ($this->container->has(Installer::class)) {
            return $this->container->get(Installer::class);
        }

        $this->container->get(App::class);

        return $this->container->get(Installer::class);
    }

    private function unavailable(): GenericResponse
    {
        return new GenericResponse(Status::SERVICE_UNAVAILABLE, 'OAuth installation is not configured.');
    }

    private function response(Status $status, string $body, string $cookieName): GenericResponse
    {
        return new GenericResponse($status, $body, ['Set-Cookie' => (string) new Cookie(
            key: $cookieName,
            value: '',
            expiresAt: 1,
            maxAge: 0,
            path: '/',
            secure: true,
            httpOnly: true,
            sameSite: SameSite::LAX,
        )]);
    }

    private function stringQuery(Request $request, string $key): ?string
    {
        $value = $request->get($key);

        return is_string($value) ? $value : null;
    }

    /**
     * OAuth state is deliberately an opaque plaintext cookie. These routes are
     * stateless, so reading its raw request header avoids Tempest's encrypted
     * cookie layer (and its unrelated SIGNING_KEY requirement).
     */
    /** @return array{?string, string} */
    private function stateCookie(Request $request): array
    {
        $expected = Installer::cookieName($this->stringQuery($request, 'state'));
        $cookieHeader = $request->headers->get('cookie');
        if ($cookieHeader === null) {
            return [null, $expected];
        }

        $values = [];
        foreach (explode(';', $cookieHeader) as $cookie) {
            [$key, $value] = array_pad(explode('=', trim($cookie), 2), 2, null);
            if ($key !== $expected && $key !== Installer::STATE_COOKIE || $value === null) {
                continue;
            }

            $values[$key][] = rawurldecode($value);
        }
        // Never fall back to the legacy cookie when the flow cookie is present
        // but malformed or ambiguous. Legacy pending installs used one fixed name.
        $candidates = $values[$expected] ?? $values[Installer::STATE_COOKIE] ?? [];
        $selectedName = isset($values[$expected])
            ? $expected
            : (isset($values[Installer::STATE_COOKIE]) ? Installer::STATE_COOKIE : $expected);
        $binding =
            count($candidates) === 1 && preg_match('/\A[A-Za-z0-9_-]{43}\z/', $candidates[0]) === 1
                ? $candidates[0]
                : null;
        return [$binding, $selectedName];
    }
}
