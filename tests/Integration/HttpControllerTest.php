<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tempest\Container\GenericContainer;
use Tempest\Http\GenericRequest;
use Tempest\Http\GenericResponse;
use Tempest\Http\HttpRequestFailed;
use Tempest\Http\Method;
use Tempest\Http\Request;
use Tempest\Http\Response;
use Tempest\Http\Status;
use Tempest\Router\HttpMiddlewareCallable;
use Thunder\Bolt\App;
use Thunder\Bolt\BoltConfig;
use Thunder\Bolt\Http\Receiver;
use Thunder\Bolt\Integration\HttpController;
use Thunder\Bolt\Integration\SlackHttpFailurePassthroughMiddleware;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;

final class HttpControllerTest extends TestCase
{
    public function test_tempest_bridge_preserves_signed_raw_body_and_plaintext_response(): void
    {
        $config = new BoltConfig('', 'test-secret');
        $controller = new HttpController(
            new Receiver(new App(new GenericContainer(), new Client(new RecordingHttpClient(), ''), $config), $config),
        );
        $raw = '{"type":"url_verification", "challenge":"verified"}';
        $timestamp = (string) time();
        $request = new GenericRequest(
            Method::POST,
            'https://example.test/slack/events',
            headers: [
                'Content-Type' => 'application/json',
                'X-Slack-Request-Timestamp' => $timestamp,
                'X-Slack-Signature' => 'v0=' . hash_hmac('sha256', "v0:{$timestamp}:{$raw}", 'test-secret'),
            ],
            raw: $raw,
        );
        $response = $controller->events($request);
        self::assertSame(200, $response->status->value);
        self::assertSame('verified', $response->body);
        self::assertStringContainsString('text/plain', $response->headers['Content-Type']->values[0]);
    }

    public function test_slack_route_failure_is_not_replaced_by_a_development_error_page(): void
    {
        $response = new GenericResponse(Status::fromCode(401), 'Invalid Slack signature.');
        $result = (new SlackHttpFailurePassthroughMiddleware())(
            new GenericRequest(Method::POST, 'https://example.test/slack/events'),
            $this->next($response),
        );
        self::assertSame($response, $result);
    }

    public function test_custom_routes_keep_deliberate_error_status_body_and_headers(): void
    {
        foreach ([400, 401, 404, 410, 422, 503] as $code) {
            $response = new GenericResponse(Status::fromCode($code), 'Deliberate application response', [
                'Cache-Control' => 'no-store',
            ]);
            self::assertSame($response, (new SlackHttpFailurePassthroughMiddleware())(
                new GenericRequest(Method::GET, 'https://example.test/connect/expired'),
                $this->next($response),
            ));
        }
    }

    public function test_thrown_exceptions_still_reach_tempest_error_handling(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unhandled application exception');
        (new SlackHttpFailurePassthroughMiddleware())(
            new GenericRequest(Method::GET, 'https://example.test/another-route'),
            new HttpMiddlewareCallable(static function (): never {
                throw new \RuntimeException('Unhandled application exception');
            }),
        );
    }

    private function next(Response $response): HttpMiddlewareCallable
    {
        return new HttpMiddlewareCallable(static fn(Request $request): Response => $response);
    }
}
