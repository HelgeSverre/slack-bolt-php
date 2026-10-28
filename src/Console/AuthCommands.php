<?php

declare(strict_types=1);

namespace Thunder\Bolt\Console;

use Tempest\Console\ConsoleCommand;
use Tempest\Console\ExitCode;
use Thunder\Bolt\App;

final readonly class AuthCommands
{
    #[ConsoleCommand(name: 'auth:verify', description: 'Verify the static Slack token and print its identity as JSON')]
    public function verify(): ExitCode
    {
        try {
            $identity = \Tempest\Container\get(App::class)->verifyToken();
            fwrite(STDOUT, json_encode($identity, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);
            return ExitCode::SUCCESS;
        } catch (\Throwable) {
            fwrite(
                STDERR,
                "Static token verification failed. Check the token, configured identity and connectivity.\n",
            );
            return ExitCode::ERROR;
        }
    }
}
