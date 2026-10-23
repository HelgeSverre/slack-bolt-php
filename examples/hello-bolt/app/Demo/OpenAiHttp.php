<?php

declare(strict_types=1);

namespace App\Demo;

use Amp\Cancellation;
use Amp\CompositeCancellation;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\TimeoutCancellation;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** Lets the demo's blocking PSR-18 interface yield to other worker jobs. */
final readonly class OpenAiHttp implements ClientInterface
{
    private HttpClient $http;

    public function __construct(
        private ?Cancellation $stop = null,
    ) {
        $this->http = HttpClientBuilder::buildDefault();
    }

    public function sendRequest(#[\SensitiveParameter] RequestInterface $request): ResponseInterface
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
            return new Response(
                $response->getStatus(),
                $response->getHeaders(),
                $response->getBody()->buffer($cancellation, 4_194_304),
            );
        } catch (\Throwable) {
            throw new DemoHttpException('The OpenAI request could not complete.');
        }
    }
}

final class DemoHttpException extends \RuntimeException implements ClientExceptionInterface {}
