<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Demo\DemoConfig;
use App\Demo\DemoJob;
use Thunder\Bolt\Attributes\Action as ActionHandler;
use Thunder\Bolt\Attributes\Event as EventHandler;
use Thunder\Bolt\Invocation\Delivery;
use Thunder\Bolt\Payloads\Action;
use Thunder\Bolt\Payloads\Event;
use Thunder\Bolt\Responses\ActionResponse;
use Thunder\Bolt\Routing\Regex;

final readonly class BoltlingHome
{
    public function __construct(
        private DemoConfig $config,
    ) {}

    #[EventHandler('app_home_opened')]
    public function opened(Event $event, Delivery $delivery): void
    {
        if (($event->raw['tab'] ?? null) !== 'home') {
            return;
        }
        $this->enqueue($event->userId ?? '', 'refresh', $delivery, $event->raw['event_ts'] ?? '');
    }

    #[ActionHandler(new Regex('/^demo_pet_home_(feed|play|clean|rest|refresh)$/'))]
    public function care(Action $action, Delivery $delivery): ActionResponse
    {
        $view = $action->raw['view'] ?? [];
        if (
            !is_array($view)
            || ($view['type'] ?? null) !== 'home'
            || ($view['callback_id'] ?? null) !== 'boltling_home'
        ) {
            return ActionResponse::empty();
        }
        $this->enqueue(
            $action->userId ?? '',
            substr($action->actionId ?? '', strlen('demo_pet_home_')),
            $delivery,
            $action->action['action_ts'] ?? '',
        );
        return ActionResponse::empty();
    }

    private function enqueue(string $user, string $action, Delivery $delivery, mixed $identity): void
    {
        $team = $delivery->authorizationSource?->teamId ?? '';
        $eventId = $delivery->authorizationSource?->raw['event_id'] ?? $identity;
        if ($team === '' || $user === '' || !is_string($eventId) || $eventId === '') {
            throw new \InvalidArgumentException('App Home requires a workspace, user, and delivery identity.');
        }
        $job = new DemoJob('pet_home', $action, $team, $user, '', operation: $action === 'refresh' ? 'open' : 'care');
        $this->config->queue()->enqueue($job->toArray(), hash('sha256', json_encode([
            $team,
            $user,
            'pet_home',
            $action,
            $eventId,
        ], JSON_THROW_ON_ERROR)));
    }
}
