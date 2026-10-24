<?php

declare(strict_types=1);

namespace App\Demo;

use App\Demo\Jobs\Answer;
use App\Demo\Jobs\StartAssistantSession;
use Thunder\Bolt\Assistant\Stores\StoredThreadContextStore;
use Thunder\Bolt\Assistant\Thread;
use Thunder\Bolt\Assistant\ThreadContext;
use Thunder\Bolt\Assistant\ThreadIdentity;
use Thunder\Bolt\Attributes\Event;
use Thunder\Bolt\Conversation\ConversationKey;
use Thunder\Bolt\Conversation\Stores\FileConversationStore;
use Thunder\Bolt\Invocation\Delivery;
use Thunder\Bolt\Jobs;
use Thunder\Bolt\Payloads\Events\AssistantThreadContextChanged;
use Thunder\Bolt\Payloads\Events\AssistantThreadStarted;
use Thunder\Bolt\Payloads\Events\Message;

/** The showcase handler for Slack's native Assistant surface, not a general agent runtime. */
final readonly class AssistantDemo
{
    public function __construct(
        private DemoConfig $config,
    ) {}

    public function started(AssistantThreadStarted $event, Thread $thread, Delivery $delivery, Jobs $jobs): void
    {
        $thread->saveContext();
        $this->enqueueStart($thread, $delivery, $event->ts ?? '', $jobs);
    }

    public function contextChanged(AssistantThreadContextChanged $event, Thread $thread): void
    {
        $thread->saveContext();
    }

    public function message(Message $message, Thread $thread, Delivery $delivery, Jobs $jobs): void
    {
        if ($thread->context() === null) {
            return;
        }
        $this->enqueue(
            html_entity_decode($message->text, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $thread,
            $delivery,
            $message->ts,
            $jobs,
        );
    }

    /**
     * Starts the playground from a direct message while Slack's Assistant surface
     * has not sent an assistant_thread_started event yet.
     */
    #[Event('message')]
    public function directMessage(Message $message, Delivery $delivery, Jobs $jobs): void
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
            html_entity_decode($message->text, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $thread,
            $delivery,
            $message->ts,
            $jobs,
        );
    }

    private function enqueue(string $text, Thread $thread, Delivery $delivery, string $identity, Jobs $jobs): void
    {
        $team = $delivery->authorizationSource?->teamId ?? '';
        $channel = $thread->identity->channelId();
        $root = $thread->identity->threadTs();
        $eventId = $identity;
        if (is_string($delivery->authorizationSource?->raw['event_id'] ?? null)) {
            $eventId = $delivery->authorizationSource->raw['event_id'];
        }
        if ($team === '' || $channel === '' || $root === '' || $eventId === '') {
            throw new \InvalidArgumentException(
                'Assistant demo requires trusted workspace, user, channel, thread, and delivery IDs.',
            );
        }
        $jobs->dispatch(
            new Answer($text, $root),
            hash('sha256', json_encode([
                $team,
                $channel,
                $root,
                $eventId,
            ], JSON_THROW_ON_ERROR)),
        );
    }

    private function enqueueStart(Thread $thread, Delivery $delivery, string $identity, Jobs $jobs): void
    {
        $team = $delivery->authorizationSource?->teamId ?? '';
        $channel = $thread->identity->channelId();
        $root = $thread->identity->threadTs();
        if ($team === '' || $channel === '' || $root === '' || $identity === '') {
            throw new \InvalidArgumentException(
                'Assistant onboarding requires trusted workspace, channel, thread, and delivery IDs.',
            );
        }
        $jobs->dispatch(
            new StartAssistantSession(),
            hash('sha256', json_encode([$team, $channel, $root, $identity], JSON_THROW_ON_ERROR)),
        );
    }

    private function contextStore(): StoredThreadContextStore
    {
        return new StoredThreadContextStore(new FileConversationStore($this->config->directory . '/assistant-context'));
    }
}
