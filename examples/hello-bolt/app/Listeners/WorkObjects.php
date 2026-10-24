<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Demo\DemoConfig;
use App\Demo\DemoJob;
use Thunder\Bolt\Attributes\Action as ActionHandler;
use Thunder\Bolt\Attributes\Event as EventHandler;
use Thunder\Bolt\Attributes\View as ViewHandler;
use Thunder\Bolt\Invocation\Delivery;
use Thunder\Bolt\Payloads\Action;
use Thunder\Bolt\Payloads\Event;
use Thunder\Bolt\Payloads\Events\EntityCommentsRequested;
use Thunder\Bolt\Payloads\Events\EntityDetailsRequested;
use Thunder\Bolt\Payloads\View;
use Thunder\Bolt\Responses\ActionResponse;
use Thunder\Bolt\Responses\ViewResponse;
use Thunder\Bolt\Routing\Regex;
use Thunder\Bolt\WorkObjects\Actor;

final readonly class WorkObjects
{
    public function __construct(
        private DemoConfig $config,
    ) {}

    #[EventHandler('entity_details_requested')]
    public function details(EntityDetailsRequested $event, Delivery $delivery): void
    {
        $this->event($event, $delivery, 'details');
    }

    #[EventHandler('entity_comments_requested')]
    public function comments(EntityCommentsRequested $event, Delivery $delivery): void
    {
        $this->event($event, $delivery, 'comments');
    }

    private function event(Event $event, Delivery $delivery, string $operation): void
    {
        $payload = array_intersect_key($event->raw, array_flip([
            'type',
            'user',
            'external_ref',
            'entity_url',
            'trigger_id',
            'cursor',
            'limit',
            'thread_root_id',
            'event_ts',
        ]));
        $this->enqueue(
            $delivery,
            $operation,
            $payload,
            $delivery->authorizationSource?->raw['event_id'] ?? $payload['trigger_id'] ?? '',
        );
    }

    #[ViewHandler(new Regex('/^work-object-(post|edit)-comment$/'))]
    public function submit(View $view, Delivery $delivery): ViewResponse
    {
        if (($view->view['type'] ?? '') !== 'entity_comment') {
            return ViewResponse::empty();
        }
        $payload = [
            'type' => 'view_submission',
            'trigger_id' => $view->raw['trigger_id'] ?? '',
            'view' => array_intersect_key($view->view, array_flip([
                'type',
                'callback_id',
                'external_ref',
                'external_id',
                'thread_root_id',
                'state',
            ])),
        ];
        $this->enqueue(
            $delivery,
            $view->callbackId === 'work-object-edit-comment' ? 'edit_comment' : 'post_comment',
            $payload,
            $view->raw['trigger_id'] ?? '',
        );
        return ViewResponse::empty();
    }

    #[ActionHandler('demo_work_delete_comment')]
    public function delete(Action $action, Delivery $delivery): ActionResponse
    {
        if (($action->raw['container']['type'] ?? '') !== 'entity_comment') {
            return ActionResponse::empty();
        }
        $payload = [
            'type' => 'block_actions',
            'trigger_id' => $action->triggerId,
            'container' => $action->raw['container'],
            'actions' => [$action->action],
        ];
        $this->enqueue($delivery, 'delete_comment', $payload, $action->triggerId ?? '');
        return ActionResponse::empty();
    }

    #[EventHandler('link_shared')]
    public function link(Event $event, Delivery $delivery): void
    {
        $links = $event->raw['links'] ?? [];
        if (
            !is_array($links)
            || !array_any(
                $links,
                fn($link): bool => (
                    is_array($link)
                    && is_string($link['url'] ?? null)
                    && str_starts_with($link['url'], rtrim($this->config->workObjectBaseUrl, '/') . '/')
                ),
            )
        ) {
            return;
        }
        $payload = ['links' => $event->raw['links'] ?? []];
        $this->enqueue(
            $delivery,
            'unfurl',
            $payload,
            $delivery->authorizationSource?->raw['event_id'] ?? '',
            $event->channelId ?? '',
            is_string($event->raw['message_ts'] ?? null) ? $event->raw['message_ts'] : null,
        );
    }

    private function enqueue(
        Delivery $delivery,
        string $operation,
        array $payload,
        mixed $identity,
        string $channel = '',
        ?string $message = null,
    ): void {
        if (!is_string($identity) || $identity === '') {
            throw new \InvalidArgumentException('A work request needs a delivery identity.');
        }
        $actor = Actor::fromDelivery($delivery);
        $job = new DemoJob(
            'work',
            '',
            $actor->teamId,
            $actor->userId,
            $channel,
            operation: $operation,
            message: $message,
            payload: $payload,
        );
        $this->config->queue()->enqueue($job->toArray(), hash('sha256', json_encode([
            $actor->teamId,
            $actor->userId,
            $operation,
            $identity,
        ], JSON_THROW_ON_ERROR)));
    }
}
