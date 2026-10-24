<?php

declare(strict_types=1);

namespace App\Demo;

use Thunder\Bolt\BlockKit\Actions;
use Thunder\Bolt\BlockKit\Button;
use Thunder\Bolt\BlockKit\Context;
use Thunder\Bolt\BlockKit\Section;
use Thunder\Bolt\BlockKit\Text;
use Thunder\Bolt\Messages\Message;
use Thunder\Bolt\Views\Modal;

final class KitchenSinkView
{
    public static function message(): Message
    {
        return Message::blocks(
            Section::markdown(
                "*Thunder · the kitchen sink*\nA working tour of what we’ve built. The live session runs in this thread.",
            ),
            Section::fields(
                Text::markdown(
                    "*Agent sessions*\nLifecycle, editable titles, native Stop, streaming text and tool progress.",
                ),
                Text::markdown(
                    "*Durable execution*\nNamed jobs, SQLite, deduplication, leases, recovery and cancellation.",
                ),
                Text::markdown("*Boltling*\nShared pet, durable stats, levels, care credit and App Home dashboard."),
                Text::markdown("*Slack surfaces*\nActions, shortcuts, options, modals, work objects and comments."),
            ),
            Actions::make(
                Button::make('Ask AI', 'kitchen_sink_ai')->primary(),
                Button::make('Check Boltling', 'kitchen_sink_pet'),
                Button::make('Create work item', 'kitchen_sink_work'),
                Button::make('Framework details', 'kitchen_sink_details'),
            ),
            Actions::make(
                Button::make('Run a stoppable session', 'kitchen_sink_stream'),
                Button::make('Stop this thread', 'kitchen_sink_stop'),
            ),
            Context::make(Text::markdown(
                'AI uses the Responses API with web search and scoped pet tools. Terminal: `/bolt terminal` (allowlisted users). Repeat: `/kickensink`.',
            )),
        )->fallbackText(
            'Thunder kitchen sink: agent sessions, durable jobs, AI, Boltling, work objects and interactive views.',
        );
    }

    public static function details(): Modal
    {
        return Modal::make(
            'Under the hood',
            Section::markdown(
                "*Modern PHP*\nTyped request and response models, fluent message and view builders, upload helpers and retry policies.",
            ),
            Section::markdown(
                "*Installation and state*\nOAuth, state validation, installation stores, token rotation, conversation storage and fresh authorization for durable jobs.",
            ),
            Section::markdown(
                "*Custom functions*\nTyped inputs, execution context, complete/fail helpers and protected async handoff. Live Workflow Builder use needs an eligible Slack plan.",
            ),
            Section::markdown(
                "*Cancellation is real*\nStop flags the persisted job. A running AI attempt cancels its HTTP request, closes the response stream and stops before further tools. Cancellation cannot undo a tool that already finished.",
            ),
        )->close('Done');
    }
}
