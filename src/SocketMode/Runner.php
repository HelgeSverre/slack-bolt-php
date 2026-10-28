<?php

declare(strict_types=1);

namespace Thunder\Bolt\SocketMode;

use Closure;
use JsonException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use Thunder\Bolt\App;
use Thunder\Bolt\Invocation\Delivery;
use Thunder\Bolt\SocketMode\Transport\Connection;
use Thunder\Bolt\SocketMode\Transport\ConnectionClosed;
use Thunder\Bolt\SocketMode\Transport\Connector;
use Thunder\Bolt\WebApi\Client;
use Thunder\Bolt\WebApi\RateLimited;
use Thunder\Bolt\WebApi\SlackApiException;

/**
 * Runs one Socket Mode worker. The transport owns the WebSocket handshake and
 * ping/pong; this class owns Slack's application envelope lifecycle.
 */
final class Runner
{
    private readonly LoggerInterface $logger;

    private readonly Closure $sleep;

    private readonly Closure $clock;

    public function __construct(
        private readonly App $app,
        private readonly Client $client,
        private readonly Config $config,
        private readonly Connector $connector,
        ?LoggerInterface $logger = null,
        ?callable $sleep = null,
        ?callable $clock = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->sleep = $sleep === null
            ? static function (float $seconds): void {
                $microseconds = (int) ceil($seconds * 1_000_000);
                if ($microseconds > 0) {
                    usleep($microseconds);
                }
            }
            : Closure::fromCallable($sleep);
        $this->clock = $clock === null
            ? static fn(): float => hrtime(true) / 1_000_000_000
            : Closure::fromCallable($clock);
    }

    /**
     * Receives Socket Mode envelopes until the caller requests shutdown.
     *
     * The worker purposefully does not replay a frame after an acknowledgement
     * send fails. Slack may redeliver that envelope on a later connection.
     *
     * @param null|callable(): bool $shouldStop
     */
    public function run(?callable $shouldStop = null): void
    {
        $shouldStop ??= static fn(): bool => false;
        if (!$this->config->isConfigured()) {
            $this->logger->warning('Socket Mode is not configured; the runner will not connect.');

            return;
        }

        $backoffSeconds = $this->config->initialBackoffMilliseconds / 1_000;

        while (!$shouldStop()) {
            $connection = null;
            $immediateReconnect = false;
            $rateLimitDelay = null;

            try {
                $connection = $this->connector->connect($this->connectionUrl());
                $ready = $this->awaitHello($connection, $shouldStop);
                if ($ready === null) {
                    return;
                }
                if ($ready !== 'ready') {
                    $immediateReconnect = $ready === 'refresh';
                } else {
                    $backoffSeconds = $this->config->initialBackoffMilliseconds / 1_000;
                    $outcome = $this->receiveEnvelopes($connection, $shouldStop);
                    if ($outcome === null) {
                        return;
                    }
                    $immediateReconnect = $outcome;
                }
            } catch (AuthenticationException $exception) {
                $this->logger->error('Socket Mode could not authenticate its app token.');

                throw $exception;
            } catch (RateLimited $exception) {
                $this->logger->warning('Socket Mode URL request was rate limited.');
                $rateLimitDelay = (float) $exception->retryAfter;
            } catch (ConnectionClosed $exception) {
                $this->logTransportFailure('Socket Mode connection closed.', $exception);
            } catch (Throwable $exception) {
                $this->logTransportFailure('Socket Mode connection failed.', $exception);
            } finally {
                if ($connection !== null) {
                    try {
                        $connection->close();
                    } catch (Throwable) {
                        // A transport failure already leads to a fresh connection.
                    }
                }
            }

            if ($shouldStop()) {
                return;
            }
            if ($rateLimitDelay !== null) {
                $this->sleepInterruptibly(max($rateLimitDelay, 0.001), $shouldStop);

                continue;
            }
            if (!$immediateReconnect) {
                $this->sleepInterruptibly(max($backoffSeconds, 0.001), $shouldStop);
                $nextBackoff = $backoffSeconds === 0.0 ? 0.001 : $backoffSeconds * 2;
                $backoffSeconds = min($nextBackoff, $this->config->maxBackoffMilliseconds / 1_000);
            }
        }
    }

