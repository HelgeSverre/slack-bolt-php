<?php

declare(strict_types=1);

namespace Thunder\Bolt\SocketMode\Transport;

interface Connection
{
    /**
     * Returns null when no complete message is received before the timeout.
     *
     * @throws ConnectionClosed
     * @throws TransportException
     */
    public function receive(float $timeoutSeconds): ?string;

    /** @throws ConnectionClosed|TransportException */
    public function send(#[\SensitiveParameter] string $payload): void;

    public function close(): void;
}
