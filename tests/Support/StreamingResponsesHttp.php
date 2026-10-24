<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Support;

use App\Demo\Ai\StreamingHttpClient;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class StreamingResponsesHttp implements StreamingHttpClient
{
    public array $requests = [];
    public bool $delivering = false;

    public function __construct(
        private array $rounds,
    ) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        throw new \LogicException('The streaming path must not buffer a PSR-18 response.');
    }

    public function sendStreamingRequest(RequestInterface $request, callable $onChunk): void
    {
        $this->requests[] = $request;
        $events = array_shift($this->rounds) ?? [];
        $this->delivering = true;
        foreach ($events as $event) {
            $wire =
                ': keepalive'
                . "\r\n"
                . 'data: '
                . json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
                . "\r\n\r\n";
            foreach (str_split($wire, 3) as $fragment) {
                $onChunk($fragment);
            }
        }
        $this->delivering = false;
    }
}
