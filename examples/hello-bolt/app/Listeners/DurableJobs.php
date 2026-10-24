<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Demo\Jobs\PublishNote;
use Thunder\Bolt\Attributes\Event;
use Thunder\Bolt\Jobs;
use Thunder\Bolt\Payloads\Events\AgentSessionStopped;
use Thunder\Bolt\Payloads\Events\AppMention;

final readonly class DurableJobs
{
    #[Event('app_mention')]
    public function enqueue(AppMention $event, Jobs $jobs): void
    {
        $text = trim(preg_replace('/^<@[^>]+>\s*/', '', trim($event->text)) ?? '');
        if (!str_starts_with($text, 'durable ')) {
            return;
        }
        // Default: one attempt. Posting a new Slack message is not idempotent.
        $jobs->dispatch(new PublishNote(substr($text, strlen('durable '))));
    }

    #[Event('agent_session_stopped')]
    public function stop(AgentSessionStopped $event, Jobs $jobs): void
    {
        if ($event->threadTs === null) {
            return;
        }
        // Application policy: cancel this demo's queued work for the stopped thread.
        $jobs->cancelThread();
    }
}
