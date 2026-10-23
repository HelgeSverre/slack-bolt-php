<?php

declare(strict_types=1);

namespace App\Listeners;

use Thunder\Bolt\Attributes\Shortcut as ShortcutAttribute;
use Thunder\Bolt\BlockKit\ExternalSelect;
use Thunder\Bolt\BlockKit\Input;
use Thunder\Bolt\BlockKit\PlainTextInput;
use Thunder\Bolt\Payloads\Shortcut;
use Thunder\Bolt\Responses\ShortcutResponse;
use Thunder\Bolt\Views\Modal;
use Thunder\Bolt\WebApi\Client;

final class OpenRequestModal
{
    public function __construct(
        private Client $client,
    ) {}

    #[ShortcutAttribute('open_request_modal', type: 'shortcut')]
    public function __invoke(Shortcut $shortcut): ShortcutResponse
    {
        $triggerId = $shortcut->triggerId ?? throw new \LogicException(
            'Slack did not provide a trigger_id for open_request_modal.',
        );
        $view = Modal::make(
            'Open request',
            Input::make(
                'What do you need?',
                PlainTextInput::make('title')->multiline()->placeholder('Describe the request'),
            )->id('summary'),
            Input::make(
                'Assignee',
                ExternalSelect::make('search_assignees')->minQueryLength(0)->placeholder('Search people'),
            )->id('assignee')->optional(),
        )
            ->callbackId('submit_request')
            ->submit('Submit')
            ->close('Cancel');
        $this->client->views()->open(['trigger_id' => $triggerId, 'view' => $view->toArray()]);
        return ShortcutResponse::empty();
    }
}
