<?php

declare(strict_types=1);

namespace Thunder\Bolt\Integration;

use Tempest\Http\HttpRequestFailed;
use Tempest\Http\Request;
use Tempest\Http\Response;
use Tempest\Router\HttpMiddleware;
use Tempest\Router\HttpMiddlewareCallable;
use Tempest\Support\Priority;

/**
 * Lets Slack receive deliberate non-success responses from Receiver unchanged.
 *
 * Tempest normally converts every 4xx/5xx route response into an exception so
 * local development can render its diagnostic page. Slack needs the original
 * plaintext 401/400/503 response to evaluate a delivery correctly.
 */
#[Priority(Priority::FRAMEWORK - 30)]
final readonly class SlackHttpFailurePassthroughMiddleware implements HttpMiddleware
{
    public function __invoke(Request $request, HttpMiddlewareCallable $next): Response
    {
        $response = $next($request);

        if (
            in_array($request->path, ['/slack/events', '/slack/install', '/slack/oauth_redirect'], true)
            || !$response->status->isClientError() && !$response->status->isServerError()
        ) {
            return $response;
        }

        throw new HttpRequestFailed(status: $response->status, cause: $response, request: $request);
    }
}
