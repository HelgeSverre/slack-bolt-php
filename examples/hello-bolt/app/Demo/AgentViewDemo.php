<?php

declare(strict_types=1);

namespace App\Demo;

use App\Demo\Jobs\AgentAnswer;
use Thunder\Bolt\Assistant\AppContext;
use Thunder\Bolt\Assistant\Thread;
use Thunder\Bolt\Invocation\Delivery;
use Thunder\Bolt\Jobs;
use Thunder\Bolt\Payloads\Events\AppHomeOpened;
use Thunder\Bolt\Payloads\Events\Message;
use Thunder\Bolt\WebApi\Client;

final readonly class AgentViewDemo
{
    public function opened(AppHomeOpened $event, Client $client): void
    {
        if ($event->channelId === null) {
            return;
        }
        $client
            ->assistant()
            ->threads()
            ->setSuggestedPrompts([
                'channel_id' => $event->channelId,
                'prompts' => [
                    ['title' => 'What can you do?', 'message' => 'What can you help me with in Slack?'],
                    ['title' => 'Check Boltling', 'message' => 'How is Boltling doing?'],
                    [
                        'title' => 'Current focus',
                        'message' => 'What am I looking at? Explain what you can tell from the context.',
                    ],
                ],
            ]);
    }

    public function message(Message $message, Thread $thread, AppContext $context, Delivery $delivery, Jobs $jobs): void
    {
        $event = $delivery->metadata['eventId'] ?? null;
        if (!is_string($event) || $event === '') {
            throw new \InvalidArgumentException('An agent turn needs Slack delivery identity.');
        }
        $jobs->dispatch(
            new AgentAnswer(html_entity_decode($message->text, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $context->toArray()),
            hash('sha256', json_encode([
                'agent-view',
                $thread->identity->key->key(),
                $event,
            ], JSON_THROW_ON_ERROR)),
        );
    }
}
