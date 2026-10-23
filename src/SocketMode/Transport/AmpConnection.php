<?php

declare(strict_types=1);

namespace Thunder\Bolt\SocketMode\Transport;

use Amp\CancelledException;
use Amp\TimeoutCancellation;
use Amp\TimeoutException;
use Amp\Websocket\Client\WebsocketConnection;

final readonly class AmpConnection implements Connection
{
    public function __construct(
        private WebsocketConnection $connection,
        private int $maxMessageBytes,
    ) {}

    public function receive(float $timeoutSeconds): ?string
    {
        if (!is_finite($timeoutSeconds) || $timeoutSeconds <= 0) {
            throw new \ValueError('Socket Mode receive timeout must be positive.');
        }

        $cancellation = new TimeoutCancellation($timeoutSeconds);
        $messageReceived = false;
        try {
            $message = $this->connection->receive($cancellation);
            if ($message === null) {
                throw new ConnectionClosed('Socket Mode WebSocket connection closed.');
            }
            if ($message->isBinary()) {
                $message->close();
                throw new TransportException('Socket Mode only accepts text WebSocket messages.');
            }
            $messageReceived = true;

            return $message->buffer($cancellation, $this->maxMessageBytes);
        } catch (CancelledException|TimeoutException $exception) {
            if ($exception instanceof CancelledException && !$exception->getPrevious() instanceof TimeoutException) {
                throw new ConnectionClosed('Socket Mode WebSocket connection closed.');
            }
            if (!$messageReceived) {
                return null;
            }
            // A cancellation while buffering can consume part of a fragmented frame.
            // Closing forces a reconnect instead of desynchronising the next receive call.
            $this->close();
            throw new ConnectionClosed('Socket Mode WebSocket receive deadline elapsed while reading a message.');
        } catch (ConnectionClosed|TransportException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new ConnectionClosed('Socket Mode WebSocket connection closed.');
        }
    }

    public function send(#[\SensitiveParameter] string $payload): void
    {
        if (strlen($payload) > $this->maxMessageBytes) {
            throw new TransportException('Socket Mode payload exceeds the configured message limit.');
        }

        try {
            $this->connection->sendText($payload);
        } catch (\Throwable) {
            throw new ConnectionClosed('Socket Mode WebSocket connection closed.');
        }
    }

    public function close(): void
    {
        // @mago-expect lint:no-empty-catch-clause -- Closing is best-effort after the peer has gone away.
        try {
            $this->connection->close();
        } catch (\Throwable) {
            // Closing is best-effort. The socket is already unusable to callers.
        }
    }
}
