<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\WebApi;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;
use Thunder\Bolt\WebApi\RateLimited;
use Thunder\Bolt\WebApi\RetryPolicy;
use Thunder\Bolt\WebApi\SlackApiException;

final class RetryPolicyTest extends TestCase
{
    public function test_rate_limits_respect_retry_after_and_preserve_body_and_scoped_token(): void
    {
        $http = new RecordingHttpClient([
            new Response(429, ['Retry-After' => '7']),
            new Response(200, [], '{"ok":true}'),
        ]);
        $waits = [];
        $client = new Client($http, 'original')->retrying(sleep: static function (float $delay) use (&$waits): void {
            $waits[] = $delay;
        })->withToken('installation');
        $client->call('chat.postMessage', ['channel' => 'C1', 'text' => 'Hello']);
        self::assertSame([7.0], $waits);
        self::assertCount(2, $http->requests);
        self::assertSame((string) $http->requests[0]->getBody(), (string) $http->requests[1]->getBody());
        self::assertSame('Bearer installation', $http->requests[1]->getHeaderLine('Authorization'));
    }

    public function test_wait_budget_does_not_shorten_slacks_retry_after(): void
    {
        $http = new RecordingHttpClient([new Response(429, ['Retry-After' => '120'])]);
        $client = new Client($http, 'token')->retrying(
            RetryPolicy::rateLimits(maxTotalWait: 2),
            static function (): void {
                self::fail('A delay exceeding the budget must not sleep.');
            },
        );
        $this->expectException(RateLimited::class);
        $client->call('auth.test');
    }

    public function test_retry_count_is_bounded_even_for_zero_delay(): void
    {
        $http = new RecordingHttpClient(array_fill(0, 4, new Response(429, ['Retry-After' => '0'])));
        try {
            new Client($http, 'token')
                ->retrying(RetryPolicy::rateLimits(maxRetries: 2))
                ->call('auth.test');
            self::fail('Expected rate limit.');
        } catch (RateLimited) {
            self::assertCount(3, $http->requests);
        }
    }

    public function test_rate_limit_only_policy_does_not_replay_a_server_failure(): void
    {
        $http = new RecordingHttpClient([new Response(503)]);
        $this->expectExceptionMessage('http_503');
        new Client($http, 'token')
            ->retrying()
            ->call('chat.postMessage');
    }

    public function test_explicit_transient_policy_uses_bounded_exponential_backoff(): void
    {
        $http = new RecordingHttpClient([new Response(500), new Response(503), new Response(200, [], '{"ok":true}')]);
        $waits = [];
        new Client($http, 'token')
            ->retrying(
                RetryPolicy::transientErrors(initialDelay: 0.5, maxDelay: 0.75, jitter: false),
                static function (float $seconds) use (&$waits): void {
                    $waits[] = $seconds;
                },
            )
            ->call('conversations.list');
        self::assertSame([0.5, 0.75], $waits);
    }

    public function test_platform_errors_and_invalid_json_are_not_replayed(): void
    {
        foreach (['{"ok":false,"error":"internal_error"}', 'not json'] as $body) {
            $http = new RecordingHttpClient([new Response(200, [], $body)]);
            try {
                new Client($http, 'token')
                    ->retrying(RetryPolicy::transientErrors())
                    ->call('auth.test');
                self::fail('Expected API error.');
            } catch (SlackApiException) {
                self::assertCount(1, $http->requests);
            }
        }
    }

    public function test_oauth_and_openid_token_exchanges_are_never_replayed(): void
    {
        foreach (['oauth.v2.access', 'oauth.v2.exchange', 'openid.connect.token'] as $method) {
            $http = new RecordingHttpClient([new Response(429, ['Retry-After' => '0'])]);
            try {
                new Client($http, '')
                    ->retrying(RetryPolicy::transientErrors())
                    ->call($method, ['token' => 'old-token']);
                self::fail('Expected rate limit.');
            } catch (RateLimited) {
                self::assertCount(1, $http->requests);
            }
        }
    }

    public function test_response_urls_remain_single_attempt(): void
    {
        $http = new RecordingHttpClient([new Response(429, ['Retry-After' => '0'])]);
        try {
            new Client($http, '')
                ->retrying()
                ->respond('https://hooks.slack.test/actions/secret', ['text' => 'Hello']);
            self::fail('Expected rate limit.');
        } catch (RateLimited) {
            self::assertCount(1, $http->requests);
        }
    }

    public function test_network_retry_requires_explicit_transient_policy(): void
    {
        $http = new class implements ClientInterface {
            public int $attempts = 0;

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                if (++$this->attempts === 1) {
                    throw new class($request) extends \RuntimeException implements NetworkExceptionInterface {
                        public function __construct(
                            private RequestInterface $request,
                        ) {
                            parent::__construct('offline');
                        }

                        public function getRequest(): RequestInterface
                        {
                            return $this->request;
                        }
                    };
                }
                return new Response(200, [], '{"ok":true}');
            }
        };
        new Client($http, 'token')
            ->retrying(RetryPolicy::transientErrors(initialDelay: 0), static function (): void {})
            ->call('auth.test');
        self::assertSame(2, $http->attempts);
    }

    public function test_invalid_and_excessive_delays_are_rejected(): void
    {
        foreach ([-1, INF, NAN, 86401] as $delay) {
            try {
                RetryPolicy::transientErrors(initialDelay: $delay);
                self::fail('Expected invalid delay.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_jitter_stays_within_backoff_bounds(): void
    {
        $policy = RetryPolicy::transientErrors(initialDelay: 2, maxDelay: 5);
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $delay = $policy->delay(new Response(503), min($attempt, 2));
            self::assertGreaterThanOrEqual(1, $delay);
            self::assertLessThanOrEqual(5, $delay);
        }
    }
}
