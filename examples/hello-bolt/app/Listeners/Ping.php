<?php

declare(strict_types=1);

namespace App\Listeners;

use Thunder\Bolt\Attributes\SlashCommand;
use Thunder\Bolt\Payloads\SlashCommand as SlackCommand;
use Thunder\Bolt\Responses\CommandResponse;

final class Ping
{
    #[SlashCommand('/ping')]
    public function __invoke(SlackCommand $command): CommandResponse
    {
        return CommandResponse::text('Thunder Bolt received your command.');
    }
}
