<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\SocketMode\Transport;

use Amp\Websocket\Client\WebsocketConnector;
use PHPUnit\Framework\TestCase;
use Thunder\Bolt\SocketMode\Transport\AmpConnection;
use Thunder\Bolt\SocketMode\Transport\AmpConnector;
use Thunder\Bolt\SocketMode\Transport\TransportException;

final class AmpConnectorTest extends TestCase
{
    public function test_rejects_insecure_or_credentialed_urls_without_connecting(): void
    {
        $native = $this->createMock(WebsocketConnector::class);
        $native->expects(self::never())->method('connect');
        $connector = new AmpConnector(connector: $native);

        foreach ([
            'ws://localhost/socket',
            'wss://token@example.test/socket',
            'wss://example.test/socket#fragment',
        ] as $url) {
            try {
                $connector->connect($url);
                self::fail('Expected the invalid URL to be rejected.');
            } catch (TransportException $exception) {
                self::assertSame('Socket Mode requires a valid wss WebSocket URL.', $exception->getMessage());
                self::assertStringNotContainsString('token', $exception->getMessage());
            }
        }
    }

    public function test_rejects_invalid_transport_limits_at_construction(): void
    {
        $this->expectException(\ValueError::class);
        new AmpConnector(maxMessageBytes: 0);
    }

    public function test_rejects_non_finite_timeouts_at_construction(): void
    {
        $this->expectException(\ValueError::class);
        new AmpConnector(connectTimeoutSeconds: INF);
    }

    public function test_builds_a_tls_handshake_and_wraps_the_native_connection(): void
    {
        $connection = $this->createStub(\Amp\Websocket\Client\WebsocketConnection::class);
        $native = $this->createMock(WebsocketConnector::class);
        $native
            ->expects(self::once())
            ->method('connect')
            ->with(self::callback(static function (\Amp\Websocket\Client\WebsocketHandshake $handshake): bool {
                return (
                    $handshake->getUri()->getScheme() === 'wss'
                    && $handshake->getTcpConnectTimeout() === 2.0
                    && $handshake->getTlsHandshakeTimeout() === 3.0
                );
            }), self::isInstanceOf(\Amp\TimeoutCancellation::class))
            ->willReturn($connection);

        $result = new AmpConnector(2.0, 3.0, connector: $native)->connect('wss://example.test/socket?ticket=redacted');

        self::assertInstanceOf(AmpConnection::class, $result);
    }
}
