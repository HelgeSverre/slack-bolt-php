<?php

declare(strict_types=1);

namespace App\Demo;

use Amp\Cancellation;
use Amp\CompositeCancellation;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\TimeoutCancellation;
use App\Demo\Ai\StreamingHttpClient;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** Lets the demo's blocking PSR-18 interface yield to other worker jobs. */
final readonly class OpenAiHttp implements StreamingHttpClient
{
    private HttpClient $http;

    public function __construct(
        private ?Cancellation $stop = null,
        ?HttpClient $http = null,
    ) {
        $this->http = $http ?? HttpClientBuilder::buildDefault();
    }

    public function withCancellation(Cancellation $stop): self
    {
        return new self($stop, $this->http);
    }

    public function sendRequest(#[\SensitiveParameter] RequestInterface $request): ResponseInterface
    {
        [$response, $cancellation] = $this->request($request);
        try {
            return new Response(
                $response->getStatus(),
                $response->getHeaders(),
                $response->getBody()->buffer($cancellation, 4_194_304),
            );
        } catch (\Throwable) {
            throw new DemoHttpException('The OpenAI request could not complete.');
        }
    }

    public function sendStreamingRequest(#[\SensitiveParameter] RequestInterface $request, callable $onChunk): void
    {
        [$response, $cancellation] = $this->request($request);
        if ($response->getStatus() < 200 || $response->getStatus() >= 300) {
            $response->getBody()->close();
            throw new DemoHttpException('The OpenAI request was rejected.');
        }
        try {
            while (($chunk = $response->getBody()->read($cancellation)) !== null) {
                $onChunk($chunk);
            }
        } catch (\Throwable) {
            throw new DemoHttpException('The OpenAI stream could not complete.');
        } finally {
            $response->getBody()->close();
        }
    }

    /** @return array{\Amp\Http\Client\Response, Cancellation} */
    private function request(#[\SensitiveParameter] RequestInterface $request): array
    {
        if ($request->getMethod() !== 'POST' || (string) $request->getUri() !== 'https://api.openai.com/v1/responses') {
            throw new DemoHttpException('The AI demo only calls the OpenAI Responses endpoint.');
        }
        $native = new Request((string) $request->getUri(), 'POST');
        foreach ($request->getHeaders() as $name => $values) {
            if (is_string($name) && $name !== '') {
                $native->setHeader($name, $values);
            }
        }
        $native->setBody((string) $request->getBody());
        $native->setTransferTimeout(90);
        $native->setInactivityTimeout(45);
        $native->setBodySizeLimit(4_194_304);
        $timeout = new TimeoutCancellation(90);
        $cancellation = $this->stop === null ? $timeout : new CompositeCancellation($timeout, $this->stop);
        try {
            $response = $this->http->request($native, $cancellation);
            return [$response, $cancellation];
        } catch (\Throwable) {
            throw new DemoHttpException('The OpenAI request could not complete.');
        }
    }
}

final class DemoHttpException extends \RuntimeException implements ClientExceptionInterface {}
