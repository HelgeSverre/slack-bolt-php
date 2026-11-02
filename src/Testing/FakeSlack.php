<?php

declare(strict_types=1);

namespace Thunder\Bolt\Testing;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** In-memory PSR-18 transport. It never delegates a request to the network. */
final class FakeSlack implements ClientInterface
{
    /** @var list<SlackCall> */
    private array $calls = [];
    /** @var array<string, list<ResponseInterface>> */
    private array $responses = [];

    /** @param array<string, string|list<string>> $headers */
    public function respondsWith(string $method, array $body, int $status = 200, array $headers = []): self
    {
        $this->responses[$method][] = new Response(
            $status,
            ['Content-Type' => 'application/json', ...$headers],
            json_encode($body, JSON_THROW_ON_ERROR),
        );
        return $this;
    }

    /** @return list<SlackCall> */
    public function calls(): array
    {
        return $this->calls;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $method = $request->getUri()->getHost() === 'slack.com' && str_starts_with($path, '/api/')
            ? substr($path, 5)
            : 'response_url';
        $arguments = [];
        if ($request->getMethod() === 'GET') {
            parse_str($request->getUri()->getQuery(), $arguments);
        } elseif (str_contains($request->getHeaderLine('Content-Type'), 'application/json')) {
            $decoded = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                throw new \UnexpectedValueException('A test Slack request must contain a JSON object.');
            }
            $arguments = $decoded;
        } else {
            parse_str((string) $request->getBody(), $arguments);
        }
        $this->calls[] = new SlackCall($method, $arguments);
        if (($this->responses[$method] ?? []) !== []) {
            return (
                array_shift($this->responses[$method]) ?? throw new \LogicException('A fake response queue was empty.')
            );
        }
        if (!in_array(
            $method,
            [
                'chat.postMessage',
                'chat.postEphemeral',
                'chat.update',
                'chat.delete',
                'chat.startStream',
                'chat.appendStream',
                'chat.stopStream',
                'views.open',
                'views.publish',
                'views.update',
                'views.push',
                'response_url',
            ],
            true,
        )) {
            throw new \LogicException("No fake response configured for Slack method {$method}.");
        }
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'ok' => true,
            'ts' => '1700000000.' . str_pad((string) count($this->calls), 6, '0', STR_PAD_LEFT),
            'channel' => $arguments['channel'] ?? 'C_TEST',
        ], JSON_THROW_ON_ERROR));
    }

    public function __debugInfo(): array
    {
        return ['calls' => count($this->calls), 'stubbedMethods' => array_keys($this->responses)];
    }
}
