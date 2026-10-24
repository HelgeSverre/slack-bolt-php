<?php

declare(strict_types=1);

namespace App\Demo\Ai;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;

interface StreamingHttpClient extends ClientInterface
{
    /** @param callable(string): void $onChunk */
    public function sendStreamingRequest(RequestInterface $request, callable $onChunk): void;
}
