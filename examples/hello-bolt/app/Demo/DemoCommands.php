<?php

declare(strict_types=1);

namespace App\Demo;

use Tempest\Console\ConsoleCommand;
use Tempest\Console\ExitCode;

final class DemoCommands
{
    #[ConsoleCommand(name: 'demo:work', description: 'Process queued hello-bolt demo jobs')]
    public function work(): ExitCode
    {
        \Tempest\Container\get(\Thunder\Bolt\App::class);
        \Tempest\Container\get(DemoRuntime::class)->run();

        return ExitCode::SUCCESS;
    }
}
