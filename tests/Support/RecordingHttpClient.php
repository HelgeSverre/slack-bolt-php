<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Support;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class RecordingHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @param list<ResponseInterface> $responses */
    public function __construct(
        private array $responses = [],
    ) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        return (
            array_shift($this->responses) ?? new Response(
                200,
                ['Content-Type' => 'application/json'],
                '{"ok":true,"ts":"1700000000.000002"}',
            )
        );
    }

    /** @return array<string, mixed> */
    public function sentPayload(int $index = 0): array
    {
        return json_decode((string) $this->requests[$index]->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }
}