    /** @param callable(): bool $shouldStop */
    private function awaitHello(Connection $connection, callable $shouldStop): ?string
    {
        $deadline = $this->now() + $this->config->helloTimeoutSeconds;

        while (!$shouldStop()) {
            $remaining = $deadline - $this->now();
            if ($remaining <= 0) {
                $this->logger->warning('Socket Mode did not receive a hello frame before its deadline.');

                return 'retry';
            }
            $frame = $connection->receive(min($this->pollTimeout(), $remaining));
            if ($frame === null) {
                continue;
            }
            $message = $this->decode($frame);
            if ($message === null) {
                continue;
            }
            if (($message['type'] ?? null) === 'hello') {
                $this->logger->debug('Socket Mode connection is ready.');

                return 'ready';
            }
            if (($message['type'] ?? null) === 'disconnect') {
                $outcome = $this->handleDisconnect($message);

                return $outcome === null ? null : ($outcome ? 'refresh' : 'retry');
            }
            $this->logger->warning('Socket Mode ignored a frame received before hello.');
        }

        return null;
    }

    /** @param callable(): bool $shouldStop */
    private function receiveEnvelopes(Connection $connection, callable $shouldStop): ?bool
    {
        while (!$shouldStop()) {
            $frame = $connection->receive($this->pollTimeout());
            if ($frame === null) {
                continue;
            }
            $message = $this->decode($frame);
            if ($message === null) {
                continue;
            }

            $type = is_string($message['type'] ?? null) ? $message['type'] : null;
            if ($type === 'hello') {
                $this->logger->debug('Socket Mode received a duplicate hello frame.');

                continue;
            }
            if ($type === 'warning') {
                $this->logger->warning('Socket Mode received a warning frame.');

                continue;
            }
            if ($type === 'disconnect') {
                return $this->handleDisconnect($message);
            }

            if ($this->dispatchEnvelope($connection, $message)) {
                return false;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $message */
    private function dispatchEnvelope(Connection $connection, array $message): bool
    {
        if (
            !is_string($message['type'] ?? null)
            || !in_array($message['type'], ['events_api', 'interactive', 'slash_commands'], true)
            || !is_string($message['envelope_id'] ?? null)
            || $message['envelope_id'] === ''
            || !is_array($message['payload'] ?? null)
            || array_is_list($message['payload'])
        ) {
            $this->logger->warning('Socket Mode ignored a malformed or unsupported delivery envelope.');

            return false;
        }
        $type = $message['type'];
        $envelopeId = $message['envelope_id'];
        $payload = $message['payload'];

        $metadata = [
            'transport' => 'socket_mode',
            'envelopeId' => $envelopeId,
        ];
        if (is_int($message['retry_attempt'] ?? null)) {
            $metadata['retryNum'] = $message['retry_attempt'];
        }
        if (is_string($message['retry_reason'] ?? null)) {
            $metadata['retryReason'] = $message['retry_reason'];
        }

        $startedAt = $this->now();
        try {
            $factory = $this->config->contextFactory;
            $acknowledgement = $this->app->dispatch(
                $payload,
                $metadata,
                $factory === null ? null : fn(Delivery $delivery) => $factory(new ContextInput($delivery, $message)),
            );
        } catch (Throwable $exception) {
            // A failed handler is not a successful acknowledgement. Continue with
            // the next frame; Slack may choose to redeliver this envelope.
            $this->logDispatchFailure($exception);

            return false;
        }

        if (($this->now() - $startedAt) >= 3.0) {
            $this->logger->warning('Socket Mode delivery acknowledgement exceeded the three-second deadline.');
        }

        $response = ['envelope_id' => $envelopeId];
        if (
            ($message['accepts_response_payload'] ?? false) === true
            && $acknowledgement !== null
            && $acknowledgement->toArray() !== []
        ) {
            $response['payload'] = $acknowledgement->toArray();
        }
        try {
            $connection->send(json_encode($response, JSON_THROW_ON_ERROR));
        } catch (Throwable $exception) {
            // Never call App::dispatch again after an acknowledgement write fails.
            $this->logTransportFailure('Socket Mode acknowledgement could not be sent.', $exception);

            return true;
        }

        return false;
    }

    /** @param array<string, mixed> $message */
    private function handleDisconnect(array $message): ?bool
    {
        if (!is_string($message['reason'] ?? null)) {
            $this->logger->warning('Socket Mode received a disconnect frame without a reason.');

            return false;
        }
        $reason = $message['reason'];
        if ($reason === 'refresh_requested') {
            $this->logger->info('Socket Mode requested a connection refresh.');

            return true;
        }
        if ($reason === 'warning') {
            $this->logger->warning('Socket Mode warned that this connection will close soon.');

            return false;
        }
        if ($reason === 'link_disabled') {
            $this->logger->warning('Socket Mode was disabled by Slack.');

            return null;
        }

        $this->logger->warning('Socket Mode received a disconnect frame.');

        return false;
    }

    /** @return array<string, mixed>|null */
    private function decode(string $frame): ?array
    {
        if (strlen($frame) > $this->config->maxMessageBytes) {
            $this->logger->warning('Socket Mode ignored a frame that exceeds its configured size limit.');

            return null;
        }
        try {
            $decoded = json_decode($frame, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->logger->warning('Socket Mode ignored a malformed JSON frame.');

            return null;
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            $this->logger->warning('Socket Mode ignored a non-object JSON frame.');

            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function connectionUrl(): string
    {
        try {
            $response = $this->client->apps()->connections()->open(['token' => $this->config->appToken]);
        } catch (SlackApiException $exception) {
            if (in_array(
                $exception->error,
                [
                    'invalid_auth',
                    'not_authed',
                    'token_revoked',
                    'account_inactive',
                    'missing_scope',
                    'user_removed_from_team',
                    'team_disabled',
                    'token_expired',
                    'token_invalid',
                    'not_allowed_token_type',
                ],
                true,
            )) {
                throw new AuthenticationException(
                    'Socket Mode app token was rejected. Check SLACK_APP_TOKEN and its connections:write scope.',
                );
            }

            throw $exception;
        }
        if (!is_string($response->url)) {
            throw new \UnexpectedValueException('apps.connections.open returned an invalid WebSocket URL.');
        }
        $url = $response->url;
        $parts = parse_url($url);
        if (
            !is_array($parts)
            || ($parts['scheme'] ?? null) !== 'wss'
            || !isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
        ) {
            throw new \UnexpectedValueException('apps.connections.open returned an invalid WebSocket URL.');
        }

        return $url;
    }

    /** @param callable(): bool $shouldStop */
    private function sleepInterruptibly(float $seconds, callable $shouldStop): void
    {
        $deadline = $this->now() + $seconds;
        while (!$shouldStop()) {
            $remaining = $deadline - $this->now();
            if ($remaining <= 0) {
                return;
            }
            ($this->sleep)(min($remaining, 0.1));
        }
    }

    private function pollTimeout(): float
    {
        return min($this->config->receiveTimeoutSeconds, 0.25);
    }

    private function now(): float
    {
        $now = ($this->clock)();
        if (!is_int($now) && !is_float($now)) {
            throw new \LogicException('Socket Mode clock must return a numeric timestamp.');
        }

        return (float) $now;
    }

    private function logDispatchFailure(Throwable $exception): void
    {
        $this->logger->error('Socket Mode delivery dispatch failed.', ['exceptionClass' => $exception::class]);
    }

    private function logTransportFailure(string $message, Throwable $exception): void
    {
        $this->logger->warning($message, ['exceptionClass' => $exception::class]);
    }
}
