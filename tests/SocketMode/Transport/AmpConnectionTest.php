<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\SocketMode\Transport;

use Amp\Websocket\Client\WebsocketConnection;
use Amp\Websocket\WebsocketMessage;
use PHPUnit\Framework\TestCase;
use Thunder\Bolt\SocketMode\Transport\AmpConnection;
use Thunder\Bolt\SocketMode\Transport\ConnectionClosed;
use Thunder\Bolt\SocketMode\Transport\TransportException;

final class AmpConnectionTest extends TestCase
{
    public function test_receives_text_frames_and_sends_text_frames(): void
    {
        $native = $this->createMock(WebsocketConnection::class);
        $native->expects(self::once())->method('receive')->willReturn(WebsocketMessage::fromText('{"type":"hello"}'));
        $native->expects(self::once())->method('sendText')->with('{"envelope_id":"E1"}');

        $connection = new AmpConnection($native, 1024);
        self::assertSame('{"type":"hello"}', $connection->receive(1.0));
        $connection->send('{"envelope_id":"E1"}');
    }

    public function test_closed_native_connection_is_normalized_without_leaking_details(): void
    {
        $native = $this->createMock(WebsocketConnection::class);
        $native->expects(self::once())->method('receive')->willReturn(null);

        $this->expectException(ConnectionClosed::class);
        $this->expectExceptionMessage('Socket Mode WebSocket connection closed.');
        new AmpConnection($native, 1024)->receive(1.0);
    }

    public function test_idle_poll_timeout_returns_null_without_closing_the_connection(): void
    {
        $native = $this->createMock(WebsocketConnection::class);
        $native
            ->expects(self::once())
            ->method('receive')
            ->willThrowException(new \Amp\TimeoutException());
        $native->expects(self::never())->method('close');

        self::assertNull(new AmpConnection($native, 1024)->receive(0.01));
    }

    public function test_rejects_non_finite_receive_timeout(): void
    {
        $native = $this->createMock(WebsocketConnection::class);
        $native->expects(self::never())->method('receive');

        $this->expectException(\ValueError::class);
        new AmpConnection($native, 1024)->receive(NAN);
    }

    public function test_amp_timeout_cancellation_returns_null_without_closing_the_connection(): void
    {
        $native = $this->createMock(WebsocketConnection::class);
        $native
            ->expects(self::once())
            ->method('receive')
            ->willThrowException(new \Amp\CancelledException(new \Amp\TimeoutException()));
        $native->expects(self::never())->method('close');

        self::assertNull(new AmpConnection($native, 1024)->receive(0.01));
    }

    public function test_rejects_binary_frames_and_closes_the_message(): void
    {
        $native = $this->createMock(WebsocketConnection::class);
        $native->expects(self::once())->method('receive')->willReturn(WebsocketMessage::fromBinary("\x00\x01"));

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Socket Mode only accepts text WebSocket messages.');
        new AmpConnection($native, 1024)->receive(1.0);
    }

    public function test_rejects_oversized_outbound_payload_before_writing(): void
    {
        $native = $this->createMock(WebsocketConnection::class);
        $native->expects(self::never())->method('sendText');

        $this->expectException(TransportException::class);
        new AmpConnection($native, 3)->send('four');
    }

    public function test_close_is_idempotent_from_the_callers_perspective(): void
    {
        $native = $this->createMock(WebsocketConnection::class);
        $native->expects(self::exactly(2))->method('close');

        $connection = new AmpConnection($native, 1024);
        $connection->close();
        $connection->close();
    }
}
