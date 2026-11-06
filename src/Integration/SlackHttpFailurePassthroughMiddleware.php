<?php

declare(strict_types=1);

namespace Thunder\Bolt\Integration;

use Tempest\Http\Request;
use Tempest\Http\Response;
use Tempest\Http\Responses\NotFound;
use Tempest\Router\Exceptions\RouteBindingFailed;
use Tempest\Router\HttpMiddleware;
use Tempest\Router\HttpMiddlewareCallable;
use Tempest\Support\Priority;

/**
 * Preserves deliberate HTTP responses from Slack and application routes alike.
 * Thrown exceptions still use Tempest's exception handlers and renderers.
 * The existing class name is retained for applications configuring middleware.
 */
#[Priority(Priority::FRAMEWORK - 30)]
final readonly class SlackHttpFailurePassthroughMiddleware implements HttpMiddleware
{
    public function __invoke(Request $request, HttpMiddlewareCallable $next): Response
    {
        try {
            return $next($request);
        } catch (RouteBindingFailed) {
            return new NotFound();
        }
    }
}
