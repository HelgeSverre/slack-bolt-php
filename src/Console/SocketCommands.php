<?php

declare(strict_types=1);

namespace Thunder\Bolt\Console;

use Tempest\Console\Console;
use Tempest\Console\ConsoleArgument;
use Tempest\Console\ConsoleCommand;
use Tempest\Console\ExitCode;
use Thunder\Bolt\App;
use Thunder\Bolt\SocketMode\AuthenticationException;
use Thunder\Bolt\SocketMode\Config;
use Thunder\Bolt\SocketMode\Runner;

/** Commands for the long-running Socket Mode worker. */
final readonly class SocketCommands
{
    public function __construct(
        private Console $console,
    ) {}

    #[ConsoleCommand(name: 'socket:serve', description: 'Run the Slack Socket Mode worker')]
    public function serve(
        #[ConsoleArgument(
            name: 'verify-token',
            description: 'Verify and hydrate static-token identity before connecting',
        )]
        bool $verifyToken = false,
    ): ExitCode {
        // Resolving App first runs the application composition root. This binds
        // the Web API client and Socket Mode configuration without making help
        // or other console commands require an app-level token.
        $app = \Tempest\Container\get(App::class);
        $config = \Tempest\Container\get(Config::class);

        if (!$config->isConfigured()) {
            $this->error(
                'SLACK_APP_TOKEN is required for socket:serve. Create an app-level xapp token in Slack and set it in .env.',
            );

            return ExitCode::INVALID;
        }
        if (!$app->hasAuthorization() && !$app->hasFunctionListeners()) {
            $this->error('socket:serve requires SLACK_BOT_TOKEN or configured OAuth installation authorization.');

            return ExitCode::INVALID;
        }

        if ($verifyToken) {
            try {
                $app->verifyToken();
            } catch (\Throwable) {
                $this->error(
                    'Static token verification failed. Check the token and configured identity before connecting.',
                );
                return ExitCode::ERROR;
            }
        }
        return $this->run(\Tempest\Container\get(Runner::class));
    }

    private function run(Runner $runner): ExitCode
    {
        $stop = false;
        /** @var array<int, callable|int> $previousHandlers */
        $previousHandlers = [];
        $previousAsyncSignals = null;

        if (function_exists('pcntl_signal') && function_exists('pcntl_signal_get_handler')) {
            $previousAsyncSignals = pcntl_async_signals();
            pcntl_async_signals(true);
            foreach ($this->signals() as $signal) {
                /** @var callable|int $handler PHP's PCNTL signature is broader than this extension's stub. */
                $handler = pcntl_signal_get_handler($signal);
                $previousHandlers[$signal] = $handler;
                pcntl_signal($signal, static function () use (&$stop): void {
                    $stop = true;
                });
            }
        }

        $this->console->info('Socket Mode worker started. Press Ctrl+C to stop.');
        try {
            $runner->run(static function () use (&$stop): bool {
                return $stop;
            });
        } catch (AuthenticationException) {
            $this->error('Socket Mode could not authenticate. Check SLACK_APP_TOKEN and its connections:write scope.');

            return ExitCode::ERROR;
        } catch (\Throwable) {
            $this->error(
                'Socket Mode stopped because its local runtime failed. Check your application log for details.',
            );

            return ExitCode::ERROR;
        } finally {
            foreach ($previousHandlers as $signal => $handler) {
                pcntl_signal($signal, $handler);
            }
            if ($previousAsyncSignals !== null) {
                pcntl_async_signals($previousAsyncSignals);
            }
        }

        $this->console->info($stop ? 'Socket Mode worker stopped.' : 'Socket Mode worker finished.');

        return ExitCode::SUCCESS;
    }

    private function error(string $message): void
    {
        fwrite(STDERR, $message . PHP_EOL);
    }

    /** @return list<int> */
    private function signals(): array
    {
        $signals = [];
        if (defined('SIGINT')) {
            $signals[] = SIGINT;
        }
        if (defined('SIGTERM')) {
            $signals[] = SIGTERM;
        }

        return $signals;
    }
}
