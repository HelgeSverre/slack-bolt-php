<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\SocketMode\Transport;

use Amp\Websocket\Client\Rfc6455Connector;
use Amp\Websocket\Client\WebsocketHandshake;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Thunder\Bolt\SocketMode\Transport\AmpConnection;
use Thunder\Bolt\SocketMode\Transport\ConnectionClosed;

final class AmpLocalServerTest extends TestCase
{
    public function test_idle_polling_bounds_memory_before_automatic_collection(): void
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/Fixtures/socket-mode/idle-memory.php.stub'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $output . $error);
        $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(400, $report['polls']);
        self::assertTrue($report['bounded']);
    }

    public function test_receives_a_text_frame_after_a_real_local_websocket_handshake(): void
    {
        $server = stream_socket_server(
            'tcp://127.0.0.1:0',
            $errorNumber,
            $errorMessage,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        );
        self::assertIsResource($server, $errorMessage);
        stream_set_blocking($server, false);
        $address = stream_socket_get_name($server, false);
        self::assertIsString($address);

        $client = null;
        $request = '';
        $handshakeCompleted = false;
        $receivedPayload = null;
        $fragmentedResponseSent = false;
        $partialResponseSent = false;
        $watcher = EventLoop::repeat(0.001, static function () use (
            &$client,
            &$request,
            &$handshakeCompleted,
            &$receivedPayload,
            &$fragmentedResponseSent,
            &$partialResponseSent,
            $server,
            &$watcher,
        ): void {
            if (!is_resource($client)) {
                $read = [$server];
                $write = [];
                $except = [];
                if (stream_select($read, $write, $except, 0, 0) > 0) {
                    $client = stream_socket_accept($server, 0);
                }
            }
            if (!is_resource($client)) {
                return;
            }
            stream_set_blocking($client, false);
            $chunk = fread($client, 8192);
            if (is_string($chunk)) {
                $request .= $chunk;
            }
            if ($handshakeCompleted || !str_contains($request, "\r\n\r\n")) {
                if (!$handshakeCompleted || strlen($request) < 2) {
                    return;
                }
                if ($fragmentedResponseSent) {
                    if ((ord($request[0]) & 0x0f) === 0x08) {
                        fwrite($client, "\x88\x02\x03\xe8");
                        EventLoop::cancel($watcher);
                    } elseif (!$partialResponseSent) {
                        fwrite($client, "\x01\x08{\"type\":");
                        $partialResponseSent = true;
                        $request = '';
                    }

                    return;
                }
                $length = ord($request[1]) & 0x7f;
                if (($request[1] & "\x80") === "\x00" || $length >= 126 || strlen($request) < (6 + $length)) {
                    return;
                }
                $mask = substr($request, 2, 4);
                $encoded = substr($request, 6, $length);
                $receivedPayload = '';
                for ($index = 0; $index < $length; $index++) {
                    $receivedPayload .= $encoded[$index] ^ $mask[$index % 4];
                }
                fwrite($client, "\x01\x08{\"type\":");
                fwrite($client, "\x80\x08\"after\"}");
                $fragmentedResponseSent = true;
                $request = '';

                return;
            }
            preg_match('/^Sec-WebSocket-Key:\s*(.+)$/mi', $request, $matches);
            if (!isset($matches[1])) {
                return;
            }
            $accept = base64_encode(sha1(trim($matches[1]) . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
            fwrite(
                $client,
                "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: {$accept}\r\n\r\n",
            );
            fwrite($client, "\x81\x10{\"type\":\"hello\"}");
            $handshakeCompleted = true;
            $request = '';
        });

        try {
            $native = new Rfc6455Connector()->connect(new WebsocketHandshake("ws://{$address}"));
            $connection = new AmpConnection($native, 1024);

            self::assertSame('{"type":"hello"}', $connection->receive(1.0));
            self::assertTrue($handshakeCompleted);
            $connection->send('{"envelope_id":"E1"}');
            self::assertSame('{"type":"after"}', $connection->receive(1.0));
            self::assertSame('{"envelope_id":"E1"}', $receivedPayload);
            $connection->send('{"kind":"partial"}');
            try {
                $connection->receive(0.05);
                self::fail('Expected the incomplete message to close the connection at its deadline.');
            } catch (ConnectionClosed $exception) {
                self::assertSame(
                    'Socket Mode WebSocket receive deadline elapsed while reading a message.',
                    $exception->getMessage(),
                );
            }
        } finally {
            EventLoop::cancel($watcher);
            if (is_resource($client)) {
                fclose($client);
            }
            fclose($server);
        }
    }
}
