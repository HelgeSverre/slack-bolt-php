<?php

declare(strict_types=1);

namespace Thunder\Bolt\WebApi\Uploads;

use GuzzleHttp\Psr7\Request;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;

/** @internal Trusted, one-shot POST to Slack's presigned upload endpoint. */
final readonly class ExternalUpload
{
    private function __construct(
        private string $url,
    ) {}

    public static function fromSlack(string $url): self
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? $parts['host'] ?? null : null;
        if (
            !is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || !is_string($host)
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
            || array_key_exists('fragment', $parts)
        ) {
            throw new UploadException('Slack returned an unsafe external upload URL.');
        }

        return new self($url);
    }

    public function send(ClientInterface $transport, Upload $upload): void
    {
        $body = $upload->body()->open();
        // Presigned Slack upload URLs use their own authorization. Never leak the API bearer token.
        // The PSR-18 caller owns an injected stream, so this helper must not close it.
        $response = $transport->sendRequest(
            new Request('POST', $this->url, ['Content-Length' => (string) $upload->length()], $body),
        );
        $this->assertSuccessful($response);
    }

    private function assertSuccessful(ResponseInterface $response): void
    {
        if ($response->getStatusCode() !== 200) {
            throw new UploadException('external_upload_http_' . $response->getStatusCode());
        }
    }
}
