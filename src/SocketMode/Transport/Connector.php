<?php

declare(strict_types=1);

namespace Thunder\Bolt\SocketMode\Transport;

interface Connector
{
    /**
     * Opens a TLS-validated Socket Mode WebSocket connection.
     *
     * @throws TransportException
     */
    public function connect(#[\SensitiveParameter] string $url): Connection;
}
