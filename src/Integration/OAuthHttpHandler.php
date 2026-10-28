<?php

declare(strict_types=1);

namespace Thunder\Bolt\Integration;

use Tempest\Http\Cookie\Cookie;
use Tempest\Http\Cookie\SameSite;
use Tempest\Http\GenericResponse;
use Tempest\Http\Request;
use Tempest\Http\Status;
use Thunder\Bolt\OAuth\Installer;
use Thunder\Bolt\OAuth\InstallRequest;
use Thunder\Bolt\OAuth\InstallResponse;
use Thunder\Bolt\OAuth\InstallResult;
use Thunder\Bolt\OAuth\InstallStatus;

final readonly class OAuthHttpHandler
{
    private ?\Closure $respond;

    /** @param null|callable(InstallResult): InstallResponse $respond */
    public function __construct(
        private Installer $installer,
        ?callable $respond = null,
    ) {
        $this->respond = $respond === null ? null : \Closure::fromCallable($respond);
    }

    public function install(?InstallRequest $request = null): GenericResponse
    {
        try {
            $redirect = $this->installer->begin($request);
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

    public function callback(Request $request): GenericResponse
    {
        $state = $this->stringQuery($request, 'state');
        [$cookieBinding, $cookieName] = $this->stateCookie($request);
        try {
            $result = $this->installer->complete(
                state: $state,
                cookieBinding: $cookieBinding,
                code: $this->stringQuery($request, 'code'),
                error: $this->stringQuery($request, 'error'),
            );
        } catch (\Throwable) {
            return $this->response(Status::BAD_GATEWAY, 'OAuth installation could not be completed.', $cookieName);
        }

        try {
            $chosen = $this->respond === null ? $this->defaultResponse($result) : ($this->respond)($result);
            if (!$chosen instanceof InstallResponse) {
                throw new \UnexpectedValueException('OAuth responders must return an InstallResponse.');
            }
            $response = $this->response(Status::from($chosen->status), $chosen->body, $cookieName);
            if ($chosen->location !== null) {
                $response->addHeader('Location', $chosen->location);
            }
            return $response;
        } catch (\Throwable) {
            return $this->response(Status::BAD_GATEWAY, 'OAuth installation could not be completed.', $cookieName);
        }
    }

    private function defaultResponse(InstallResult $result): InstallResponse
    {
        return match ($result->status) {
            InstallStatus::Installed => InstallResponse::text('Thunder Bolt was installed.'),
            InstallStatus::Denied => InstallResponse::text('Slack authorization was denied.', 400),
            InstallStatus::InvalidState => InstallResponse::text('OAuth state could not be verified.', 400),
            InstallStatus::MissingCode => InstallResponse::text('OAuth authorization did not include a code.', 400),
            InstallStatus::Failed => InstallResponse::text('OAuth installation could not be completed.', 502),
        };
    }

    private function response(Status $status, string $body, string $cookieName): GenericResponse
    {
        return new GenericResponse($status, $body, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Set-Cookie' => (string) new Cookie(
                key: $cookieName,
                value: '',
                expiresAt: 1,
                maxAge: 0,
                path: '/',
                secure: true,
                httpOnly: true,
                sameSite: SameSite::LAX,
            ),
        ]);
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
     *
     * @return array{?string, string}
     */
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
