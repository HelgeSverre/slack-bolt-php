<?php

declare(strict_types=1);

namespace Thunder\Bolt\SocketMode\Transport;

use Amp\TimeoutCancellation;
use Amp\Websocket\Client\Rfc6455ConnectionFactory;
use Amp\Websocket\Client\Rfc6455Connector;
use Amp\Websocket\Client\WebsocketConnector;
use Amp\Websocket\Client\WebsocketHandshake;
use Amp\Websocket\Parser\Rfc6455ParserFactory;
use Amp\Websocket\PeriodicHeartbeatQueue;

final readonly class AmpConnector implements Connector
{
    private WebsocketConnector $connector;

    /**
     * Amp validates the TLS certificate and peer hostname for wss URLs by default.
     * The transport deliberately provides no switch that weakens that validation.
     */
    public function __construct(
        float $connectTimeoutSeconds = 10.0,
        float $tlsHandshakeTimeoutSeconds = 10.0,
        int $heartbeatPeriodSeconds = 10,
        int $unansweredPingLimit = 3,
        private int $maxMessageBytes = 1_048_576,
        float $closePeriodSeconds = 0.5,
        ?WebsocketConnector $connector = null,
    ) {
        if (
            !is_finite($connectTimeoutSeconds)
            || !is_finite($tlsHandshakeTimeoutSeconds)
            || !is_finite($closePeriodSeconds)
            || $connectTimeoutSeconds <= 0
            || $tlsHandshakeTimeoutSeconds <= 0
        ) {
            throw new \ValueError('Socket Mode connection timeouts must be positive.');
        }
        if (
            $heartbeatPeriodSeconds <= 0
            || $unansweredPingLimit <= 0
            || $this->maxMessageBytes <= 0
            || $closePeriodSeconds <= 0
        ) {
            throw new \ValueError('Socket Mode heartbeat and message limits must be positive.');
        }

        $this->connector = $connector ?? new Rfc6455Connector(new Rfc6455ConnectionFactory(
            heartbeatQueue: new PeriodicHeartbeatQueue($unansweredPingLimit, $heartbeatPeriodSeconds),
            parserFactory: new Rfc6455ParserFactory(
                textOnly: true,
                messageSizeLimit: $this->maxMessageBytes,
                frameSizeLimit: $this->maxMessageBytes,
            ),
            closePeriod: $closePeriodSeconds,
        ));
        $this->connectTimeoutSeconds = $connectTimeoutSeconds;
        $this->tlsHandshakeTimeoutSeconds = $tlsHandshakeTimeoutSeconds;
    }

    private float $connectTimeoutSeconds;

    private float $tlsHandshakeTimeoutSeconds;

    public function connect(#[\SensitiveParameter] string $url): Connection
    {
        $this->assertSecureSocketUrl($url);

        try {
            $handshake = new WebsocketHandshake($url)
                ->withTcpConnectTimeout($this->connectTimeoutSeconds)
                ->withTlsHandshakeTimeout($this->tlsHandshakeTimeoutSeconds);

            return new AmpConnection(
                $this->connector->connect(
                    $handshake,
                    new TimeoutCancellation($this->connectTimeoutSeconds + $this->tlsHandshakeTimeoutSeconds),
                ),
                $this->maxMessageBytes,
            );
        } catch (\Throwable) {
            throw new TransportException('Unable to establish a Socket Mode WebSocket connection.');
        }
    }

    private function assertSecureSocketUrl(#[\SensitiveParameter] string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new TransportException('Socket Mode requires a valid wss WebSocket URL.');
        }

        $host = $parts['host'] ?? null;
        if (
            ($parts['scheme'] ?? null) !== 'wss'
            || !is_string($host)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
        ) {
            throw new TransportException('Socket Mode requires a valid wss WebSocket URL.');
        }
    }
}
