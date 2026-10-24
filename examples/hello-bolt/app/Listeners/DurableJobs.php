<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Demo\DemoConfig;
use App\Demo\Jobs\PublishNote;
use App\Demo\ThreadState;
use Thunder\Bolt\Attributes\Event;
use Thunder\Bolt\Invocation\Delivery;
use Thunder\Bolt\Jobs;
use Thunder\Bolt\Payloads\Events\AgentSessionStopped;
use Thunder\Bolt\Payloads\Events\AgentSessionTitleChanged;
use Thunder\Bolt\Payloads\Events\AppMention;
use Thunder\Bolt\WebApi\Client;

final readonly class DurableJobs
{
    public function __construct(
        private DemoConfig $config,
    ) {}

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
    public function stop(AgentSessionStopped $event, Jobs $jobs, Delivery $delivery, Client $client): void
    {
        if ($event->threadTs === null) {
            return;
        }
        $channel = $event->channelId;
        if ($channel === null) {
            return;
        }
        // All ordinary turns carry a thread-bound JobContext.
        $jobs->cancelThread();
        // Slash-command roots acquire their root timestamp inside the worker, so the
        // original JobContext cannot name the new thread. The state record closes that gap.
        $team = $delivery->authorizationSource?->teamId;
        if ($team !== null) {
            $active =
                new ThreadState($this->config->directory)->get($team, $channel, $event->threadTs)['active_job'] ?? null;
            if (is_string($active) && $active !== '') {
                $jobs->cancel($active);
            }
        }
        // A Stop click only delivers intent; Slack requires the app to leave processing.
        $client
            ->agents()
            ->sessions()
            ->setStatus([
                'channel_id' => $channel,
                'thread_ts' => $event->threadTs,
                'status' => 'active',
            ]);
    }

    #[Event('agent_session_title_changed')]
    public function titleChanged(AgentSessionTitleChanged $event, Delivery $delivery): void
    {
        if ($event->threadTs === null || $event->channelId === null) {
            return;
        }
        $source = $delivery->authorizationSource;
        $tenant = $source?->teamId ?? $event->teamId;
        if ($tenant === null && $source?->enterpriseId !== null) {
            $tenant = 'enterprise:' . $source->enterpriseId;
        }
        if ($tenant === null) {
            return;
        }
        new ThreadState($this->config->directory)->update(
            $tenant,
            $event->channelId,
            $event->threadTs,
            static fn(array $state): array => [
                ...$state,
                'title' => $event->title,
                'user_title' => true,
            ],
        );
    }
}
