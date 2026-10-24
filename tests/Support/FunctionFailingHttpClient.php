<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Support;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class FunctionFailingHttpClient implements ClientInterface
{
    public ?RequestInterface $request = null;

    public function __construct(
        private \Throwable $failure,
    ) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->request = $request;

        throw $this->failure;
    }
}
