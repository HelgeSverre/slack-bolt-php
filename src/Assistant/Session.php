<?php

declare(strict_types=1);

namespace Thunder\Bolt\Assistant;

use Thunder\Bolt\Payloads\Events\AgentSessionStopped;
use Thunder\Bolt\WebApi\Client;
use Thunder\Bolt\WebApi\Response;

/** The modern Slack agent-session lifecycle for one thread. */
final readonly class Session
{
    public function __construct(
        private Client $client,
        private ThreadIdentity $identity,
    ) {}

    public function processing(?string $title = null): Response
    {
        return $this->set(AgentSessionStatus::Processing, $title);
    }

    public function active(): Response
    {
        return $this->set(AgentSessionStatus::Active);
    }

    public function suspended(): Response
    {
        return $this->set(AgentSessionStatus::Suspended);
    }

    public function closed(): Response
    {
        return $this->set(AgentSessionStatus::Closed);
    }

    public function rename(string $title): Response
    {
        self::title($title);

        return $this->client
            ->agents()
            ->sessions()
            ->rename([
                'channel_id' => $this->identity->channelId(),
                'thread_ts' => $this->identity->threadTs(),
                'title' => $title,
            ]);
    }

    /** Mark a matching stopped session ready after the caller has cancelled its work. */
    public function stopped(AgentSessionStopped $event): Response
    {
        if ($event->channelId !== $this->identity->channelId() || $event->threadTs !== $this->identity->threadTs()) {
            throw new \InvalidArgumentException('The stopped event does not belong to this agent session.');
        }

        return $this->active();
    }

    private function set(AgentSessionStatus $status, ?string $title = null): Response
    {
        if ($title !== null) {
            self::title($title);
        }

        return $this->client
            ->agents()
            ->sessions()
            ->setStatus([
                'channel_id' => $this->identity->channelId(),
                'thread_ts' => $this->identity->threadTs(),
                'status' => $status->value,
                ...($title !== null ? ['title' => $title] : []),
            ]);
    }

    private static function title(string $title): void
    {
        if (trim($title) === '' || mb_strlen($title) > 1000) {
            throw new \InvalidArgumentException('Agent session titles must contain 1 to 1000 characters.');
        }
    }
}
