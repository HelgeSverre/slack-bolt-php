<?php

declare(strict_types=1);

namespace Thunder\Bolt\Integration;

use Tempest\Http\GenericResponse;
use Tempest\Http\Request;
use Tempest\Http\Status;
use Tempest\Router\Post;
use Tempest\Router\PreventCrossSiteRequestsMiddleware;
use Tempest\Router\SetCookieHeadersMiddleware;
use Thunder\Bolt\Http\Receiver;

final readonly class HttpController
{
    public function __construct(
        private Receiver $receiver,
    ) {}

    // Slack sends signed server-to-server POSTs, which intentionally do not
    // carry browser Fetch Metadata headers. Signature verification happens in
    // Receiver before the payload is decoded or dispatched.
    #[Post('/slack/events', without: [
        PreventCrossSiteRequestsMiddleware::class,
        SetCookieHeadersMiddleware::class,
    ])]
    public function events(Request $request): GenericResponse
    {
        /** @var array<string, string|list<string>> $headers */
        $headers = [];
        foreach ($request->headers->toArray() as $name => $value) {
            if (!is_string($name)) {
                continue;
            }

            if (is_string($value)) {
                $headers[$name] = $value;

                continue;
            }

            if (is_array($value) && array_is_list($value) && array_all($value, 'is_string')) {
                $headers[$name] = $value;
            }
        }

        $response = $this->receiver->handle(
            rawBody: $request->raw ?? '',
            headers: $headers,
            contentType: $request->headers->get('content-type', 'application/json') ?? 'application/json',
        );

        return new GenericResponse(
            status: Status::fromCode($response->status),
            body: $response->body,
            headers: $response->headers,
        );
    }
}
