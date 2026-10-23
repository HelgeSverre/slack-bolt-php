<?php

declare(strict_types=1);

namespace App\Listeners;

use Thunder\Bolt\Attributes\Event;
use Thunder\Bolt\BlockKit\Actions;
use Thunder\Bolt\BlockKit\Button;
use Thunder\Bolt\BlockKit\Section;
use Thunder\Bolt\Messages\Message;
use Thunder\Bolt\Payloads\Events\AppMention;
use Thunder\Bolt\Reply;

final class GreetOnMention
{
    #[Event('app_mention')]
    public function __invoke(AppMention $event, Reply $reply): void
    {
        $reply->inThread(Message::blocks(
            Section::markdown("Hello <@{$event->userId}>! Ready to review this request?"),
            Actions::make(Button::make('Approve', 'approve_request')->primary()),
        )->fallbackText("Hello <@{$event->userId}>! Ready to review this request?"));
    }
}
