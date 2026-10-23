<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Console;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Tempest\Console\Console;
use Tempest\Console\ExitCode;
use Tempest\Container\GenericContainer;
use Thunder\Bolt\App;
use Thunder\Bolt\BoltConfig;
use Thunder\Bolt\Console\SocketCommands;
use Thunder\Bolt\SocketMode\Config;
use Thunder\Bolt\SocketMode\Runner;
use Thunder\Bolt\SocketMode\Transport\Connection;
use Thunder\Bolt\SocketMode\Transport\ConnectionClosed;
use Thunder\Bolt\SocketMode\Transport\Connector;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;

final class SocketCommandsTest extends TestCase
{
    public function test_sigterm_stops_the_worker_and_restores_the_previous_signal_configuration(): void
    {
        if (
            !function_exists('pcntl_signal')
            || !function_exists('pcntl_signal_get_handler')
            || !function_exists('posix_kill')
        ) {
            self::markTestSkipped('PCNTL and POSIX signals are unavailable.');
        }

        $previousHandler = pcntl_signal_get_handler(SIGTERM);
        $previousAsyncSignals = pcntl_async_signals();
        $console = $this->createMock(Console::class);
        $console->expects(self::exactly(2))->method('info')->willReturnSelf();
        $http = new RecordingHttpClient([
            new Response(200, [], '{"ok":true,"url":"wss://socket.slack.test/?ticket=secret"}'),
        ]);
        $client = new Client($http, 'xoxb-bot');
        $app = new App(new GenericContainer(), $client, new BoltConfig(token: 'xoxb-bot'));
        $runner = new Runner($app, $client, new Config(appToken: 'xapp-app-token'), new SignalConnector());

        try {
            $method = new \ReflectionMethod(SocketCommands::class, 'run');
            self::assertSame(ExitCode::SUCCESS, $method->invoke(new SocketCommands($console), $runner));
            self::assertSame($previousHandler, pcntl_signal_get_handler(SIGTERM));
            self::assertSame($previousAsyncSignals, pcntl_async_signals());
        } finally {
            pcntl_signal(SIGTERM, $previousHandler);
            pcntl_async_signals($previousAsyncSignals);
        }
    }
}

final class SignalConnector implements Connector
{
    public function connect(string $url): Connection
    {
        posix_kill(posix_getpid(), SIGTERM);

        throw new ConnectionClosed('Signal requested worker shutdown.');
    }
}
