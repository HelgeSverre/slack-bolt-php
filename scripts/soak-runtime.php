<?php

declare(strict_types=1);

use Amp\Websocket\Client\Rfc6455Connector;
use Amp\Websocket\Client\WebsocketHandshake;
use Revolt\EventLoop;
use Thunder\Bolt\SocketMode\Transport\AmpConnection;

require dirname(__DIR__) . '/vendor/autoload.php';

$options = getopt('', ['duration:', 'interval:', 'help']);
if (isset($options['help'])) {
    fwrite(
        STDOUT,
        "Usage: php -d memory_limit=128M scripts/soak-runtime.php --duration=7200 --interval=60\nLocal WebSocket polling plus repeated isolated crash/recovery, cancellation, reconnect and OAuth rotation tests. No live Slack calls.\n",
    );
    exit(0);
}
$duration = filter_var($options['duration'] ?? 7200, FILTER_VALIDATE_INT);
$interval = filter_var($options['interval'] ?? 60, FILTER_VALIDATE_INT);
if (
    $duration === false
    || $duration < 1
    || $duration > 86400
    || $interval === false
    || $interval < 1
    || $interval > 3600
) {
    fwrite(STDERR, "Use --duration=1..86400 and --interval=1..3600 seconds.\n");
    exit(2);
}
$root = dirname(__DIR__);
$rounds = 0;
$checks = static function () use ($root, &$rounds): void {
    $command = [
        PHP_BINARY,
        '-d',
        'memory_limit=128M',
        $root . '/vendor/bin/phpunit',
        '--colors=never',
        $root . '/tests/Runtime/Operations',
        $root . '/tests/SocketMode/RunnerTest.php',
        $root . '/tests/OAuth/FileRotationConcurrencyTest.php',
        $root . '/tests/Jobs/DurableExecutionTest.php',
    ];
    $child = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    if (!is_resource($child))
        throw new RuntimeException('Cannot start isolated reliability checks.');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($child) !== 0)
        throw new RuntimeException('Isolated reliability checks failed: ' . $output . $errors);
    $rounds++;
};
$started = hrtime(true);
$lastRound = -INF;
$polls = 0;
$maxGrowth = 0;
$failed = false;

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
stream_set_blocking($server, false);
$address = stream_socket_get_name($server, false);
$peer = null;
$request = '';
$ready = false;
$watcher = EventLoop::repeat(0.0005, static function () use ($server, &$peer, &$request, &$ready) {
    if (!is_resource($peer))
        $peer = @stream_socket_accept($server, 0);
    if (!is_resource($peer))
        return;
    stream_set_blocking($peer, false);
    $chunk = fread($peer, 8192);
    if (is_string($chunk))
        $request .= $chunk;
    if ($ready) {
        if (strlen($request) >= 2 && (ord($request[0]) & 15) === 8) {
            fwrite($peer, "\x88\x02\x03\xe8");
            $request = '';
        }
        return;
    }
    if (!str_contains($request, "\r\n\r\n"))
        return;
    preg_match('/^Sec-WebSocket-Key:\s*(.+)$/mi', $request, $m);
    $accept = base64_encode(sha1(trim($m[1]) . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
    fwrite(
        $peer,
        "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: {$accept}\r\n\r\n",
    );
    $ready = true;
    $request = '';
});
$native = null;
try {
    $native = new Rfc6455Connector()->connect(new WebsocketHandshake('ws://' . $address));
    $connection = new AmpConnection($native, 1024);
    for ($i = 0; $i < 20; $i++)
        $connection->receive(0.0005);
    gc_collect_cycles();
    $baseline = memory_get_usage();
    while (((hrtime(true) - $started) / 1e9) < $duration) {
        if ($connection->receive(0.005) !== null)
            throw new RuntimeException('Unexpected idle data.');
        $polls++;
        $growth = memory_get_usage() - $baseline;
        $maxGrowth = max($maxGrowth, $growth);
        if ($growth > (16 * 1024 * 1024))
            throw new RuntimeException('Idle WebSocket retained more than 16 MiB.');
        $elapsed = (hrtime(true) - $started) / 1e9;
        if (($elapsed - $lastRound) >= $interval) {
            $checks();
            $lastRound = (hrtime(true) - $started) / 1e9;
            echo
                json_encode([
                    'phase' => 'running',
                    'elapsed_seconds' => round($lastRound, 1),
                    'rounds' => $rounds,
                    'polls' => $polls,
                    'retained_bytes' => $growth,
                    'peak_bytes' => memory_get_peak_usage(true),
                ]),
                PHP_EOL;
        }
    }
    echo
        json_encode([
            'phase' => 'passed',
            'elapsed_seconds' => round((hrtime(true) - $started) / 1e9, 1),
            'rounds' => $rounds,
            'polls' => $polls,
            'max_retained_bytes' => $maxGrowth,
            'peak_bytes' => memory_get_peak_usage(true),
            'live_slack' => false,
        ]),
        PHP_EOL;
} finally {
    if ($native !== null)
        $native->close();
    EventLoop::cancel($watcher);
    if (is_resource($peer))
        fclose($peer);
    fclose($server);
}
exit($failed ? 1 : 0);
