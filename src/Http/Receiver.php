<?php

declare(strict_types=1);

namespace Thunder\Bolt\Http;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Thunder\Bolt\App;
use Thunder\Bolt\BoltConfig;
use Thunder\Bolt\Exceptions\InvalidPayload;
use Thunder\Bolt\Invocation\Interaction;

final readonly class Receiver
{
    private RequestVerifier $verifier;

    private LoggerInterface $logger;

    public function __construct(
        private App $app,
        private BoltConfig $config,
        ?RequestVerifier $verifier = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->verifier = $verifier ?? new RequestVerifier($config->signingSecret, $config->maxTimestampSkewSeconds);
        $this->logger = $logger ?? new NullLogger();
    }

    /** @param array<string, string|list<string>> $headers */
    public function handle(string $rawBody, array $headers = [], string $contentType = 'application/json'): Response
    {
        $started = hrtime(true);
        if (strlen($rawBody) > $this->config->maxBodyBytes) {
            return new Response(413, 'Request body exceeds the configured limit.');
        }
        if ($this->config->signingSecret === '') {
            return new Response(503, 'Configure SLACK_SIGNING_SECRET before accepting Slack requests.');
        }
        if (!$this->verifier->isValid($rawBody, $headers)) {
            return new Response(401, 'Invalid Slack signature or request timestamp.');
        }
        try {
            $body = $this->decode($rawBody, $contentType);
        } catch (\JsonException|\InvalidArgumentException) {
            return new Response(400, 'Expected a Slack JSON or form-encoded payload.');
        }
        if (($body['type'] ?? null) === 'url_verification') {
            return is_string($body['challenge'] ?? null)
                ? new Response(200, $body['challenge'])
                : new Response(400, 'Missing URL verification challenge.');
        }
        $isCommand = is_string($body['command'] ?? null);
        $isEvent =
            ($body['type'] ?? null) === 'event_callback'
            && is_array($body['event'] ?? null)
            && is_string($body['event']['type'] ?? null);
        if (!$isCommand && !$isEvent && Interaction::kind($body) === null) {
            return new Response(501, 'This Slack payload family is not implemented.');
        }
        $normalized = array_change_key_case($headers, CASE_LOWER);
        $retry = $normalized['x-slack-retry-num'] ?? null;
        $reason = $normalized['x-slack-retry-reason'] ?? null;
        $metadata = [
            'retryNum' => is_string($retry) && ctype_digit($retry) ? (int) $retry : null,
            'retryReason' => is_string($reason) ? $reason : null,
        ];
        try {
            if (!$this->app->hasAuthorization($body)) {
                return new Response(
                    503,
                    'Configure a Slack bot token or installation authorizer before dispatching Slack deliveries.',
                );
            }
            $ack = $this->app->dispatch($body, $metadata);
        } catch (InvalidPayload $exception) {
            return new Response(400, 'Malformed Slack delivery payload.');
        } catch (\Throwable $exception) {
            $this->logger->error('Slack delivery failed before its HTTP response was emitted.', [
                'exception' => $exception,
            ]);

            return new Response(500, 'Slack delivery could not be processed.');
        }
        if (((hrtime(true) - $started) / 1e9) >= 2.5) {
            $this->logger->warning(
                'Inline Slack processing approached or exceeded the 3-second acknowledgement deadline. Move slow work to a worker.',
            );
        }

        return $ack === null || $ack->toArray() === [] ? new Response(200) : Response::json($ack->toArray());
    }

    private function decode(string $rawBody, string $contentType): array
    {
        $mediaType = strtolower(trim(explode(';', $contentType, 2)[0]));
        if ($mediaType === 'application/json') {
            $decoded = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);
        } elseif ($mediaType === 'application/x-www-form-urlencoded') {
            $decoded = [];
            parse_str($rawBody, $decoded);
            if (isset($decoded['payload'])) {
                if (!is_string($decoded['payload'])) {
                    throw new \InvalidArgumentException('Expected a string payload.');
                }
                $decoded = json_decode($decoded['payload'], true, flags: JSON_THROW_ON_ERROR);
            }
        } else {
            throw new \InvalidArgumentException('Unsupported content type.');
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new \InvalidArgumentException('Expected a Slack object.');
        }

        return $decoded;
    }
}
