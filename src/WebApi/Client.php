<?php

declare(strict_types=1);

namespace Thunder\Bolt\WebApi;

use GuzzleHttp\Psr7\Request;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Thunder\Bolt\Generated\WebApi\HasResources;

final readonly class Client
{
    use HasResources;

    public function workObjects(): \Thunder\Bolt\WorkObjects\Publisher
    {
        return new \Thunder\Bolt\WorkObjects\Publisher($this);
    }

    public function __construct(
        private ClientInterface $http,
        #[\SensitiveParameter]
        private string $token,
        private string $baseUrl = 'https://slack.com/api/',
        private ?RetryPolicy $retryPolicy = null,
        private ?\Closure $sleep = null,
        private ?ClientInterface $uploadHttp = null,
    ) {
        $parts = parse_url($baseUrl);
        if (
            !is_array($parts)
            || ($parts['scheme'] ?? '') !== 'https'
            || !isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new \InvalidArgumentException(
                'The Slack API base URL must use HTTPS without credentials, query, or fragment.',
            );
        }
    }

    public function withToken(#[\SensitiveParameter] string $token): self
    {
        return new self($this->http, $token, $this->baseUrl, $this->retryPolicy, $this->sleep, $this->uploadHttp);
    }

    #[\NoDiscard]
    public function retrying(?RetryPolicy $policy = null, ?callable $sleep = null): self
    {
        return new self(
            $this->http,
            $this->token,
            $this->baseUrl,
            $policy ?? RetryPolicy::rateLimits(),
            $sleep === null ? null : \Closure::fromCallable($sleep),
            $this->uploadHttp,
        );
    }

    public function upload(Uploads\Upload $upload): Uploads\UploadResult
    {
        return new Uploads\Uploader($this, $this->uploadHttp ?? $this->http)->upload($upload);
    }

    public function uploadMany(Uploads\Upload ...$uploads): Uploads\UploadResult
    {
        return new Uploads\Uploader($this, $this->uploadHttp ?? $this->http)->uploadMany(...$uploads);
    }

    /**
     * @template T of TypedResponse
     * @param class-string<T> $response
     * @return T
     */
    public function callTyped(string $method, array|RequestPayload $arguments, string $response): TypedResponse
    {
        return $response::fromResponse($this->call($method, $arguments));
    }

    public function call(string $method, array|RequestPayload $arguments = []): Response
    {
        if ($method === 'files.uploadV2') {
            throw new \InvalidArgumentException(
                'files.uploadV2 is a helper, not an API endpoint. Use upload(), uploadMany(), or files()->uploadV2() with Upload values.',
            );
        }
        if (!preg_match('/\A[a-z][a-zA-Z0-9]*(?:\.[a-zA-Z][a-zA-Z0-9]*)+\z/', $method)) {
            throw new \InvalidArgumentException('Use a Slack method name such as chat.postMessage, not a URL.');
        }

        $data = $arguments instanceof RequestPayload ? $arguments->toArray() : $arguments;
        // oauth.v2.exchange consumes the old access token as a form field,
        // rather than using the normal per-call bearer-token override.
        $exchange = $method === 'oauth.v2.exchange';
        $token = $exchange ? '' : $data['token'] ?? $this->token;
        if (!$exchange) {
            unset($data['token']);
        } elseif (!is_string($data['token'] ?? null) || $data['token'] === '') {
            throw new \InvalidArgumentException('OAuth token exchange requires a token form field.');
        }
        if (!is_string($token)) {
            throw new \InvalidArgumentException('A Slack token must be a string.');
        }
        $form = in_array(
            $method,
            ['oauth.access', 'oauth.v2.access', 'oauth.v2.exchange', 'openid.connect.token'],
            true,
        );
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => $form ? 'application/x-www-form-urlencoded' : 'application/json; charset=utf-8',
        ];
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        $multipart = null;
        foreach ($data as $value) {
            if ($value instanceof MultipartFile) {
                $multipart = new MultipartForm($data);
                break;
            }
        }
        if ($multipart !== null) {
            $headers['Content-Type'] = 'multipart/form-data; boundary=' . $multipart->boundary;
            $body = '';
        } else {
            $body = $form
                ? http_build_query($data, '', '&', PHP_QUERY_RFC3986)
                : json_encode((object) $data, JSON_THROW_ON_ERROR);
        }
        $request = new Request('POST', rtrim($this->baseUrl, '/') . '/' . $method, $headers, $body);
        $allowRetries = match ($method) {
            'openid.connect.token' => false,
            default => !str_starts_with($method, 'oauth.'),
        };
        $response = $this->send($request, $allowRetries && ($multipart === null || $multipart->replayable), $multipart);

        $this->assertSuccessfulStatus($response);
        try {
            $result = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new SlackApiException('invalid_json', previous: $exception);
        }
        if (!is_array($result) || !isset($result['ok']) || !is_bool($result['ok'])) {
            throw new SlackApiException('invalid_response');
        }
        if (!$result['ok']) {
            throw new SlackApiException(
                is_string($result['error'] ?? null) ? $result['error'] : 'unknown_error',
                $result,
            );
        }

        return new Response($result);
    }

    private function send(
        RequestInterface $request,
        bool $allowRetries,
        ?MultipartForm $multipart = null,
    ): ResponseInterface {
        $waited = 0.0;
        $retry = 0;
        while (true) {
            $response = null;
            $failure = null;
            try {
                // Recreate the body for each attempt; PSR-18 transports may consume it.
                $body = $multipart !== null
                    ? $multipart->stream()
                    : \GuzzleHttp\Psr7\Utils::streamFor((string) $request->getBody());
                $attempt = $request->withBody($body);
                $response = $this->http->sendRequest($attempt);
            } catch (NetworkExceptionInterface $exception) {
                $failure = $exception;
            }
            $delay = $allowRetries ? $this->retryPolicy?->delay($response, $retry) : null;
            if ($delay === null || ($waited + $delay) > ($this->retryPolicy?->maxTotalWait ?? 0)) {
                if ($failure !== null) {
                    throw $failure;
                }
                return $response ?? throw new \LogicException('HTTP transport returned no response.');
            }
            // Close failed responses before waiting, releasing connections promptly.
            $response?->getBody()->close();
            if ($this->sleep !== null) {
                ($this->sleep)($delay);
            } elseif ($delay > 0) {
                usleep(max(0, (int) ceil($delay * 1_000_000)));
            }
            $waited += $delay;
            $retry++;
        }
    }

    /** @param array<string,mixed> $arguments */
    public function respond(#[\SensitiveParameter] string $url, array $arguments): Response
    {
        $parts = parse_url($url);
        if (
            !is_array($parts)
            || ($parts['scheme'] ?? '') !== 'https'
            || !isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
        ) {
            throw new \InvalidArgumentException(
                'A Slack response URL must use HTTPS without credentials or a fragment.',
            );
        }
        $request = new Request(
            'POST',
            $url,
            ['Content-Type' => 'application/json; charset=utf-8'],
            json_encode((object) $arguments, JSON_THROW_ON_ERROR),
        );
        $response = $this->http->sendRequest($request);
        $this->assertSuccessfulStatus($response);
        $body = trim((string) $response->getBody());
        if ($body === 'ok') {
            return new Response(['ok' => true]);
        }
        try {
            $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new SlackApiException('invalid_response_url_response', previous: $exception);
        }
        if (!is_array($data) || ($data['ok'] ?? null) !== true) {
            throw new SlackApiException(
                is_array($data) && is_string($data['error'] ?? null) ? $data['error'] : 'invalid_response_url_response',
            );
        }

        return new Response($data);
    }

    private function assertSuccessfulStatus(ResponseInterface $response): void
    {
        if ($response->getStatusCode() === 429) {
            $retryAfter = filter_var($response->getHeaderLine('Retry-After'), FILTER_VALIDATE_INT);
            throw new RateLimited(is_int($retryAfter) && $retryAfter >= 0 ? $retryAfter : 1);
        }
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new SlackApiException('http_' . $response->getStatusCode());
        }
    }

    /** @param array<string, mixed> $arguments
     * @return \Generator<int, Response>
     */
    public function paginate(string $method, array $arguments = []): \Generator
    {
        $seen = [];
        do {
            $response = $this->call($method, $arguments);
            yield $response;
            $metadata = $response->data['response_metadata'] ?? [];
            $cursor = is_array($metadata) && is_string($metadata['next_cursor'] ?? null)
                ? trim($metadata['next_cursor'])
                : '';
            if ($cursor === '') {
                return;
            }
            if (isset($seen[$cursor])) {
                throw new SlackApiException('repeated_pagination_cursor');
            }
            $seen[$cursor] = true;
            $arguments['cursor'] = $cursor;
        } while (true);
    }
}
