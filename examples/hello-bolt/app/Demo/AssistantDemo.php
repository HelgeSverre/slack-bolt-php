<?php

declare(strict_types=1);

namespace App\Demo;

use Thunder\Bolt\Assistant\Stores\StoredThreadContextStore;
use Thunder\Bolt\Assistant\SuggestedPrompt;
use Thunder\Bolt\Assistant\Thread;
use Thunder\Bolt\Assistant\ThreadContext;
use Thunder\Bolt\Assistant\ThreadIdentity;
use Thunder\Bolt\Attributes\Event;
use Thunder\Bolt\Conversation\ConversationKey;
use Thunder\Bolt\Conversation\Stores\FileConversationStore;
use Thunder\Bolt\Invocation\Delivery;
use Thunder\Bolt\Payloads\Events\AssistantThreadContextChanged;
use Thunder\Bolt\Payloads\Events\AssistantThreadStarted;
use Thunder\Bolt\Payloads\Events\Message;
use Thunder\Bolt\WebApi\Client;

/** The showcase handler for Slack's native Assistant surface, not a general agent runtime. */
final readonly class AssistantDemo
{
    public function __construct(
        private DemoConfig $config,
    ) {}

    public function started(AssistantThreadStarted $event, Thread $thread, Delivery $delivery): void
    {
        $thread->saveContext();
        $this->enqueue('started', '', $thread->identity->userId, $thread, $delivery, $event->ts ?? '');
    }

    public function contextChanged(AssistantThreadContextChanged $event, Thread $thread): void
    {
        $thread->saveContext();
    }

    public function message(Message $message, Thread $thread, Delivery $delivery): void
    {
        if ($thread->context() === null) {
            return;
        }
        $this->enqueue(
            'message',
            html_entity_decode($message->text, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $thread->identity->userId,
            $thread,
            $delivery,
            $message->ts,
        );
    }

    /**
     * Starts the playground from a direct message while Slack's Assistant surface
     * has not sent an assistant_thread_started event yet.
     */
    #[Event('message')]
    public function directMessage(Message $message, Delivery $delivery): void
    {
        if (
            ($message->raw['channel_type'] ?? null) !== 'im'
            || $message->threadTs !== null
            || isset($message->raw['bot_id'])
            || !in_array($message->raw['subtype'] ?? null, [null, 'file_share'], true)
            || $message->channelId === ''
            || $message->userId === ''
            || $message->ts === ''
            || $delivery->client === null
        ) {
            return;
        }

        $team = $delivery->authorizationSource?->teamId ?? '';
        if ($team === '') {
            return;
        }

        $thread = new Thread(
            $delivery->client,
            new ThreadIdentity(
                new ConversationKey($message->channelId, teamId: $team, threadTs: $message->ts),
                $message->userId,
            ),
            $this->contextStore(),
            new ThreadContext(),
        );
        $thread->saveContext();

        $this->enqueue(
            'message',
            html_entity_decode($message->text, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $thread->identity->userId,
            $thread,
            $delivery,
            $message->ts,
            ['root' => true],
        );
    }

    public function start(DemoJob $job, Client $client): void
    {
        $thread = $this->threadFor($job, $client);
        $thread->setStatus('Preparing this thread.', ['Saving context']);
        try {
            $thread->setTitle('Thunder Bolt');
            $thread->setSuggestedPrompts($this->suggestedPrompts(), 'Try asking');
            $thread->say('Ready. Ask a question, search the web, or ask about Boltling.');
        } finally {
            $thread->clearStatus();
        }
    }

    public function beginAnswer(DemoJob $job, Client $client): Thread
    {
        $thread = $this->threadFor($job, $client);
        $thread->setStatus('Thinking.', ['Reading your message']);
        if (($job->payload['root'] ?? false) === true) {
            $thread->setTitle($this->titleFor($job->text));
            $thread->setSuggestedPrompts($this->suggestedPrompts(), 'Try asking');
        }

        return $thread;
    }

    private function enqueue(
        string $operation,
        string $text,
        string $user,
        Thread $thread,
        Delivery $delivery,
        string $identity,
        array $payload = [],
    ): void {
        $team = $delivery->authorizationSource?->teamId ?? '';
        $channel = $thread->identity->channelId();
        $root = $thread->identity->threadTs();
        $eventId = $identity;
        if (is_string($delivery->authorizationSource?->raw['event_id'] ?? null)) {
            $eventId = $delivery->authorizationSource->raw['event_id'];
        }
        if ($team === '' || $channel === '' || $root === '' || $user === '' || $eventId === '') {
            throw new \InvalidArgumentException(
                'Assistant demo requires trusted workspace, user, channel, thread, and delivery IDs.',
            );
        }
        $job = new DemoJob('assistant', $text, $team, $user, $channel, $root, $operation, payload: $payload);
        $this->config->queue()->enqueue($job->toArray(), hash('sha256', json_encode([
            $team,
            $channel,
            $root,
            $operation,
            $eventId,
        ], JSON_THROW_ON_ERROR)));
    }

    public function threadFor(DemoJob $job, Client $client): Thread
    {
        return new Thread(
            $client,
            new ThreadIdentity(
                new ConversationKey($job->channel, teamId: $job->team, threadTs: $job->thread),
                $job->user,
            ),
            $this->contextStore(),
        );
    }

    private function contextStore(): StoredThreadContextStore
    {
        return new StoredThreadContextStore(new FileConversationStore($this->config->directory . '/assistant-context'));
    }

    /** @return list<SuggestedPrompt> */
    private function suggestedPrompts(): array
    {
        return [
            new SuggestedPrompt('What can you do?', 'What can you help me with in Slack?'),
            new SuggestedPrompt('Check Boltling', 'How is Boltling doing?'),
            new SuggestedPrompt('Search the web', 'Search the web for a useful answer.'),
        ];
    }

    private function titleFor(string $question): string
    {
        $question = trim(preg_replace('/\s+/', ' ', $question) ?? '');

        return $question === '' ? 'Thunder Bolt' : mb_strimwidth($question, 0, 80, '…');
    }
}
